<?php
/**
 * Executes one confirmed Blueprint publication under durable locks and claims.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\StorageClaimStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlueprintApplyService {

	private $publication;
	private $ownership;
	private $claims;
	private $compiler;
	private $compilation_freshness;
	private $preparer;
	private $blueprints;
	private $versions;
	private $artifacts;
	private $bindings;
	private $change_sets;
	private $field_migrations;
	private $migration_ledger;
	private $migration_locks;
	private $locks;
	private $runs;
	private $run_events;
	private $cache;
	private $transaction;

	public function __construct( array $dependencies ) {
		foreach ( [ 'publication', 'ownership', 'compiler', 'preparer', 'blueprints', 'versions', 'artifacts', 'bindings', 'change_sets', 'field_migrations', 'locks', 'runs', 'run_events', 'cache', 'transaction' ] as $dependency ) {
			$this->{$dependency} = $dependencies[ $dependency ];
		}
		$this->claims = $dependencies['claims'] ?? new StorageClaimStore();
		$this->compilation_freshness = $dependencies['compilation_freshness'] ?? new PreparedCompilationFreshness( $this->compiler );
		$this->migration_ledger = $dependencies['migration_ledger'] ?? new MigrationLedgerValidator();
		$this->migration_locks = $dependencies['migration_locks'] ?? new MigrationStorageLockCoordinator();
	}

	public function apply( $change_set_id, $confirmation_token, $user_id = 0 ) {
		$change_set = $this->change_sets->get( $change_set_id );
		if ( ! $change_set || ! in_array( $change_set['status'] ?? '', [ 'prepared', 'applying' ], true ) ) {
			return new \WP_Error( 'eit_change_set_not_prepared', __( 'Blueprint change set is neither prepared nor recoverable.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! hash_equals( $change_set['confirmation_hash'], hash( 'sha256', (string) $confirmation_token ) ) ) {
			return new \WP_Error( 'eit_change_set_confirmation_invalid', __( 'Blueprint publication confirmation is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$integrity = $this->migration_ledger->validate( $change_set );
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
		}

		$ownership_resource = 'storage-ownership';
		$ownership_lock = $this->locks->acquire( $ownership_resource, $user_id, 900 );
		if ( is_wp_error( $ownership_lock ) ) {
			return $ownership_lock;
		}
		$resource = 'blueprint-' . $change_set['blueprint_id'];
		$lock = $this->locks->acquire( $resource, $user_id, 900 );
		if ( is_wp_error( $lock ) ) {
			$this->locks->release( $ownership_resource, $ownership_lock );
			return $lock;
		}
		$run = null;
		$claim_records = [];
		$migration_leases = [];
		$migration_heartbeat = false;
		$heartbeat = new LifecycleLeaseGuard( $this->locks, [ $ownership_resource => $ownership_lock, $resource => $lock ] );
		try {
			$locked_change_set = $this->change_sets->get( $change_set_id );
			$integrity = is_array( $locked_change_set ) ? $this->migration_ledger->validate( $locked_change_set ) : null;
			if ( ! $locked_change_set || is_wp_error( $integrity ) ) {
				return is_wp_error( $integrity ) ? $integrity : new \WP_Error( 'eit_change_set_not_prepared', __( 'Blueprint change set is neither prepared nor recoverable.', 'elementor-implementation-toolkit' ) );
			}
			$authorized = $this->publication->authorize_locked( $change_set_id, $confirmation_token, $change_set['blueprint_id'] );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}
			$change_set = $authorized['change_set'];
			$fresh = $this->compilation_freshness->validate( $change_set, $authorized['blueprint'] );
			if ( is_wp_error( $fresh ) ) {
				return $fresh;
			}
			if ( $this->migration_planned( $change_set ) ) {
				$migration_leases = $this->migration_locks->acquire( $change_set['blueprint_id'], $change_set_id, $user_id, $this->field_migrations, [ $ownership_resource => $ownership_lock, $resource => $lock ], LifecycleLeaseGuard::storage_scopes( $change_set['compiled_artifacts']['artifacts'] ?? [] ) );
				if ( is_wp_error( $migration_leases ) ) {
					return $migration_leases;
				}
				$migration_heartbeat = true;
			} else {
				$migration_leases = $this->migration_locks->acquire_scopes( LifecycleLeaseGuard::storage_scopes( $change_set['compiled_artifacts']['artifacts'] ?? [] ), $user_id );
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
			return $this->apply_owned( $change_set_id, $change_set, $authorized['blueprint'], $user_id, $run, $claim_records, $heartbeat );
		} catch ( \Throwable $error ) {
			$failure = new \WP_Error( 'eit_blueprint_apply_exception', __( 'Blueprint publication stopped after an unexpected runtime failure.', 'elementor-implementation-toolkit' ) );
			$this->fail_claims( $change_set, $change_set_id, $claim_records, $failure );
			$current = $this->change_sets->get( $change_set_id );
			$retryable = false;
			if ( $this->migration_planned( $change_set ) ) {
				$switch_failure = $this->field_migrations->record_switch_failure( $change_set['blueprint_id'], $change_set_id, $failure );
				$retryability = is_wp_error( $switch_failure ) ? $this->field_migrations->can_retry( $change_set['blueprint_id'], $change_set_id ) : (bool) ( $switch_failure['retryable'] ?? false );
				if ( is_wp_error( $retryability ) ) {
					$failure = $retryability;
					$retryable = true;
				} else {
					$retryable = (bool) $retryability;
				}
			}
			if ( ! $retryable && 'applying' === ( $current['status'] ?? '' ) ) {
				$this->change_sets->transition( $change_set_id, 'applying', 'failed' );
			}
			if ( is_array( $run ) && ! empty( $run['id'] ) ) {
				$this->runs->finish( $run['id'], 'failed', $failure );
			}
			do_action( 'eit_blueprint_apply_failed', $change_set['blueprint_id'], get_class( $error ) );
			return $failure;
		} finally {
			if ( $migration_leases ) {
				$this->migration_locks->release( $migration_leases, $migration_heartbeat ? $this->field_migrations : null );
			}
			$this->locks->release( $resource, $lock );
			$this->locks->release( $ownership_resource, $ownership_lock );
		}
	}

	private function apply_owned( $change_set_id, array $change_set, array $blueprint, $user_id, &$run, array &$claim_records, LifecycleLeaseGuard $heartbeat ) {
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $pulse;
		}
		$owned = $this->ownership->validate( $change_set['blueprint_id'], $change_set['compiled_artifacts']['artifacts'] );
		if ( is_wp_error( $owned ) ) {
			return $owned;
		}
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $pulse;
		}

		$recovering = 'applying' === ( $change_set['status'] ?? '' );
		$migration_planned = $this->migration_planned( $change_set );
		if ( ! $recovering ) {
			$transition = $this->change_sets->transition( $change_set_id, 'prepared', 'applying' );
			if ( is_wp_error( $transition ) ) {
				return $transition;
			}
		}
		$run = $this->runs->start( $change_set['blueprint_id'], 'apply', $change_set_id, [ 'user_id' => $user_id, 'compiler_checksum' => $change_set['compiled_artifacts']['compiler_checksum'], 'recovery' => $recovering ] );
		if ( is_wp_error( $run ) ) {
			$this->change_sets->transition( $change_set_id, 'applying', 'failed' );
			return $run;
		}
		$recorded = $this->record_compilation( $run, $change_set );
		if ( is_wp_error( $recorded ) ) {
			return $this->fail_apply( $change_set_id, $run, $recorded );
		}
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $this->fail_apply( $change_set_id, $run, $pulse );
		}

		$claim_records = $this->claims->claim_many( $change_set['blueprint_id'], $change_set_id, $change_set['compiled_artifacts']['artifacts'] );
		if ( is_wp_error( $claim_records ) ) {
			return $this->fail_apply( $change_set_id, $run, $claim_records );
		}
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $this->fail_apply( $change_set_id, $run, $pulse );
		}
		$this->run_events->append( $run['id'], 'storage_claimed', [ 'count' => count( $claim_records ), 'recovery' => $recovering ] );

		$migration_started = microtime( true );
		$migration_targets = $migration_planned ? $this->field_migrations->before_runtime_prepare( $change_set['blueprint_id'], $change_set_id ) : [];
		$this->run_events->append( $run['id'], 'migration_targets_reserved', [ 'ok' => ! is_wp_error( $migration_targets ), 'count' => is_array( $migration_targets ) ? count( $migration_targets ) : 0 ], ( microtime( true ) - $migration_started ) * 1000 );
		if ( is_wp_error( $migration_targets ) ) {
			return $this->fail_migration_apply( $change_set_id, $run, $migration_targets, $change_set, $migration_planned );
		}
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $this->fail_migration_apply( $change_set_id, $run, $pulse, $change_set, $migration_planned );
		}

		$prepare_started = microtime( true );
		$prepared = $this->preparer->prepare( $change_set['compiled_artifacts']['artifacts'], [ 'change_set_id' => $change_set_id ] );
		$this->run_events->append( $run['id'], 'runtime_prepared', [ 'ok' => ! is_wp_error( $prepared ) ], ( microtime( true ) - $prepare_started ) * 1000 );
		if ( is_wp_error( $prepared ) ) {
			$retryable = $migration_planned ? $this->field_migrations->has_operations( $change_set['blueprint_id'], $change_set_id ) : false;
			if ( is_wp_error( $retryable ) ) {
				return $this->fail_apply( $change_set_id, $run, $retryable, true );
			}
			if ( $retryable ) {
				$recorded = $this->field_migrations->record_runtime_failure( $change_set['blueprint_id'], $change_set_id, $prepared );
				if ( is_wp_error( $recorded ) ) {
					return $this->fail_apply( $change_set_id, $run, $recorded, true );
				}
			} else {
				$this->fail_claims( $change_set, $change_set_id, $claim_records, $prepared );
			}
			return $this->fail_apply( $change_set_id, $run, $prepared, $retryable );
		}
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $this->fail_migration_apply( $change_set_id, $run, $pulse, $change_set, $migration_planned );
		}
		$migration_targets = $migration_planned ? $this->field_migrations->after_runtime_prepare( $change_set['blueprint_id'], $change_set_id ) : [];
		if ( is_wp_error( $migration_targets ) ) {
			return $this->fail_migration_apply( $change_set_id, $run, $migration_targets, $change_set, $migration_planned );
		}
		$storage_prepared = $claim_records ? $this->claims->mark_prepared( $change_set['blueprint_id'], $change_set_id, $this->claim_hashes( $claim_records ) ) : [];
		if ( is_wp_error( $storage_prepared ) ) {
			if ( ! $migration_planned ) {
				$this->fail_claims( $change_set, $change_set_id, $claim_records, $storage_prepared );
			}
			return $this->fail_migration_apply( $change_set_id, $run, $storage_prepared, $change_set, $migration_planned );
		}
		$this->run_events->append( $run['id'], 'storage_prepared', [ 'count' => count( $claim_records ) ] );
		$migrated = $migration_planned ? $this->field_migrations->copy_and_validate( $change_set['blueprint_id'], $change_set_id ) : [];
		$this->run_events->append( $run['id'], 'field_migrations_validated', [ 'ok' => ! is_wp_error( $migrated ), 'count' => is_array( $migrated ) ? count( $migrated ) : 0 ] );
		if ( is_wp_error( $migrated ) ) {
			return $this->fail_migration_apply( $change_set_id, $run, $migrated, $change_set, $migration_planned );
		}
		$switch_ready = $migration_planned ? $this->field_migrations->prepare_switch( $change_set['blueprint_id'], $change_set_id ) : [];
		if ( is_wp_error( $switch_ready ) ) {
			return $this->fail_migration_apply( $change_set_id, $run, $switch_ready, $change_set, $migration_planned );
		}
		$pulse = $heartbeat->pulse();
		if ( is_wp_error( $pulse ) ) {
			return $this->fail_migration_apply( $change_set_id, $run, $pulse, $change_set, $migration_planned );
		}

		$result = $this->persist_version( $blueprint, $change_set, $change_set_id, $migration_planned, $heartbeat );
		if ( is_wp_error( $result ) ) {
			$this->run_events->append( $run['id'], 'transaction_failed', [ 'error' => [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ] ] );
			$failure_state = $migration_planned ? $this->field_migrations->record_switch_failure( $change_set['blueprint_id'], $change_set_id, $result ) : [ 'retryable' => false ];
			return is_wp_error( $failure_state )
				? $this->fail_apply( $change_set_id, $run, $failure_state, true )
				: $this->fail_apply( $change_set_id, $run, $result, (bool) ( $failure_state['retryable'] ?? false ) );
		}
		$this->cache->set( $change_set['blueprint_id'], $result['id'], $change_set['compiled_artifacts']['artifacts'] );
		$this->run_events->append( $run['id'], 'version_activated', [ 'version_id' => $result['id'], 'version' => $result['version'], 'checksum' => $result['checksum'] ] );
		$this->runs->finish( $run['id'], 'succeeded' );
		RuntimeDefinitionProvider::invalidate();
		return $result;
	}

	private function record_compilation( array $run, array $change_set ) {
		return $this->run_events->append(
			$run['id'],
			'compiled',
			[
				'compiler_checksum' => $change_set['compiled_artifacts']['compiler_checksum'],
				'artifact_count' => count( $change_set['compiled_artifacts']['artifacts'] ),
				'binding_count' => count( $change_set['compiled_artifacts']['bindings'] ),
				'impact' => $change_set['impact']['summary'] ?? [],
			]
		);
	}

	private function persist_version( array $blueprint, array $change_set, $change_set_id, $migration_planned, LifecycleLeaseGuard $heartbeat ) {
		return $this->transaction->run(
			function () use ( $blueprint, $change_set, $change_set_id, $migration_planned, $heartbeat ) {
				$pulse = $heartbeat->pulse();
				if ( is_wp_error( $pulse ) ) {
					return $pulse;
				}
				$version = $this->versions->insert( $change_set['blueprint_id'], $blueprint['draft_document'], $change_set['draft_checksum'] );
				if ( is_wp_error( $version ) ) {
					return $version;
				}
				$stored = $this->artifacts->insert_many( $change_set['blueprint_id'], $version['id'], $change_set['compiled_artifacts']['artifacts'] );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
				$stored = $this->bindings->insert_many( $change_set['blueprint_id'], $version['id'], $change_set['compiled_artifacts']['bindings'] );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
				$pulse = $heartbeat->pulse();
				if ( is_wp_error( $pulse ) ) {
					return $pulse;
				}
				$source_authority = $this->publication->revalidate_source_locked( $change_set_id, $change_set['blueprint_id'] );
				if ( is_wp_error( $source_authority ) ) {
					return $source_authority;
				}
				$pulse = $heartbeat->pulse();
				if ( is_wp_error( $pulse ) ) {
					return $pulse;
				}
				$activated = $this->blueprints->activate_if_current( $change_set['blueprint_id'], $version['id'], $change_set['from_version_id'], $change_set['draft_checksum'], $blueprint['draft_revision'] ?? null );
				if ( is_wp_error( $activated ) ) {
					return $activated;
				}
				$pulse = $heartbeat->pulse();
				if ( is_wp_error( $pulse ) ) {
					return $pulse;
				}
				if ( $migration_planned ) {
					$switched = $this->field_migrations->mark_switched( $change_set['blueprint_id'], $change_set_id );
					if ( is_wp_error( $switched ) ) {
						return $switched;
					}
				}
				$transitioned = $this->change_sets->transition( $change_set_id, 'applying', 'applied', [ 'applied_at' => current_time( 'mysql', true ) ] );
				if ( is_wp_error( $transitioned ) ) {
					return $transitioned;
				}
				$pulse = $heartbeat->pulse();
				return is_wp_error( $pulse ) ? $pulse : $version;
			}
		);
	}

	private function fail_apply( $change_set_id, array $run, $error, $retryable = false ) {
		if ( ! $retryable ) {
			$this->change_sets->transition( $change_set_id, 'applying', 'failed' );
		}
		$this->runs->finish( $run['id'], 'failed', $error );
		return $error;
	}

	private function fail_migration_apply( $change_set_id, array $run, $error, array $change_set, $migration_planned ) {
		if ( ! $migration_planned ) {
			return $this->fail_apply( $change_set_id, $run, $error );
		}
		$retryable = $this->field_migrations->can_retry( $change_set['blueprint_id'], $change_set_id );
		return is_wp_error( $retryable )
			? $this->fail_apply( $change_set_id, $run, $retryable, true )
			: $this->fail_apply( $change_set_id, $run, $error, (bool) $retryable );
	}

	private function fail_claims( array $change_set, $change_set_id, $claim_records, $error ) {
		if ( is_array( $claim_records ) && $claim_records ) {
			$this->claims->mark_failed( $change_set['blueprint_id'], $change_set_id, is_wp_error( $error ) ? $error->get_error_code() : 'eit_storage_prepare_failed', $this->claim_hashes( $claim_records ) );
		}
	}

	private function claim_hashes( array $claims ) {
		return array_values( array_filter( array_column( $claims, 'identity_hash' ) ) );
	}

	private function migration_planned( array $change_set ) {
		return ! empty( $change_set['impact']['migration_plan']['operations'] );
	}
}
