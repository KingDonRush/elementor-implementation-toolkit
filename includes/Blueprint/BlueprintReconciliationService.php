<?php
/**
 * Reconciles one applied Blueprint under runtime and storage locks.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlueprintReconciliationService {

	private $change_sets;
	private $blueprints;
	private $versions;
	private $artifacts;
	private $bindings;
	private $field_migrations;
	private $migration_ledger;
	private $migration_locks;
	private $locks;
	private $runs;
	private $run_events;
	private $reconciliations;
	private $transaction;
	private $snapshot_integrity;

	public function __construct( array $dependencies ) {
		foreach ( [ 'change_sets', 'blueprints', 'versions', 'artifacts', 'bindings', 'field_migrations', 'migration_locks', 'locks', 'runs', 'run_events', 'reconciliations', 'transaction' ] as $dependency ) {
			$this->{$dependency} = $dependencies[ $dependency ];
		}
		$this->migration_ledger = $dependencies['migration_ledger'] ?? new MigrationLedgerValidator();
		$this->snapshot_integrity = $dependencies['snapshot_integrity'] ?? new PublicationSnapshotIntegrity();
	}

	public function reconcile( $change_set_id ) {
		$change_set = $this->change_sets->get( $change_set_id );
		if ( ! $change_set || 'applied' !== $change_set['status'] ) {
			return new \WP_Error( 'eit_change_set_not_applied', __( 'Only an applied change set can be reconciled.', 'elementor-implementation-toolkit' ) );
		}
		$integrity = $this->migration_ledger->validate( $change_set );
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
		}
		$blueprint_id = $change_set['blueprint_id'];
		$ownership_resource = 'storage-ownership';
		$ownership_lock = $this->locks->acquire( $ownership_resource, 0, 900 );
		if ( is_wp_error( $ownership_lock ) ) {
			return $ownership_lock;
		}
		$resource = 'blueprint-' . $blueprint_id;
		$lock = null;
		$migration_leases = [];
		$migration_heartbeat = false;
		$heartbeat = null;
		try {
			$lock = $this->locks->acquire( $resource, 0, 900 );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
			$change_set = $this->change_sets->get( $change_set_id );
			$integrity = is_array( $change_set ) ? $this->migration_ledger->validate( $change_set ) : null;
			if ( ! $change_set || is_wp_error( $integrity ) ) {
				return is_wp_error( $integrity ) ? $integrity : new \WP_Error( 'eit_reconciliation_authority_changed', __( 'The active Blueprint version no longer belongs to this change set.', 'elementor-implementation-toolkit' ) );
			}
			$blueprint = $this->blueprints->get( $blueprint_id );
			$active_version = is_array( $blueprint ) ? $this->versions->get( $blueprint['active_version_id'] ?? 0 ) : null;
			if ( ! $change_set || 'applied' !== $change_set['status'] || ! $active_version || ! hash_equals( (string) $change_set['draft_checksum'], (string) ( $active_version['checksum'] ?? '' ) ) ) {
				return new \WP_Error( 'eit_reconciliation_authority_changed', __( 'The active Blueprint version no longer belongs to this change set.', 'elementor-implementation-toolkit' ) );
			}
			$heartbeat = new LifecycleLeaseGuard( $this->locks, [ $ownership_resource => $ownership_lock, $resource => $lock ] );
			if ( ! empty( $change_set['impact']['migration_plan']['operations'] ) ) {
				$migration_leases = $this->migration_locks->acquire( $blueprint_id, $change_set_id, 0, $this->field_migrations, [ $ownership_resource => $ownership_lock, $resource => $lock ], LifecycleLeaseGuard::storage_scopes( $change_set['compiled_artifacts']['artifacts'] ?? [] ) );
				if ( is_wp_error( $migration_leases ) ) {
					return $migration_leases;
				}
				$migration_heartbeat = true;
			} else {
				$migration_leases = $this->migration_locks->acquire_scopes( LifecycleLeaseGuard::storage_scopes( $change_set['compiled_artifacts']['artifacts'] ?? [] ), 0 );
				if ( is_wp_error( $migration_leases ) ) {
					return $migration_leases;
				}
			}
			$tracked = $heartbeat->add( $migration_leases );
			if ( is_wp_error( $tracked ) ) {
				return $tracked;
			}
			$pulse = $heartbeat->pulse();
			if ( is_wp_error( $pulse ) ) {
				return $pulse;
			}
			return $this->reconcile_locked( $change_set, $blueprint, $heartbeat );
		} finally {
			if ( $migration_leases ) {
				$this->migration_locks->release( $migration_leases, $migration_heartbeat ? $this->field_migrations : null );
			}
			if ( null !== $lock && ! is_wp_error( $lock ) ) {
				$this->locks->release( $resource, $lock );
			}
			$this->locks->release( $ownership_resource, $ownership_lock );
		}
	}

	private function reconcile_locked( array $change_set, array $blueprint, LifecycleLeaseGuard $heartbeat ) {
		$change_set_id = $change_set['id'];
		$run = $this->runs->start( $change_set['blueprint_id'], 'reconcile', $change_set_id, [ 'active_version_id' => $blueprint['active_version_id'] ?? null ] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $this->fail_read( $run, $pulse, 'leases' );
		}
		$version_id = (int) $blueprint['active_version_id'];
		$stored_artifacts = $this->artifacts->for_version_checked( $version_id );
		if ( is_wp_error( $stored_artifacts ) ) {
			return $this->fail_read( $run, $stored_artifacts, 'artifacts' );
		}
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $this->fail_read( $run, $pulse, 'leases' );
		}
		$stored_bindings = $this->bindings->for_version_checked( $version_id );
		if ( is_wp_error( $stored_bindings ) ) {
			return $this->fail_read( $run, $stored_bindings, 'bindings' );
		}
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $this->fail_read( $run, $pulse, 'leases' );
		}
		$prepared = $change_set['compiled_artifacts'] ?? null;
		$comparison = $this->snapshot_integrity->compare(
			is_array( $prepared ) ? ( $prepared['artifacts'] ?? null ) : null,
			$stored_artifacts,
			is_array( $prepared ) ? ( $prepared['bindings'] ?? null ) : null,
			$stored_bindings,
			$change_set['blueprint_id'],
			$version_id
		);
		if ( is_wp_error( $comparison ) ) {
			return $this->fail_read( $run, $comparison, 'snapshot' );
		}
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $this->fail_read( $run, $pulse, 'leases' );
		}
		$status = $comparison['status'];
		$counts = $comparison['counts'];
		$checksums = $comparison['checksums'];
		$this->record_comparison_events( $run['id'], $comparison );
		if ( 'verified' !== $status ) {
			$proof = $this->reconciliations->record( $change_set['blueprint_id'], $change_set_id, $status, $counts, $checksums );
			$error = is_wp_error( $proof ) ? $proof : new \WP_Error( 'eit_reconciliation_mismatch', __( 'Published artifacts or Field bindings do not match the prepared change set.', 'elementor-implementation-toolkit' ), [ 'components' => $comparison['mismatches'] ] );
			$this->runs->finish( $run['id'], 'failed', $error );
			return $error;
		}
		$migration_planned = ! empty( $change_set['impact']['migration_plan']['operations'] );
		$migration_count = 0;
		$result = $this->transaction->run(
			function () use ( $change_set, $change_set_id, $migration_planned, $counts, $checksums, $heartbeat, &$migration_count ) {
				$pulse = $heartbeat->pulse();
				if ( is_wp_error( $pulse ) ) {
					return $pulse;
				}
				$migration_proof = $migration_planned ? $this->field_migrations->reconcile( $change_set['blueprint_id'], $change_set_id ) : [];
				if ( is_wp_error( $migration_proof ) ) {
					return $migration_proof;
				}
				$pulse = $heartbeat->pulse();
				if ( is_wp_error( $pulse ) ) {
					return $pulse;
				}
				$migration_count = is_array( $migration_proof ) ? count( $migration_proof ) : 0;
				$proof = $this->reconciliations->record( $change_set['blueprint_id'], $change_set_id, 'verified', $counts, $checksums );
				if ( is_wp_error( $proof ) ) {
					return $proof;
				}
				$pulse = $heartbeat->pulse();
				if ( is_wp_error( $pulse ) ) {
					return $pulse;
				}
				$transitioned = $this->change_sets->transition( $change_set_id, 'applied', 'reconciled' );
				if ( is_wp_error( $transitioned ) ) {
					return $transitioned;
				}
				$pulse = $heartbeat->pulse();
				return is_wp_error( $pulse ) ? $pulse : $transitioned;
			}
		);
		$this->run_events->append( $run['id'], 'field_migrations_reconciled', [ 'ok' => ! is_wp_error( $result ), 'count' => $migration_count ] );
		$this->runs->finish( $run['id'], is_wp_error( $result ) ? 'failed' : 'succeeded', is_wp_error( $result ) ? $result : null );
		return $result;
	}

	private function record_comparison_events( $run_id, array $comparison ) {
		foreach ( [ 'artifacts', 'bindings' ] as $component ) {
			$this->run_events->append(
				$run_id,
				$component . '_compared',
				[
					'status' => in_array( $component, $comparison['mismatches'], true ) ? 'mismatch' : 'verified',
					'expected_count' => $comparison['counts'][ $component ]['expected'],
					'actual_count' => $comparison['counts'][ $component ]['actual'],
					'expected_checksum' => $comparison['checksums'][ $component ]['expected'],
					'actual_checksum' => $comparison['checksums'][ $component ]['actual'],
				]
			);
		}
		$this->run_events->append(
			$run_id,
			'publication_snapshot_compared',
			[
				'status' => $comparison['status'],
				'expected_count' => $comparison['counts']['expected'],
				'actual_count' => $comparison['counts']['actual'],
				'expected_checksum' => $comparison['checksums']['expected'],
				'actual_checksum' => $comparison['checksums']['actual'],
				'mismatches' => $comparison['mismatches'],
			]
		);
	}

	private function fail_read( array $run, $error, $component ) {
		$this->run_events->append( $run['id'], 'publication_snapshot_read_failed', [ 'component' => $component, 'error_code' => is_wp_error( $error ) ? $error->get_error_code() : 'unknown' ] );
		$this->runs->finish( $run['id'], 'failed', $error );
		return $error;
	}
}
