<?php
/**
 * Executes reserved field migrations without deleting their source storage.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\MigrationOperationStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FieldMigrationExecutionService {

	const BATCH_SIZE = 200;

	private $operations;
	private $drivers;
	private $failures;
	private $heartbeat;

	public function __construct( $operations = null, array $drivers = [] ) {
		$this->operations = $operations ?: new MigrationOperationStore();
		$this->drivers = $drivers ?: [ new CptFieldMigrationDriver(), new CctFieldMigrationDriver() ];
		$this->failures = new FieldMigrationFailureCoordinator( $this->operations );
		$this->heartbeat = new MigrationHeartbeat( $this->drivers );
	}
	public function set_heartbeat( $heartbeat = null ) {
		return $this->heartbeat->configure( $heartbeat );
	}
	public function reserve( $blueprint_id, $change_set_id, array $operations ) {
		return $this->operations->reserve_many( $blueprint_id, $change_set_id, $operations );
	}

	public function has_operations( $blueprint_id, $change_set_id ) {
		return $this->failures->has_operations( $blueprint_id, $change_set_id );
	}

	public function can_retry( $blueprint_id, $change_set_id ) {
		return $this->failures->can_retry( $blueprint_id, $change_set_id );
	}

	public function record_runtime_failure( $blueprint_id, $change_set_id, \WP_Error $error ) {
		return $this->failures->record_retryable_phase( $blueprint_id, $change_set_id, 'target_preparing', $error );
	}

	public function record_switch_failure( $blueprint_id, $change_set_id, \WP_Error $error ) {
		return $this->failures->record_switch_failure( $blueprint_id, $change_set_id, $error );
	}

	/**
	 * Proves that every physical target is new before schema preparation.
	 */
	public function before_runtime_prepare( $blueprint_id, $change_set_id ) {
		return $this->walk(
			$blueprint_id,
			$change_set_id,
			function ( array $record ) {
				$record = $this->resume_if_needed( $record );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( 'target_preparing' === $record['status'] ) {
					$driver = $this->driver( $record['operation'] );
					$available = is_wp_error( $driver ) ? $driver : $driver->assert_target_available( $record['operation'], true );
					return is_wp_error( $available ) ? $this->fail( $record, $available, false ) : $record;
				}
				if ( 'planned' !== $record['status'] ) {
					return $this->target_preparation_state( $record );
				}
				$driver = $this->driver( $record['operation'] );
				if ( is_wp_error( $driver ) ) {
					return $this->fail( $record, $driver, false );
				}
				$available = $driver->assert_target_available( $record['operation'], false );
				if ( is_wp_error( $available ) ) {
					return $this->fail( $record, $available, false );
				}
				return $this->operations->transition( $record['id'], 'planned', $record['state_revision'], 'target_preparing' );
			}
		);
	}

	/**
	 * Proves that the runtime preparer created the reserved target.
	 */
	public function after_runtime_prepare( $blueprint_id, $change_set_id ) {
		return $this->walk(
			$blueprint_id,
			$change_set_id,
			function ( array $record ) {
				$record = $this->resume_if_needed( $record );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( 'target_preparing' !== $record['status'] ) {
					return $this->copy_preparation_state( $record );
				}
				$driver = $this->driver( $record['operation'] );
				if ( is_wp_error( $driver ) ) {
					return $this->fail( $record, $driver, false );
				}
				$ready = $driver->assert_target_ready( $record['operation'] );
				if ( is_wp_error( $ready ) ) {
					return $this->fail( $record, $ready, true );
				}
				return $this->operations->transition( $record['id'], 'target_preparing', $record['state_revision'], 'target_prepared' );
			}
		);
	}

	/**
	 * Copies resumable batches, then validates semantic parity.
	 */
	public function copy_and_validate( $blueprint_id, $change_set_id ) {
		return $this->walk(
			$blueprint_id,
			$change_set_id,
			function ( array $record ) {
				$record = $this->resume_if_needed( $record );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( 'target_prepared' === $record['status'] ) {
					$record = $this->operations->transition( $record['id'], 'target_prepared', $record['state_revision'], 'copying' );
				}
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( 'copying' === $record['status'] ) {
					$record = $this->copy( $record );
				}
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( 'copied' === $record['status'] ) {
					$record = $this->operations->transition( $record['id'], 'copied', $record['state_revision'], 'validating' );
				}
				if ( is_wp_error( $record ) || 'validating' !== ( $record['status'] ?? '' ) ) {
					return is_wp_error( $record ) ? $record : $this->validated_state( $record );
				}
				return $this->validate( $record );
			}
		);
	}

	/**
	 * Moves validated operations to the transaction-ready switch state.
	 */
	public function prepare_switch( $blueprint_id, $change_set_id ) {
		return $this->walk(
			$blueprint_id,
			$change_set_id,
			function ( array $record ) {
				$record = $this->resume_if_needed( $record );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( 'validated' === $record['status'] ) {
					return $this->operations->transition( $record['id'], 'validated', $record['state_revision'], 'switching' );
				}
				return 'switching' === $record['status'] ? $record : $this->state_error( $record, 'switching' );
			}
		);
	}

	/**
	 * Must run in the same transaction as the active-version pointer switch.
	 */
	public function mark_switched( $blueprint_id, $change_set_id ) {
		return $this->walk(
			$blueprint_id,
			$change_set_id,
			function ( array $record ) {
				if ( 'switched' === $record['status'] ) {
					return $record;
				}
				return 'switching' === $record['status']
					? $this->operations->transition( $record['id'], 'switching', $record['state_revision'], 'switched' )
					: $this->state_error( $record, 'switched' );
			}
		);
	}

	/**
	 * Rechecks the frozen source and active target before releasing the fence.
	 */
	public function reconcile( $blueprint_id, $change_set_id ) {
		return $this->walk(
			$blueprint_id,
			$change_set_id,
			function ( array $record ) {
				if ( 'switched' === $record['status'] ) {
					$record = $this->operations->transition( $record['id'], 'switched', $record['state_revision'], 'reconciling' );
				}
				if ( is_wp_error( $record ) || 'reconciling' !== ( $record['status'] ?? '' ) ) {
					return is_wp_error( $record ) ? $record : ( 'reconciled' === $record['status'] ? $record : $this->state_error( $record, 'reconciling' ) );
				}
				$proof = $this->proof( $record );
				if ( is_wp_error( $proof ) ) {
					return $this->fail( $record, $proof, true );
				}
				if ( ! hash_equals( (string) $record['source_checksum'], $proof['source_checksum'] ) || ! hash_equals( (string) $record['target_checksum'], $proof['target_checksum'] ) ) {
					return $this->fail( $record, $this->proof_error(), true );
				}
				return $this->operations->transition( $record['id'], 'reconciling', $record['state_revision'], 'reconciled' );
			}
		);
	}

	public function prepare_rollback( $blueprint_id, $change_set_id ) {
		return $this->walk(
			$blueprint_id,
			$change_set_id,
			function ( array $record ) {
				$record = $this->resume_if_needed( $record );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( 'rolling_back' === $record['status'] ) {
					return $record;
				}
				if ( ! in_array( $record['status'], [ 'switched', 'reconciling', 'reconciled' ], true ) ) {
					return $this->state_error( $record, 'rolling_back' );
				}
				$proof = $this->proof( $record );
				$matches = ! is_wp_error( $proof )
					&& 0 === $proof['rejected_count']
					&& hash_equals( (string) $record['source_checksum'], $proof['source_checksum'] )
					&& hash_equals( $proof['source_checksum'], $proof['target_checksum'] );
				if ( ! $matches ) {
					return is_wp_error( $proof ) ? $proof : new \WP_Error( 'eit_migration_rollback_data_changed', __( 'Rollback is blocked because migrated values changed after the verified copy.', 'elementor-implementation-toolkit' ) );
				}
				return $this->operations->transition( $record['id'], $record['status'], $record['state_revision'], 'rolling_back' );
			}
		);
	}

	/**
	 * Must run atomically with the previous-version pointer activation.
	 */
	public function mark_rolled_back( $blueprint_id, $change_set_id ) {
		return $this->walk(
			$blueprint_id,
			$change_set_id,
			function ( array $record ) {
				if ( 'rolled_back' === $record['status'] ) {
					return $record;
				}
				return 'rolling_back' === $record['status']
					? $this->operations->transition( $record['id'], 'rolling_back', $record['state_revision'], 'rolled_back' )
					: $this->state_error( $record, 'rolled_back' );
			}
		);
	}

	private function copy( array $record ) {
		$driver = $this->driver( $record['operation'] );
		if ( is_wp_error( $driver ) ) {
			return $this->fail( $record, $driver, false );
		}
		$cursor = absint( $record['cursor']['last_id'] ?? 0 );
		while ( true ) {
			$heartbeat = $this->heartbeat->pulse();
			if ( is_wp_error( $heartbeat ) ) {
				return $heartbeat;
			}
			$batch = $driver->copy_batch( $record['operation'], $cursor, self::BATCH_SIZE );
			if ( is_wp_error( $batch ) ) {
				return $this->fail( $record, $batch, $this->failures->retryable( $batch ) );
			}
			$next_cursor = absint( $batch['cursor'] ?? $cursor );
			$processed = absint( $batch['processed'] ?? 0 );
			if ( $next_cursor < $cursor || ( ! empty( $batch['complete'] ) && $processed > self::BATCH_SIZE ) || ( empty( $batch['complete'] ) && ( 0 === $processed || $next_cursor <= $cursor ) ) ) {
				return $this->fail( $record, new \WP_Error( 'eit_migration_driver_progress_invalid', __( 'Migration driver returned an invalid progress checkpoint.', 'elementor-implementation-toolkit' ) ), false );
			}
			$record = $this->operations->checkpoint( $record['id'], $record['state_revision'], [ 'last_id' => $next_cursor ], (int) $record['copied_count'] + absint( $batch['copied'] ?? $processed ) );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			$cursor = $next_cursor;
			if ( ! empty( $batch['complete'] ) ) {
				return $this->operations->transition( $record['id'], 'copying', $record['state_revision'], 'copied' );
			}
		}
	}

	private function validate( array $record ) {
		$proof = $this->proof( $record );
		if ( is_wp_error( $proof ) ) {
			return $this->fail( $record, $proof, $this->failures->retryable( $proof ) );
		}
		$verified = $proof['source_count'] === $proof['transformed_count']
			&& $proof['transformed_count'] === $proof['target_count']
			&& 0 === $proof['rejected_count']
			&& hash_equals( $proof['source_checksum'], $proof['target_checksum'] );
		$result = $this->operations->record_proof( $record['id'], $record['state_revision'], $proof, $verified );
		return $verified ? $result : ( is_wp_error( $result ) ? $result : $this->proof_error() );
	}

	private function proof( array $record ) {
		$driver = $this->driver( $record['operation'] );
		if ( is_wp_error( $driver ) ) {
			return $driver;
		}
		$source = $driver->fingerprint( $record['operation'], 'source' );
		$transformed = $driver->fingerprint( $record['operation'], 'transformed' );
		$target = $driver->fingerprint( $record['operation'], 'target' );
		foreach ( [ $source, $transformed, $target ] as $fingerprint ) {
			if ( is_wp_error( $fingerprint ) ) {
				return $fingerprint;
			}
		}
		return [
			'source_count' => (int) $source['record_count'],
			'transformed_count' => max( 0, (int) $transformed['record_count'] - (int) $transformed['rejected_count'] ),
			'target_count' => (int) $target['record_count'],
			'rejected_count' => (int) $source['rejected_count'] + (int) $transformed['rejected_count'] + (int) $target['rejected_count'],
			'source_checksum' => (string) $transformed['checksum'],
			'target_checksum' => (string) $target['checksum'],
		];
	}

	private function walk( $blueprint_id, $change_set_id, callable $callback ) {
		$records = $this->operations->for_change_set( $blueprint_id, $change_set_id );
		if ( is_wp_error( $records ) ) {
			return $records;
		}
		$result = [];
		foreach ( $records as $record ) {
			$heartbeat = $this->heartbeat->pulse();
			if ( is_wp_error( $heartbeat ) ) {
				return $heartbeat;
			}
			$next = $callback( $record );
			if ( is_wp_error( $next ) ) {
				return $next;
			}
			$result[] = $next;
		}
		return $result;
	}

	private function driver( array $operation ) {
		foreach ( $this->drivers as $driver ) {
			if ( $driver->supports( $operation ) ) {
				return $driver;
			}
		}
		return new \WP_Error( 'eit_migration_driver_missing', __( 'No migration driver supports the reserved operation.', 'elementor-implementation-toolkit' ) );
	}

	private function resume_if_needed( array $record ) {
		return 'retryable_failure' === $record['status']
			? $this->operations->resume( $record['id'], $record['state_revision'] )
			: $record;
	}

	private function fail( array $record, \WP_Error $error, $retryable ) {
		return $this->failures->record_operation_failure( $record, $error, $retryable );
	}

	private function target_preparation_state( array $record ) {
		$allowed = [ 'target_preparing', 'target_prepared', 'copying', 'copied', 'validating', 'validated', 'switching' ];
		return in_array( $record['status'], $allowed, true ) ? $record : $this->state_error( $record, 'target_preparing' );
	}

	private function copy_preparation_state( array $record ) {
		$allowed = [ 'target_prepared', 'copying', 'copied', 'validating', 'validated', 'switching' ];
		return in_array( $record['status'], $allowed, true ) ? $record : $this->state_error( $record, 'target_prepared' );
	}

	private function validated_state( array $record ) {
		return in_array( $record['status'], [ 'validated', 'switching' ], true ) ? $record : $this->state_error( $record, 'validated' );
	}

	private function state_error( array $record, $expected ) {
		return new \WP_Error(
			'eit_migration_operation_state_invalid',
			__( 'Migration operation is not in the required durable state.', 'elementor-implementation-toolkit' ),
			[ 'operation_id' => $record['id'] ?? '', 'status' => $record['status'] ?? '', 'expected' => $expected ]
		);
	}

	private function proof_error() {
		return new \WP_Error( 'eit_migration_proof_mismatch', __( 'Copied field data failed count or checksum validation.', 'elementor-implementation-toolkit' ) );
	}
}
