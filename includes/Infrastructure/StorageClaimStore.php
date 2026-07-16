<?php
/**
 * Durable ownership claims around non-transactional CPT/CCT preparation.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;
use EIT\CCT\SchemaManager as CctSchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StorageClaimStore {

	const STATUSES = [ 'claimed', 'prepared', 'failed' ];

	private $transaction;

	public function __construct( Transaction $transaction = null ) {
		$this->transaction = $transaction ?: new Transaction();
	}

	public function claim_many( $blueprint_id, $change_set_id, array $artifacts ) {
		$scope = $this->validate_identifiers( $blueprint_id, $change_set_id );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$claims = $this->claims_from_artifacts( $artifacts );
		if ( is_wp_error( $claims ) || ! $claims ) {
			return $claims;
		}

		return $this->transaction->run(
			function () use ( $blueprint_id, $change_set_id, $claims ) {
				$scope = $this->validate_scope( $blueprint_id, $change_set_id, true );
				if ( is_wp_error( $scope ) ) {
					return $scope;
				}
				$records = [];
				foreach ( $claims as $claim ) {
					$record = $this->claim_one( $blueprint_id, $change_set_id, $claim );
					if ( is_wp_error( $record ) ) {
						return $record;
					}
					$records[] = $record;
				}
				return $records;
			}
		);
	}

	public function mark_prepared( $blueprint_id, $change_set_id, array $identity_hashes = [] ) {
		return $this->transition_many( $blueprint_id, $change_set_id, 'prepared', '', $identity_hashes );
	}

	public function mark_failed( $blueprint_id, $change_set_id, $failure_code, array $identity_hashes = [] ) {
		$failure_code = substr( sanitize_key( $failure_code ), 0, 96 );
		return $this->transition_many(
			$blueprint_id,
			$change_set_id,
			'failed',
			$failure_code ?: 'eit_storage_prepare_failed',
			$identity_hashes
		);
	}

	public function release( $blueprint_id, $change_set_id, array $identity_hashes = [] ) {
		$scope = $this->validate_identifiers( $blueprint_id, $change_set_id );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return $this->transaction->run(
			function () use ( $blueprint_id, $change_set_id, $identity_hashes ) {
				global $wpdb;

				$records = $this->locked_scope( $blueprint_id, $change_set_id, $identity_hashes );
				if ( is_wp_error( $records ) ) {
					return $records;
				}
				foreach ( $records as $record ) {
					if ( 'claimed' !== $record['status'] ) {
						return $this->error(
							'eit_storage_claim_release_blocked',
							__( 'Prepared or failed storage claims require reconciliation before release.', 'elementor-implementation-toolkit' ),
							$record
						);
					}
					if ( ! $record['existed_before'] && $this->identity_exists( $record['strategy'], $record['storage_slug'] ) ) {
						return $this->error( 'eit_storage_claim_release_blocked', __( 'Storage now exists and its claim requires reconciliation before release.', 'elementor-implementation-toolkit' ), $record );
					}
				}
				foreach ( $records as $record ) {
					$deleted = $wpdb->delete(
						Tables::name( Tables::STORAGE_CLAIMS ),
						[
							'identity_hash' => $record['identity_hash'],
							'blueprint_id' => (string) $blueprint_id,
							'change_set_id' => (string) $change_set_id,
							'status' => 'claimed',
						]
					);
					if ( 1 !== $deleted ) {
						return $this->state_conflict();
					}
				}
				return true;
			}
		);
	}

	public function owner( $strategy, $slug ) {
		global $wpdb;

		$identity = $this->normalize_identity( $strategy, $slug );
		if ( is_wp_error( $identity ) ) {
			return null;
		}
		$table = Tables::name( Tables::STORAGE_CLAIMS );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE identity_hash = %s", $identity['identity_hash'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	public function for_change_set( $blueprint_id, $change_set_id ) {
		global $wpdb;

		$table = Tables::name( Tables::STORAGE_CLAIMS );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE blueprint_id = %s AND change_set_id = %s ORDER BY strategy,storage_slug", $blueprint_id, $change_set_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	public static function identity_hash( $strategy, $slug ) {
		$strategy = sanitize_key( $strategy );
		$slug = sanitize_key( $slug );
		$max_length = 'cpt' === $strategy ? 20 : 32;
		return in_array( $strategy, [ 'cpt', 'cct' ], true ) && '' !== $slug && strlen( $slug ) <= $max_length
			? hash( 'sha256', 'entity_definition|' . $strategy . '|' . $slug )
			: '';
	}

	private function claims_from_artifacts( array $artifacts ) {
		$claims = [];
		foreach ( $artifacts as $artifact ) {
			if ( 'entity_definition' !== ( $artifact['kind'] ?? '' ) ) {
				continue;
			}
			$payload = is_array( $artifact['payload'] ?? null ) ? $artifact['payload'] : [];
			$strategy = sanitize_key( $payload['strategy'] ?? '' );
			if ( ! in_array( $strategy, [ 'cpt', 'cct' ], true ) ) {
				continue;
			}
			$identity = $this->normalize_identity( $strategy, $payload['definition']['slug'] ?? '' );
			$checksum = strtolower( (string) ( $artifact['checksum'] ?? '' ) );
			if ( is_wp_error( $identity ) || ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
				return $this->error( 'eit_storage_claim_artifact_invalid', __( 'Compiled storage cannot be claimed because its identity is invalid.', 'elementor-implementation-toolkit' ) );
			}
			$identity['artifact_checksum'] = $checksum;
			$identity['existed_before'] = $this->identity_exists( $strategy, $identity['storage_slug'] );
			$hash = $identity['identity_hash'];
			if ( isset( $claims[ $hash ] ) && ! hash_equals( $claims[ $hash ]['artifact_checksum'], $checksum ) ) {
				return $this->error( 'eit_storage_claim_artifact_collision', __( 'Two compiled Entities claim the same storage with different contracts.', 'elementor-implementation-toolkit' ) );
			}
			$claims[ $hash ] = $identity;
		}
		ksort( $claims );
		return array_values( $claims );
	}

	private function claim_one( $blueprint_id, $change_set_id, array $claim ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$previous_suppression = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			Tables::name( Tables::STORAGE_CLAIMS ),
			[
				'identity_hash' => $claim['identity_hash'],
				'strategy' => $claim['strategy'],
				'storage_slug' => $claim['storage_slug'],
				'blueprint_id' => (string) $blueprint_id,
				'change_set_id' => (string) $change_set_id,
				'artifact_checksum' => $claim['artifact_checksum'],
				'existed_before' => $claim['existed_before'] ? 1 : 0,
				'status' => 'claimed',
				'failure_code' => null,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		$database_error = sanitize_text_field( $wpdb->last_error );
		$wpdb->suppress_errors( $previous_suppression );
		if ( false !== $inserted ) {
			return $this->owner( $claim['strategy'], $claim['storage_slug'] );
		}

		$existing = $this->locked_owner( $claim );
		if ( ! $existing ) {
			return $this->error( 'eit_storage_claim_write_failed', __( 'The storage identity claim could not be recorded.', 'elementor-implementation-toolkit' ), [ 'database_error' => $database_error ] );
		}
		if ( ! in_array( $existing['status'], self::STATUSES, true ) ) {
			return $this->error( 'eit_storage_claim_identity_corrupt', __( 'The storage identity ledger is inconsistent.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! hash_equals( $existing['identity_hash'], $claim['identity_hash'] ) ) {
			return $this->error( 'eit_storage_claim_identity_corrupt', __( 'The storage identity ledger is inconsistent.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! hash_equals( $existing['blueprint_id'], (string) $blueprint_id ) ) {
			return $this->ownership_conflict( $existing );
		}
		if ( hash_equals( $existing['change_set_id'], (string) $change_set_id ) ) {
			return hash_equals( $existing['artifact_checksum'], $claim['artifact_checksum'] )
				? $existing
				: $this->error( 'eit_storage_claim_artifact_mismatch', __( 'This change set already claimed the storage with a different compiled contract.', 'elementor-implementation-toolkit' ) );
		}
		if ( 'claimed' === $existing['status'] && $this->change_set_is_applying( $existing['change_set_id'] ) ) {
			return $this->error( 'eit_storage_claim_busy', __( 'Another change set from this Blueprint is still preparing the storage.', 'elementor-implementation-toolkit' ), [ 'change_set_id' => $existing['change_set_id'] ] );
		}

		$updated = $wpdb->update(
			Tables::name( Tables::STORAGE_CLAIMS ),
			[
				'change_set_id' => (string) $change_set_id,
				'artifact_checksum' => $claim['artifact_checksum'],
				'status' => 'claimed',
				'failure_code' => null,
				'updated_at' => $now,
			],
			[
				'identity_hash' => $existing['identity_hash'],
				'blueprint_id' => $existing['blueprint_id'],
				'change_set_id' => $existing['change_set_id'],
				'artifact_checksum' => $existing['artifact_checksum'],
				'status' => $existing['status'],
			]
		);
		return 1 === $updated ? $this->owner( $claim['strategy'], $claim['storage_slug'] ) : $this->state_conflict();
	}

	private function transition_many( $blueprint_id, $change_set_id, $status, $failure_code, array $identity_hashes ) {
		$scope = $this->validate_identifiers( $blueprint_id, $change_set_id );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return $this->transaction->run(
			function () use ( $blueprint_id, $change_set_id, $status, $failure_code, $identity_hashes ) {
				global $wpdb;

				$records = $this->locked_scope( $blueprint_id, $change_set_id, $identity_hashes );
				if ( is_wp_error( $records ) ) {
					return $records;
				}
				foreach ( $records as $record ) {
					if ( $status === $record['status'] ) {
						continue;
					}
					if ( 'claimed' !== $record['status'] ) {
						return $this->state_conflict();
					}
					$updated = $wpdb->update(
						Tables::name( Tables::STORAGE_CLAIMS ),
						[
							'status' => $status,
							'failure_code' => 'failed' === $status ? $failure_code : null,
							'updated_at' => current_time( 'mysql', true ),
						],
						[
							'identity_hash' => $record['identity_hash'],
							'blueprint_id' => (string) $blueprint_id,
							'change_set_id' => (string) $change_set_id,
							'status' => 'claimed',
						]
					);
					if ( 1 !== $updated ) {
						return $this->state_conflict();
					}
				}
				return $this->for_change_set( $blueprint_id, $change_set_id );
			}
		);
	}

	private function locked_scope( $blueprint_id, $change_set_id, array $identity_hashes ) {
		global $wpdb;

		$table = Tables::name( Tables::STORAGE_CLAIMS );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE blueprint_id = %s AND change_set_id = %s ORDER BY identity_hash FOR UPDATE", $blueprint_id, $change_set_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$records = array_map( [ $this, 'hydrate' ], $rows ?: [] );
		if ( ! $identity_hashes ) {
			return $records;
		}
		$requested = [];
		foreach ( $identity_hashes as $hash ) {
			$hash = strtolower( (string) $hash );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
				return $this->error( 'eit_storage_claim_identity_invalid', __( 'A requested storage claim identity is invalid.', 'elementor-implementation-toolkit' ) );
			}
			$requested[ $hash ] = true;
		}
		$selected = array_values( array_filter( $records, fn( $record ) => isset( $requested[ $record['identity_hash'] ] ) ) );
		return count( $selected ) === count( $requested )
			? $selected
			: $this->error( 'eit_storage_claim_missing', __( 'A requested storage claim does not belong to this change set.', 'elementor-implementation-toolkit' ) );
	}

	private function locked_owner( array $claim ) {
		global $wpdb;

		$table = Tables::name( Tables::STORAGE_CLAIMS );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE strategy = %s AND storage_slug = %s FOR UPDATE", $claim['strategy'], $claim['storage_slug'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	private function validate_scope( $blueprint_id, $change_set_id, $lock = false ) {
		$valid = $this->validate_identifiers( $blueprint_id, $change_set_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		global $wpdb;
		$table = Tables::name( Tables::CHANGE_SETS );
		$owner = $lock
			? $wpdb->get_row( $wpdb->prepare( "SELECT blueprint_id,status FROM `{$table}` WHERE id = %s FOR UPDATE", $change_set_id ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_row( $wpdb->prepare( "SELECT blueprint_id,status FROM `{$table}` WHERE id = %s", $change_set_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $owner || ! hash_equals( (string) $owner['blueprint_id'], (string) $blueprint_id ) || 'applying' !== $owner['status'] ) {
			return $this->error( 'eit_storage_claim_change_set_invalid', __( 'Storage can only be claimed by its applying Blueprint change set.', 'elementor-implementation-toolkit' ) );
		}
		return true;
	}

	private function validate_identifiers( $blueprint_id, $change_set_id ) {
		return Uuid::is_valid( $blueprint_id ) && Uuid::is_valid( $change_set_id )
			? true
			: $this->error( 'eit_storage_claim_scope_invalid', __( 'The storage claim owner is invalid.', 'elementor-implementation-toolkit' ) );
	}

	private function normalize_identity( $strategy, $slug ) {
		$strategy = sanitize_key( $strategy );
		$slug = sanitize_key( $slug );
		$hash = self::identity_hash( $strategy, $slug );
		return '' !== $hash
			? [ 'identity_hash' => $hash, 'strategy' => $strategy, 'storage_slug' => $slug ]
			: $this->error( 'eit_storage_claim_identity_invalid', __( 'The storage claim identity is invalid.', 'elementor-implementation-toolkit' ) );
	}

	private function identity_exists( $strategy, $slug ) {
		return 'cpt' === $strategy
			? function_exists( 'post_type_exists' ) && post_type_exists( $slug )
			: CctSchemaManager::table_exists( $slug );
	}

	private function change_set_is_applying( $change_set_id ) {
		global $wpdb;
		$table = Tables::name( Tables::CHANGE_SETS );
		return 'applying' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id = %s", $change_set_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function hydrate( array $row ) {
		$row['existed_before'] = (bool) $row['existed_before'];
		return $row;
	}

	private function ownership_conflict( array $owner ) {
		return $this->error(
			'eit_storage_claim_conflict',
			__( 'Another Blueprint already owns this storage identity.', 'elementor-implementation-toolkit' ),
			[
				'status' => 409,
				'identity_hash' => $owner['identity_hash'],
				'strategy' => $owner['strategy'],
				'storage_slug' => $owner['storage_slug'],
				'blueprint_id' => $owner['blueprint_id'],
				'change_set_id' => $owner['change_set_id'],
			]
		);
	}

	private function state_conflict() {
		return $this->error( 'eit_storage_claim_state_conflict', __( 'The storage claim changed state before this operation completed.', 'elementor-implementation-toolkit' ) );
	}

	private function error( $code, $message, array $data = [] ) {
		return new \WP_Error( $code, $message, $data );
	}
}
