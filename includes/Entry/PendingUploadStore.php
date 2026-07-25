<?php
/**
 * Durable ledger and fencing for guest uploads held outside the web root.
 */

namespace EIT\Entry;

use EIT\Blueprint\Uuid;
use EIT\Infrastructure\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PendingUploadStore {

	const LIFETIME = 86400;
	const RECEIVE_LEASE = 900;
	const SWEEP_HOOK = 'eit_sweep_entry_pending_uploads';

	private $files;
	private $policy;
	private $contract_resolver;
	private $submission_reconciler;

	public function __construct( $directory = '', ?callable $mover = null, ?EntryMediaPolicy $policy = null, ?callable $contract_resolver = null, ?PendingUploadSubmissionReconciler $submission_reconciler = null ) {
		$this->files = new PendingUploadFiles( $directory, $mover );
		$this->policy = $policy ?: new EntryMediaPolicy();
		$this->contract_resolver = $contract_resolver ?: static fn( $surface_id ) => ( new EntrySurfaceResolver() )->get( $surface_id );
		$this->submission_reconciler = $submission_reconciler ?: new PendingUploadSubmissionReconciler();
	}

	public function quarantine( array $identity, array $file ) {
		global $wpdb;

		$valid = $this->valid_identity( $identity, false );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$scheduled = $this->ensure_sweeper();
		if ( is_wp_error( $scheduled ) ) {
			return $scheduled;
		}
		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			return $this->error( 'eit_entry_pending_token_failed', 'A secure pending upload token could not be created.' );
		}
		$id = Uuid::v4();
		$extension = preg_replace( '/[^a-zA-Z0-9]/', '', (string) ( $file['extension'] ?? '' ) );
		$storage_name = str_replace( '-', '', $id ) . ( $extension ? '.' . strtolower( $extension ) : '' );
		$original_name = sanitize_file_name( $file['original_name'] ?? 'upload' );
		$recorded_mime = sanitize_mime_type( $file['mime_type'] ?? '' );
		$directory = $this->files->protected_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			Tables::name( Tables::PENDING_UPLOADS ),
			[
				'id' => $id,
				'blueprint_id' => (string) $identity['blueprint_id'],
				'version_id' => absint( $identity['version_id'] ),
				'contract_checksum' => (string) $identity['contract_checksum'],
				'surface_id' => (string) $identity['surface_id'],
				'field_id' => (string) $identity['field_id'],
				'actor_key' => (string) $identity['actor_key'],
				'token_hash' => hash( 'sha256', $token ),
				'storage_name' => $storage_name,
				'original_name' => $original_name,
				'mime_type' => $recorded_mime,
				'size_bytes' => absint( $file['size_bytes'] ?? 0 ),
				'file_checksum' => str_repeat( '0', 64 ),
				'status' => 'receiving',
				'submission_id' => null,
				'attachment_id' => null,
				'promoted_path' => null,
				'cleanup_attempts' => 0,
				'last_error' => null,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::RECEIVE_LEASE ),
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		if ( false === $inserted ) {
			return $this->error( 'eit_entry_pending_record_failed', 'The protected pending upload could not be recorded.' );
		}
		$stored = $this->files->quarantine( $file['tmp_name'] ?? '', $storage_name );
		if ( is_wp_error( $stored ) ) {
			$this->discard_unissued( $id, $identity['surface_id'] );
			return $stored;
		}
		$checked = wp_check_filetype_and_ext( $stored['path'], $original_name );
		$actual_size = is_file( $stored['path'] ) ? filesize( $stored['path'] ) : false;
		if ( false === $actual_size || absint( $actual_size ) !== absint( $file['size_bytes'] ?? 0 ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $stored['checksum'] ) || ! hash_equals( $recorded_mime, sanitize_mime_type( $checked['type'] ?? '' ) ) || strtolower( $extension ) !== strtolower( sanitize_key( $checked['ext'] ?? '' ) ) ) {
			$this->discard_unissued( $id, $identity['surface_id'] );
			return $this->error( 'eit_entry_pending_file_mismatch', 'The protected upload no longer matches its validated file identity.' );
		}
		$recorded = $wpdb->update(
			Tables::name( Tables::PENDING_UPLOADS ),
			[ 'file_checksum' => $stored['checksum'], 'status' => 'pending', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::LIFETIME ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id, 'status' => 'receiving' ]
		);
		if ( 1 !== $recorded ) {
			$this->discard_unissued( $id, $identity['surface_id'] );
			return $this->error( 'eit_entry_pending_record_failed', 'The protected pending upload could not finish its durable record.' );
		}
		return [
			'pending_token' => $token,
			'name' => sanitize_file_name( $file['original_name'] ?? 'upload' ),
			'mime' => sanitize_mime_type( $file['mime_type'] ?? '' ),
		];
	}

	public function authorize( $token, array $identity ) {
		$valid = $this->valid_identity( $identity, ! empty( $identity['submission_id'] ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$record = $this->find( $token );
		if ( ! $record || ! in_array( $record['status'], [ 'pending', 'claimed', 'promoting', 'promoted', 'consumed' ], true ) || strtotime( $record['expires_at'] . ' UTC' ) <= time() ) {
			return $this->error( 'eit_entry_pending_upload_invalid', 'The pending upload is unavailable or expired.' );
		}
		foreach ( [ 'blueprint_id', 'contract_checksum', 'surface_id', 'field_id', 'actor_key' ] as $key ) {
			if ( ! hash_equals( (string) $record[ $key ], (string) ( $identity[ $key ] ?? '' ) ) ) {
				return $this->error( 'eit_entry_pending_upload_forbidden', 'The pending upload belongs to another Entry contract.' );
			}
		}
		if ( absint( $record['version_id'] ) !== absint( $identity['version_id'] ?? 0 ) ) {
			return $this->error( 'eit_entry_pending_upload_forbidden', 'The pending upload belongs to another Entry version.' );
		}
		if ( ! empty( $identity['submission_id'] ) && ! empty( $record['submission_id'] ) && ! hash_equals( (string) $record['submission_id'], (string) $identity['submission_id'] ) ) {
			return $this->error( 'eit_entry_pending_upload_claimed', 'The pending upload is already claimed by another submission.' );
		}
		return $record;
	}

	public function claim( $token, array $identity ) {
		global $wpdb;

		$record = $this->authorize( $token, $identity );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		try {
			$current = call_user_func( $this->contract_resolver, $record['surface_id'] );
		} catch ( \Throwable $error ) {
			$current = null;
		}
		if ( ! is_array( $current ) || absint( $current['version_id'] ?? 0 ) !== absint( $record['version_id'] ) || ! hash_equals( (string) ( $current['artifact_checksum'] ?? '' ), (string) $record['contract_checksum'] ) ) {
			return $this->error( 'eit_entry_pending_contract_stale', 'The pending upload contract is no longer active.' );
		}
		$active_field = array_column( $current['fields'] ?? [], null, 'id' )[ $record['field_id'] ] ?? null;
		if ( ! $active_field ) {
			return $this->error( 'eit_entry_pending_contract_stale', 'The pending upload field is no longer active.' );
		}
		$allowed = $this->policy->validate_pending( $current, $active_field, $record );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! empty( $record['submission_id'] ) ) {
			return hash_equals( (string) $record['submission_id'], (string) $identity['submission_id'] ) ? $record : $this->error( 'eit_entry_pending_upload_claimed', 'The pending upload is already claimed by another submission.' );
		}
		$updated = $wpdb->update(
			Tables::name( Tables::PENDING_UPLOADS ),
			[ 'status' => 'claimed', 'submission_id' => $identity['submission_id'], 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::LIFETIME ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $record['id'], 'status' => 'pending', 'submission_id' => null ]
		);
		if ( 1 === $updated ) {
			return $this->find( $token );
		}
		$current = $this->find( $token );
		return $current && hash_equals( (string) ( $current['submission_id'] ?? '' ), (string) $identity['submission_id'] )
			? $current
			: $this->error( 'eit_entry_pending_upload_claimed', 'The pending upload is already claimed by another submission.' );
	}

	public function promote( $token, array $identity ) {
		global $wpdb;

		$record = $this->authorize( $token, $identity );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		if ( empty( $identity['submission_id'] ) || ! hash_equals( (string) ( $record['submission_id'] ?? '' ), (string) $identity['submission_id'] ) ) {
			return $this->error( 'eit_entry_pending_not_claimed', 'The pending upload has no matching submission claim.' );
		}
		$renewed_expiry = gmdate( 'Y-m-d H:i:s', time() + self::LIFETIME );
		$renewed = $wpdb->update(
			Tables::name( Tables::PENDING_UPLOADS ),
			[ 'expires_at' => $renewed_expiry, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $record['id'], 'status' => $record['status'], 'submission_id' => $record['submission_id'] ]
		);
		if ( 1 !== $renewed ) {
			$current = $this->find( $token );
			if ( ! $current || ! hash_equals( (string) $record['status'], (string) $current['status'] ) || ! hash_equals( (string) $record['submission_id'], (string) $current['submission_id'] ) || strtotime( $current['expires_at'] . ' UTC' ) < strtotime( $renewed_expiry . ' UTC' ) ) {
				return $this->error( 'eit_entry_pending_promote_claim_lost', 'The pending upload could not renew its promotion lease.' );
			}
			$record = $current;
		}
		$record['expires_at'] = $renewed_expiry;
		return ( new PendingUploadPromoter( $this->files ) )->promote( $record );
	}

	public function consume( array $attachment_ids, $submission_id ) {
		global $wpdb;

		if ( ! Uuid::is_valid( $submission_id ) ) {
			return $this->error( 'eit_entry_pending_consume_invalid', 'Pending media consumption requires a valid submission claim.' );
		}
		$attachment_ids = array_values( array_filter( array_unique( array_map( 'absint', $attachment_ids ) ) ) );
		foreach ( $attachment_ids as $attachment_id ) {
			$record = $this->find_by_attachment( $attachment_id );
			if ( ! $record ) {
				if ( 'attachment' !== get_post_type( $attachment_id ) || get_post_meta( $attachment_id, '_eit_entry_pending_upload_id', true ) ) {
					return $this->error( 'eit_entry_pending_consume_unknown', 'Pending media ownership could not be reconciled.' );
				}
				continue;
			}
			if ( ! hash_equals( (string) ( $record['submission_id'] ?? '' ), (string) $submission_id ) ) {
				return $this->error( 'eit_entry_pending_consume_forbidden', 'Pending media belongs to another submission.' );
			}
			if ( 'promoted' === $record['status'] ) {
				$updated = $wpdb->update(
					Tables::name( Tables::PENDING_UPLOADS ),
					[ 'status' => 'consumed', 'last_error' => null, 'updated_at' => current_time( 'mysql', true ) ],
					[ 'id' => $record['id'], 'status' => 'promoted', 'submission_id' => $submission_id ]
				);
				if ( 1 !== $updated ) {
					return $this->error( 'eit_entry_pending_consume_race', 'Pending media consumption lost its state claim.' );
				}
			} elseif ( 'consumed' !== $record['status'] ) {
				return $this->error( 'eit_entry_pending_consume_race', 'Pending media is not ready for consumption.' );
			}
			$this->files->release_attachment( $attachment_id );
		}
		return true;
	}

	public function cleanup( $id, $surface_id, $force = false ) {
		global $wpdb;

		$record = $this->find_by_id( $id );
		if ( ! $record || ! hash_equals( (string) $record['surface_id'], (string) $surface_id ) ) {
			return false;
		}
		$recovering_cleanup = in_array( $record['status'], [ 'cleaning', 'retiring' ], true );
		if ( ! $force && ! $recovering_cleanup && strtotime( $record['expires_at'] . ' UTC' ) > time() ) {
			return false;
		}
		$record = $this->submission_reconciler->reconcile( $record, [ $this, 'consume' ] );
		if ( is_wp_error( $record ) ) {
			$wpdb->update( Tables::name( Tables::PENDING_UPLOADS ), [ 'last_error' => sanitize_key( $record->get_error_code() ), 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
			return false;
		}
		$table = Tables::name( Tables::PENDING_UPLOADS );
		if ( in_array( $record['status'], [ 'consumed', 'discarded', 'retiring' ], true ) ) {
			if ( in_array( $record['status'], [ 'consumed', 'discarded' ], true ) ) {
				if ( 'consumed' === $record['status'] ) {
					$attachment_id = absint( $record['attachment_id'] ?? 0 );
					if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
						$wpdb->update( $table, [ 'last_error' => 'eit_entry_pending_consumed_attachment_missing', 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $record['id'], 'status' => 'consumed' ] );
						return false;
					}
					$this->files->release_attachment( $attachment_id );
				}
				$claimed = $wpdb->update( $table, [ 'status' => 'retiring', 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $record['id'], 'status' => $record['status'] ] );
				if ( 1 !== $claimed ) {
					return false;
				}
			}
			return false !== $wpdb->delete( $table, [ 'id' => $record['id'], 'status' => 'retiring' ] );
		}
		if ( 'cleaning' !== $record['status'] ) {
			$claimed = $wpdb->update( $table, [ 'status' => 'cleaning', 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $record['id'], 'status' => $record['status'], 'updated_at' => $record['updated_at'] ] );
			if ( 1 !== $claimed ) {
				$current = $this->find_by_id( $id );
				return $current && 'consumed' === $current['status'] ? $this->cleanup( $id, $surface_id, $force ) : false;
			}
		}
		$deleted = $this->files->delete_unconsumed( $record );
		if ( is_wp_error( $deleted ) ) {
			$this->cleanup_failed( $record['id'], $deleted );
			return false;
		}
		$retained = $wpdb->update(
			$table,
			[ 'status' => 'discarded', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'last_error' => null, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $record['id'], 'status' => 'cleaning' ]
		);
		if ( 1 !== $retained ) {
			$this->cleanup_failed( $record['id'], $this->error( 'eit_entry_pending_ledger_delete_failed', 'Expired pending media ledger cleanup failed.' ) );
			return false;
		}
		return true;
	}

	public function sweep( $limit = 100 ) {
		global $wpdb;

		$table = Tables::name( Tables::PENDING_UPLOADS );
		$limit = min( 500, max( 1, absint( $limit ) ) );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 300 );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,surface_id FROM `{$table}` WHERE (expires_at <= %s AND status IN ('receiving','pending','claimed','promoting','promoted','consumed','discarded','cleanup_failed')) OR (updated_at <= %s AND status IN ('cleaning','retiring')) ORDER BY expires_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$stale,
				$limit
			),
			ARRAY_A
		);
		$cleaned = 0;
		foreach ( $rows ?: [] as $row ) {
			$cleaned += $this->cleanup( $row['id'], $row['surface_id'] ) ? 1 : 0;
		}
		return [ 'examined' => count( $rows ?: [] ), 'cleaned' => $cleaned ];
	}

	public function ensure_sweeper() {
		if ( wp_next_scheduled( self::SWEEP_HOOK ) ) {
			return true;
		}
		$result = wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::SWEEP_HOOK, [], true );
		if ( ! is_wp_error( $result ) && $result ) {
			return true;
		}
		return wp_next_scheduled( self::SWEEP_HOOK )
			? true
			: $this->error( 'eit_entry_pending_sweeper_failed', 'Pending upload cleanup could not be scheduled safely.' );
	}

	private function valid_identity( array $identity, $submission_required ) {
		$valid = Uuid::is_valid( $identity['blueprint_id'] ?? '' )
			&& Uuid::is_valid( $identity['surface_id'] ?? '' )
			&& Uuid::is_valid( $identity['field_id'] ?? '' )
			&& absint( $identity['version_id'] ?? 0 ) > 0
			&& preg_match( '/^[a-f0-9]{64}$/', (string) ( $identity['contract_checksum'] ?? '' ) )
			&& preg_match( '/^[a-f0-9]{64}$/', (string) ( $identity['actor_key'] ?? '' ) );
		if ( $submission_required ) {
			$valid = $valid && Uuid::is_valid( $identity['submission_id'] ?? '' );
		}
		return $valid ? true : $this->error( 'eit_entry_pending_identity_invalid', 'The pending upload identity is invalid.' );
	}

	private function find( $token ) {
		$token = strtolower( trim( (string) $token ) );
		return preg_match( '/^[a-f0-9]{64}$/', $token ) ? $this->find_where( 'token_hash', hash( 'sha256', $token ) ) : null;
	}

	private function find_by_id( $id ) {
		return Uuid::is_valid( $id ) ? $this->find_where( 'id', $id ) : null;
	}

	private function find_by_attachment( $attachment_id ) {
		return $attachment_id ? $this->find_where( 'attachment_id', absint( $attachment_id ), '%d' ) : null;
	}

	private function find_where( $column, $value, $format = '%s' ) {
		global $wpdb;

		$allowed = [ 'id', 'token_hash', 'attachment_id' ];
		if ( ! in_array( $column, $allowed, true ) ) {
			return null;
		}
		$table = Tables::name( Tables::PENDING_UPLOADS );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$column}` = {$format} LIMIT 1", $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function cleanup_failed( $id, \WP_Error $error ) {
		global $wpdb;

		$table = Tables::name( Tables::PENDING_UPLOADS );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 'cleanup_failed', cleanup_attempts = cleanup_attempts + 1, last_error = %s, expires_at = %s, updated_at = %s WHERE id = %s AND status = 'cleaning'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_text_field( $error->get_error_code() ),
				gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	private function discard_unissued( $id, $surface_id ) {
		$this->cleanup( $id, $surface_id, true );
		$this->cleanup( $id, $surface_id, true );
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, __( $message, 'elementor-implementation-toolkit' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
