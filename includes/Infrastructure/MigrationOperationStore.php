<?php
/**
 * Immutable plans and compare-and-swap progress for staged field migrations.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationOperationStore {

	const STATUSES = [
		'planned', 'target_preparing', 'target_prepared', 'copying', 'copied', 'validating', 'validated', 'switching',
		'switched', 'reconciling', 'reconciled', 'retryable_failure', 'terminal_failure', 'aborted', 'rolling_back', 'rolled_back',
	];

	const FINISHED_STATUSES = [ 'reconciled', 'terminal_failure', 'aborted', 'rolled_back' ];

	private $transaction;
	private $reader;

	public function __construct( ?Transaction $transaction = null, ?MigrationOperationReader $reader = null ) {
		$this->transaction = $transaction ?: new Transaction();
		$this->reader = $reader ?: new MigrationOperationReader();
	}

	public function reserve_many( $blueprint_id, $change_set_id, array $operations ) {
		if ( ! Uuid::is_valid( $blueprint_id ) || ! Uuid::is_valid( $change_set_id ) ) {
			return $this->error( 'eit_migration_scope_invalid', __( 'Migration operation scope is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$records = [];
		$targets = [];
		$fields = [];
		foreach ( $operations as $operation ) {
			$record = MigrationOperationRecordCodec::normalize_operation( $operation );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			if ( isset( $records[ $record['id'] ] ) || isset( $targets[ $record['target_identity_hash'] ] ) || isset( $fields[ $record['field_id'] ] ) ) {
				return $this->error( 'eit_migration_reservation_duplicate', __( 'Migration operations contain a duplicate identity.', 'elementor-implementation-toolkit' ) );
			}
			$records[ $record['id'] ] = $record;
			$targets[ $record['target_identity_hash'] ] = true;
			$fields[ $record['field_id'] ] = true;
		}

		return $this->transaction->run(
			function () use ( $blueprint_id, $change_set_id, $records ) {
				$scope = $this->validate_scope( $blueprint_id, $change_set_id );
				if ( is_wp_error( $scope ) ) {
					return $scope;
				}
				$reserved = [];
				foreach ( $records as $record ) {
					$result = $this->reserve_one( $blueprint_id, $change_set_id, $record );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					$reserved[] = $result;
				}
				return $reserved;
			}
		);
	}

	public function get( $id ) {
		return $this->reader->get( $id );
	}

	public function for_change_set( $blueprint_id, $change_set_id ) {
		return $this->reader->for_change_set( $blueprint_id, $change_set_id );
	}

	public function transition( $id, $expected_status, $expected_revision, $to_status ) {
		if ( in_array( $to_status, [ 'validated', 'retryable_failure', 'terminal_failure' ], true ) || ! self::can_transition( $expected_status, $to_status ) ) {
			return $this->error( 'eit_migration_transition_invalid', __( 'Migration operation transition is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$finished_at = in_array( $to_status, self::FINISHED_STATUSES, true ) ? current_time( 'mysql', true ) : null;
		return $this->cas(
			$id,
			$expected_status,
			$expected_revision,
			[
				'status' => $to_status,
				'resume_status' => null,
				'error_code' => null,
				'finished_at' => $finished_at,
			]
		);
	}

	public function checkpoint( $id, $expected_revision, array $cursor, $copied_count ) {
		$count = MigrationOperationRecordCodec::count_value( $copied_count );
		$current = $this->get( $id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( null === $count || ! $current || 'copying' !== $current['status'] || (int) $expected_revision !== $current['state_revision'] || $count < $current['copied_count'] ) {
			return $this->state_conflict();
		}
		$encoded = JsonCodec::encode( MigrationOperationRecordCodec::sort_recursive( $cursor ) );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		return $this->cas( $id, 'copying', $expected_revision, [ 'cursor' => $encoded, 'copied_count' => $count ] );
	}

	public function record_proof( $id, $expected_revision, array $proof, $verified ) {
		$current = $this->get( $id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$proof = MigrationOperationRecordCodec::normalize_proof( $proof );
		if ( is_wp_error( $proof ) ) {
			return $proof;
		}
		if ( ! $current || 'validating' !== $current['status'] || (int) $expected_revision !== $current['state_revision'] ) {
			return $this->state_conflict();
		}
		$matches = $proof['source_count'] === $proof['transformed_count']
			&& $proof['source_count'] === $proof['target_count']
			&& 0 === $proof['rejected_count']
			&& hash_equals( $proof['source_checksum'], $proof['target_checksum'] );
		if ( $verified && ! $matches ) {
			return $this->error( 'eit_migration_proof_invalid', __( 'Migration proof does not establish an equivalent copy.', 'elementor-implementation-toolkit' ) );
		}
		$data = $proof;
		$data['status'] = $verified ? 'validated' : 'retryable_failure';
		$data['resume_status'] = $verified ? null : 'validating';
		$data['error_code'] = $verified ? null : 'eit_migration_proof_mismatch';
		$data['attempts'] = $current['attempts'] + ( $verified ? 0 : 1 );
		return $this->cas( $id, 'validating', $expected_revision, $data );
	}

	public function record_failure( $id, $expected_status, $expected_revision, $failure_status, $error_code ) {
		if ( ! in_array( $failure_status, [ 'retryable_failure', 'terminal_failure' ], true ) || ! self::can_transition( $expected_status, $failure_status ) ) {
			return $this->error( 'eit_migration_failure_invalid', __( 'Migration failure transition is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$current = $this->get( $id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$error_code = substr( sanitize_key( $error_code ), 0, 96 );
		if ( ! $current || $current['status'] !== $expected_status || $current['state_revision'] !== (int) $expected_revision || '' === $error_code ) {
			return $this->state_conflict();
		}
		$terminal = 'terminal_failure' === $failure_status;
		return $this->cas(
			$id,
			$expected_status,
			$expected_revision,
			[
				'status' => $failure_status,
				'resume_status' => $terminal ? null : $expected_status,
				'attempts' => $current['attempts'] + 1,
				'error_code' => $error_code,
				'finished_at' => $terminal ? current_time( 'mysql', true ) : null,
			]
		);
	}

	public function record_retryable_group_failure( $blueprint_id, $change_set_id, $expected_status, $error_code ) {
		return $this->transaction->run(
			function () use ( $blueprint_id, $change_set_id, $expected_status, $error_code ) {
				$records = $this->for_change_set( $blueprint_id, $change_set_id );
				if ( is_wp_error( $records ) || ! $records ) {
					return is_wp_error( $records ) ? $records : $this->state_conflict();
				}
				foreach ( $records as $record ) {
					$already_recorded = 'retryable_failure' === $record['status'] && $expected_status === $record['resume_status'];
					if ( $expected_status !== $record['status'] && ! $already_recorded ) {
						return $this->state_conflict();
					}
				}
				foreach ( $records as $record ) {
					if ( 'retryable_failure' === $record['status'] ) {
						continue;
					}
					$failed = $this->record_failure( $record['id'], $expected_status, $record['state_revision'], 'retryable_failure', $error_code );
					if ( is_wp_error( $failed ) ) {
						return $failed;
					}
				}
				return $this->for_change_set( $blueprint_id, $change_set_id );
			}
		);
	}

	public function record_terminal_group_failure( $blueprint_id, $change_set_id, $failed_id, $expected_status, $expected_revision, $error_code ) {
		return $this->transaction->run(
			function () use ( $blueprint_id, $change_set_id, $failed_id, $expected_status, $expected_revision, $error_code ) {
				$records = $this->for_change_set( $blueprint_id, $change_set_id );
				if ( is_wp_error( $records ) || ! $records ) {
					return is_wp_error( $records ) ? $records : $this->state_conflict();
				}
				$failed_record = null;
				foreach ( $records as $record ) {
					if ( $record['id'] === $failed_id ) {
						$failed_record = $record;
						continue;
					}
					if ( ! $this->abortable_before_switch( $record ) && ! in_array( $record['status'], [ 'terminal_failure', 'aborted' ], true ) ) {
						return $this->error( 'eit_migration_group_abort_unsafe', __( 'Migration siblings cannot be aborted after runtime authority switched.', 'elementor-implementation-toolkit' ) );
					}
				}
				if ( ! $failed_record || $expected_status !== $failed_record['status'] || (int) $expected_revision !== $failed_record['state_revision'] ) {
					return $this->state_conflict();
				}
				$failed = $this->record_failure( $failed_id, $expected_status, $expected_revision, 'terminal_failure', $error_code );
				if ( is_wp_error( $failed ) ) {
					return $failed;
				}
				foreach ( $records as $record ) {
					if ( $record['id'] === $failed_id || in_array( $record['status'], [ 'terminal_failure', 'aborted' ], true ) ) {
						continue;
					}
					$aborted = $this->abort_record( $record, $failed_id );
					if ( is_wp_error( $aborted ) ) {
						return $aborted;
					}
				}
				return $this->for_change_set( $blueprint_id, $change_set_id );
			}
		);
	}

	public function resume( $id, $expected_revision ) {
		$current = $this->get( $id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$resume = $current['resume_status'] ?? '';
		if ( ! $current || 'retryable_failure' !== $current['status'] || $current['state_revision'] !== (int) $expected_revision || ! in_array( $resume, self::STATUSES, true ) || in_array( $resume, self::FINISHED_STATUSES, true ) ) {
			return $this->state_conflict();
		}
		return $this->cas( $id, 'retryable_failure', $expected_revision, [ 'status' => $resume, 'resume_status' => null, 'error_code' => null, 'finished_at' => null ] );
	}

	public static function can_transition( $from, $to ) {
		$map = [
			'planned' => [ 'target_preparing', 'aborted', 'terminal_failure' ],
			'target_preparing' => [ 'target_prepared', 'retryable_failure', 'terminal_failure' ],
			'target_prepared' => [ 'copying', 'retryable_failure', 'terminal_failure' ],
			'copying' => [ 'copied', 'retryable_failure', 'terminal_failure' ],
			'copied' => [ 'validating', 'retryable_failure', 'terminal_failure' ],
			'validating' => [ 'validated', 'retryable_failure', 'terminal_failure' ],
			'validated' => [ 'switching', 'retryable_failure', 'terminal_failure' ],
			'switching' => [ 'switched', 'retryable_failure', 'terminal_failure' ],
			'switched' => [ 'reconciling', 'rolling_back' ],
			'reconciling' => [ 'reconciled', 'retryable_failure', 'rolling_back' ],
			'reconciled' => [ 'rolling_back' ],
			'rolling_back' => [ 'rolled_back', 'retryable_failure', 'terminal_failure' ],
		];
		return in_array( $to, $map[ $from ] ?? [], true );
	}

	public static function operation_checksum( array $operation ) {
		return MigrationOperationRecordCodec::operation_checksum( $operation );
	}

	public static function source_identity_hash( array $operation ) {
		return MigrationOperationRecordCodec::identity_hash( $operation, 'source' );
	}

	public static function target_identity_hash( array $operation ) {
		return MigrationOperationRecordCodec::identity_hash( $operation, 'target' );
	}

	private function reserve_one( $blueprint_id, $change_set_id, array $record ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$data = array_merge(
			$record,
			[
				'blueprint_id' => (string) $blueprint_id,
				'change_set_id' => (string) $change_set_id,
				'status' => 'planned',
				'resume_status' => null,
				'state_revision' => 0,
				'cursor' => null,
				'copied_count' => 0,
				'source_count' => null,
				'transformed_count' => null,
				'target_count' => null,
				'rejected_count' => 0,
				'source_checksum' => null,
				'target_checksum' => null,
				'attempts' => 0,
				'error_code' => null,
				'created_at' => $now,
				'updated_at' => $now,
				'finished_at' => null,
			]
		);
		$previous_suppression = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( Tables::name( Tables::MIGRATION_OPERATIONS ), $data );
		$database_error = sanitize_text_field( $wpdb->last_error );
		$wpdb->suppress_errors( $previous_suppression );
		if ( false !== $inserted ) {
			return $this->get( $record['id'] );
		}

		$existing = $this->get( $record['id'] );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( $existing ) {
			$same = hash_equals( $existing['blueprint_id'], (string) $blueprint_id )
				&& hash_equals( $existing['change_set_id'], (string) $change_set_id )
				&& hash_equals( $existing['operation_checksum'], $record['operation_checksum'] )
				&& hash_equals( $existing['target_identity_hash'], $record['target_identity_hash'] );
			return $same ? $existing : $this->error( 'eit_migration_operation_collision', __( 'Migration operation identity already represents another immutable plan.', 'elementor-implementation-toolkit' ) );
		}
		$target = $this->reader->by_target( $record['target_identity_hash'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( $target ) {
			return $this->error( 'eit_migration_target_claimed', __( 'The migration target storage identity has already been reserved.', 'elementor-implementation-toolkit' ), [ 'operation_id' => $target['id'] ] );
		}
		$field = $this->reader->by_field( $change_set_id, $record['field_id'] );
		if ( is_wp_error( $field ) ) {
			return $field;
		}
		return $field
			? $this->error( 'eit_migration_field_claimed', __( 'This change set already reserved a migration for the field.', 'elementor-implementation-toolkit' ), [ 'operation_id' => $field['id'] ] )
			: $this->error( 'eit_migration_reservation_failed', __( 'Migration operation could not be reserved.', 'elementor-implementation-toolkit' ), [ 'database_error' => $database_error ] );
	}

	private function validate_scope( $blueprint_id, $change_set_id ) {
		global $wpdb;

		$table = Tables::name( Tables::CHANGE_SETS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT blueprint_id,status FROM `{$table}` WHERE id = %s FOR UPDATE", $change_set_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row && hash_equals( (string) $row['blueprint_id'], (string) $blueprint_id ) && in_array( $row['status'], [ 'prepared', 'applying' ], true )
			? true
			: $this->error( 'eit_migration_scope_conflict', __( 'Migration operations do not belong to a prepared or applying change set.', 'elementor-implementation-toolkit' ) );
	}

	private function cas( $id, $expected_status, $expected_revision, array $data ) {
		global $wpdb;

		$data['state_revision'] = (int) $expected_revision + 1;
		$data['updated_at'] = current_time( 'mysql', true );
		$updated = $wpdb->update(
			Tables::name( Tables::MIGRATION_OPERATIONS ),
			$data,
			[ 'id' => (string) $id, 'status' => (string) $expected_status, 'state_revision' => (int) $expected_revision ]
		);
		return 1 === $updated ? $this->get( $id ) : $this->state_conflict();
	}

	private function abortable_before_switch( array $record ) {
		$pre_switch = [ 'planned', 'target_preparing', 'target_prepared', 'copying', 'copied', 'validating', 'validated', 'switching' ];
		return in_array( $record['status'], $pre_switch, true )
			|| ( 'retryable_failure' === $record['status'] && in_array( $record['resume_status'], $pre_switch, true ) );
	}

	private function abort_record( array $record, $failed_id ) {
		if ( ! $this->abortable_before_switch( $record ) ) {
			return $this->error( 'eit_migration_group_abort_unsafe', __( 'Migration operation cannot be aborted after runtime authority switched.', 'elementor-implementation-toolkit' ) );
		}
		return $this->cas(
			$record['id'],
			$record['status'],
			$record['state_revision'],
			[
				'status' => 'aborted',
				'resume_status' => null,
				'error_code' => 'eit_migration_sibling_aborted_' . substr( sanitize_key( $failed_id ), 0, 12 ),
				'finished_at' => current_time( 'mysql', true ),
			]
		);
	}

	private function state_conflict() {
		return $this->error( 'eit_migration_state_conflict', __( 'Migration operation changed before this write could be applied.', 'elementor-implementation-toolkit' ) );
	}

	private function error( $code, $message, array $data = [] ) {
		return new \WP_Error( $code, $message, $data );
	}
}
