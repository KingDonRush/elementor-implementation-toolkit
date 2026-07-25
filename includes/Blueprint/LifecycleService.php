<?php
/**
 * Draft, impact, confirmation, publication, reconciliation and rollback flow.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BindingStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\ChangeSetStore;
use EIT\Infrastructure\LockStore;
use EIT\Infrastructure\MigrationOperationStore;
use EIT\Infrastructure\ReconciliationStore;
use EIT\Infrastructure\RollbackStore;
use EIT\Infrastructure\RunStore;
use EIT\Infrastructure\RunEventStore;
use EIT\Infrastructure\RuntimeCache;
use EIT\Infrastructure\Transaction;
use EIT\Infrastructure\VersionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LifecycleService {

	private $validator;
	private $canonicalizer;
	private $draft_migrations;
	private $compiler;
	private $impact_planner;
	private $impact_map;
	private $migration_guard;
	private $publication;
	private $ownership;
	private $apply_service;
	private $reconciliation_service;
	private $legacy_rollback;
	private $preparer;
	private $blueprints;
	private $versions;
	private $artifacts;
	private $bindings;
	private $change_sets;
	private $migration_operations;
	private $migration_ledger;
	private $field_migrations;
	private $migration_locks;
	private $locks;
	private $runs;
	private $run_events;
	private $reconciliations;
	private $rollbacks;
	private $cache;
	private $transaction;

	public function __construct( array $dependencies = [] ) {
		$this->validator = $dependencies['validator'] ?? new BlueprintValidator();
		$this->canonicalizer = $dependencies['canonicalizer'] ?? new Canonicalizer();
		$this->draft_migrations = $dependencies['draft_migrations'] ?? new DraftMigrationNormalizer();
		$this->compiler = $dependencies['compiler'] ?? new Compiler();
		$this->impact_planner = $dependencies['impact_planner'] ?? new ImpactPlanner();
		$this->impact_map = $dependencies['impact_map'] ?? new ImpactMapBuilder();
		$this->blueprints = $dependencies['blueprints'] ?? new BlueprintStore();
		$this->versions = $dependencies['versions'] ?? new VersionStore();
		$this->artifacts = $dependencies['artifacts'] ?? new ArtifactStore();
		$this->bindings = $dependencies['bindings'] ?? new BindingStore();
		$this->change_sets = $dependencies['change_sets'] ?? new ChangeSetStore();
		$this->migration_operations = $dependencies['migration_operations'] ?? new MigrationOperationStore();
		$this->migration_ledger = $dependencies['migration_ledger'] ?? new MigrationLedgerValidator( $this->migration_operations );
		$this->field_migrations = $dependencies['field_migrations'] ?? new FieldMigrationExecutionService( $this->migration_operations );
		$this->locks = $dependencies['locks'] ?? new LockStore();
		$this->migration_locks = $dependencies['migration_locks'] ?? new MigrationStorageLockCoordinator( $this->migration_operations, $this->locks );
		$legacy_authority = $dependencies['legacy_authority'] ?? new LegacyAuthoritySnapshot();
		$migrations = $dependencies['migration_guard'] ?? new MigrationPublicationGuard( null, null, $this->compiler, $legacy_authority );
		$this->migration_guard = $migrations;
		$source_commit_guard = $dependencies['source_commit_guard'] ?? new LegacySourceCommitGuard( $legacy_authority );
		$claims = $dependencies['claims'] ?? new \EIT\Infrastructure\StorageClaimStore();
		$this->publication = $dependencies['publication'] ?? new PublicationAuthority( $this->blueprints, $this->change_sets, $migrations, $source_commit_guard );
		$this->ownership = $dependencies['ownership'] ?? new StorageOwnershipValidator( $this->blueprints, $this->artifacts, $migrations, $claims );
		$this->preparer = $dependencies['preparer'] ?? new RuntimeArtifactPreparer();
		$this->runs = $dependencies['runs'] ?? new RunStore();
		$this->run_events = $dependencies['run_events'] ?? new RunEventStore();
		$this->reconciliations = $dependencies['reconciliations'] ?? new ReconciliationStore();
		$this->rollbacks = $dependencies['rollbacks'] ?? new RollbackStore();
		$this->cache = $dependencies['cache'] ?? new RuntimeCache();
		$this->transaction = $dependencies['transaction'] ?? new Transaction();
		$this->apply_service = $dependencies['apply_service'] ?? new BlueprintApplyService(
			[
				'publication' => $this->publication,
				'ownership' => $this->ownership,
				'compiler' => $this->compiler,
				'claims' => $claims,
				'preparer' => $this->preparer,
				'blueprints' => $this->blueprints,
				'versions' => $this->versions,
				'artifacts' => $this->artifacts,
				'bindings' => $this->bindings,
				'change_sets' => $this->change_sets,
				'field_migrations' => $this->field_migrations,
				'migration_ledger' => $this->migration_ledger,
				'migration_locks' => $this->migration_locks,
				'locks' => $this->locks,
				'runs' => $this->runs,
				'run_events' => $this->run_events,
				'cache' => $this->cache,
				'transaction' => $this->transaction,
			]
		);
		$this->reconciliation_service = $dependencies['reconciliation_service'] ?? new BlueprintReconciliationService(
			[
				'change_sets' => $this->change_sets,
				'blueprints' => $this->blueprints,
				'versions' => $this->versions,
				'artifacts' => $this->artifacts,
				'bindings' => $this->bindings,
				'field_migrations' => $this->field_migrations,
				'migration_ledger' => $this->migration_ledger,
				'migration_locks' => $this->migration_locks,
				'locks' => $this->locks,
				'runs' => $this->runs,
				'run_events' => $this->run_events,
				'reconciliations' => $this->reconciliations,
				'transaction' => $this->transaction,
			]
		);
		$this->legacy_rollback = $dependencies['legacy_rollback'] ?? new LegacyRollbackService(
			[
				'blueprints' => $this->blueprints,
				'versions' => $this->versions,
				'change_sets' => $this->change_sets,
				'legacy_authority' => $legacy_authority,
				'source_commit' => $source_commit_guard,
				'migration_ledger' => $this->migration_ledger,
				'locks' => $this->locks,
			]
		);
	}

	public function save_draft( array $document ) {
		unset( $document['checksum'] );
		$existing = ! empty( $document['id'] ) ? $this->blueprints->get( $document['id'] ) : null;
		if ( $existing && ! empty( $existing['active_version_id'] ) ) {
			$active = $this->versions->get( $existing['active_version_id'] );
			if ( is_array( $active['document'] ?? null ) ) {
				$document = $this->draft_migrations->normalize( $document, $active['document'] );
			}
		}
		$checksum = $this->canonicalizer->checksum( $document );
		$document['checksum'] = $checksum;
		return $this->blueprints->save_draft( $document, $checksum );
	}

	public function validate_draft( $blueprint_id ) {
		$blueprint = $this->blueprints->get( $blueprint_id );
		return $blueprint && is_array( $blueprint['draft_document'] )
			? $this->validator->validate( $blueprint['draft_document'] )
			: new ValidationResult( [ [ 'code' => 'draft_not_found', 'message' => 'Blueprint draft was not found.' ] ] );
	}

	public function prepare( $blueprint_id, $user_id = 0 ) {
		$blueprint = $this->blueprints->get( $blueprint_id );
		if ( ! $blueprint || ! is_array( $blueprint['draft_document'] ) ) {
			return new \WP_Error( 'eit_blueprint_draft_not_found', __( 'Blueprint draft was not found.', 'elementor-implementation-toolkit' ) );
		}
		$compiled = $this->compiler->compile( $blueprint['draft_document'] );
		if ( ! $compiled->is_valid() ) {
			return new \WP_Error( 'eit_blueprint_compile_failed', __( 'Blueprint could not be compiled.', 'elementor-implementation-toolkit' ), [ 'errors' => $compiled->errors() ] );
		}

		$active_version_id = $blueprint['active_version_id'];
		$active_artifacts = $active_version_id ? $this->artifacts->for_version( $active_version_id ) : [];
		$active_bindings = $active_version_id ? $this->bindings->for_version( $active_version_id ) : [];
		$impact = $this->impact_planner->plan( $active_artifacts, $active_bindings, $compiled->artifacts(), $compiled->bindings() );
		$authority_blockers = array_merge( $this->publication->blockers( $blueprint ), $this->ownership->blockers( $blueprint_id, $compiled->artifacts() ) );
		if ( $authority_blockers ) {
			$impact['blockers'] = array_merge( $impact['blockers'], $authority_blockers );
			$impact['blocked'] = true;
		}
		$impact['map'] = $this->impact_map->build( $blueprint['draft_document'], $impact );
		$legacy_authority = null;
		if ( ! $impact['blocked'] && method_exists( $this->migration_guard, 'snapshot' ) ) {
			$legacy_authority = $this->migration_guard->snapshot( $blueprint );
			if ( is_wp_error( $legacy_authority ) ) {
				return $legacy_authority;
			}
		}
		$token = bin2hex( random_bytes( 32 ) );
		$record_data = [
			'id' => Uuid::v4(),
			'blueprint_id' => $blueprint_id,
			'from_version_id' => $active_version_id,
			'draft_checksum' => $blueprint['draft_checksum'],
			'status' => $impact['blocked'] ? 'blocked' : 'prepared',
			'impact' => $impact,
			'compiled_artifacts' => [
				'artifacts' => $compiled->artifacts(),
				'bindings' => $compiled->bindings(),
				'compiler_checksum' => $compiled->checksum(),
				'legacy_authority' => $legacy_authority,
			],
			'confirmation_hash' => hash( 'sha256', $token ),
			'created_by' => $user_id,
		];
		$record = $this->transaction->run(
			function () use ( $record_data, $impact, $blueprint_id, $active_version_id ) {
				$created = null;
				$candidates = [];
				if ( ! $impact['blocked'] ) {
					$candidates = $this->change_sets->prepared_candidates_for_draft( $blueprint_id, $active_version_id, $record_data['draft_checksum'] );
					if ( is_wp_error( $candidates ) ) {
						return $candidates;
					}
					foreach ( $candidates as $existing ) {
						if ( $this->same_preparation( $existing, $record_data ) ) {
							$created = $this->change_sets->rotate_confirmation( $existing['id'], $existing['confirmation_hash'], $record_data['confirmation_hash'], $record_data['created_by'] );
							break;
						}
					}
				}
				if ( ! $created ) {
					foreach ( $candidates as $candidate ) {
						if ( ! empty( $candidate['impact']['migration_plan']['operations'] ) ) {
							return new \WP_Error(
								'eit_migration_plan_supersession_required',
								__( 'This prepared migration plan owns durable reservations and cannot be superseded automatically.', 'elementor-implementation-toolkit' ),
								[ 'status' => 409, 'change_set_id' => (string) ( $candidate['id'] ?? '' ), 'reprepare_required' => true ]
							);
						}
					}
				}
				$created = $created ?: $this->change_sets->create( $record_data );
				if ( is_wp_error( $created ) || 'prepared' !== ( $created['status'] ?? '' ) ) {
					return $created;
				}
				$operations = $impact['migration_plan']['operations'] ?? [];
				if ( ! $operations ) {
					return $created;
				}
				$reserved = $this->field_migrations->reserve( $blueprint_id, $created['id'], $operations );
				return is_wp_error( $reserved ) ? $reserved : $created;
			}
		);
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$record['confirmation_token'] = $impact['blocked'] ? null : $token;
		return $record;
	}

	public function apply( $change_set_id, $confirmation_token, $user_id = 0 ) {
		return $this->apply_service->apply( $change_set_id, $confirmation_token, $user_id );
	}

	public function reconcile( $change_set_id ) {
		return $this->reconciliation_service->reconcile( $change_set_id );
	}
	public function rollback( $blueprint_id, $target_version_id, $reason, $user_id = 0 ) {
		if ( 'legacy' === $target_version_id || 0 === $target_version_id || '0' === $target_version_id ) {
			return $this->legacy_rollback->rollback( $blueprint_id, $reason, $user_id );
		}
		$blueprint = $this->blueprints->get( $blueprint_id );
		$target = $this->versions->get( $target_version_id );
		if ( ! $this->valid_rollback_target( $blueprint, $target, $blueprint_id ) ) {
			return new \WP_Error( 'eit_rollback_target_invalid', __( 'Blueprint rollback target is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$preflight_version = $this->versions->get( $blueprint['active_version_id'] );
		$preflight_change_set = is_array( $preflight_version ) ? $this->change_sets->published_for_checksum( $blueprint_id, $preflight_version['checksum'] ?? '' ) : null;
		if ( is_wp_error( $preflight_change_set ) ) {
			return $preflight_change_set;
		}
		$integrity = is_array( $preflight_change_set ) ? $this->migration_ledger->validate( $preflight_change_set ) : true;
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
		}
		$ownership_resource = 'storage-ownership';
		$ownership_lock = $this->locks->acquire( $ownership_resource, $user_id, 900 );
		if ( is_wp_error( $ownership_lock ) ) {
			return $ownership_lock;
		}
		$resource = 'blueprint-' . $blueprint_id;
		$lock = null;
		$migration_leases = [];
		$migration_heartbeat = false;
		$heartbeat = null;
		try {
			$lock = $this->locks->acquire( $resource, $user_id, 900 );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
			$blueprint = $this->blueprints->get( $blueprint_id );
			$target = $this->versions->get( $target_version_id );
			if ( ! $this->valid_rollback_target( $blueprint, $target, $blueprint_id ) ) {
				return new \WP_Error( 'eit_rollback_target_invalid', __( 'Blueprint rollback target changed while acquiring its lock.', 'elementor-implementation-toolkit' ) );
			}
			$rolled_back_lineage = $this->change_sets->rolled_back_for_checksum( $blueprint_id, $target['checksum'] ?? '' );
			if ( is_wp_error( $rolled_back_lineage ) ) {
				return $rolled_back_lineage;
			}
			if ( $rolled_back_lineage && ! empty( $rolled_back_lineage['impact']['migration_plan']['operations'] ) ) {
				return new \WP_Error( 'eit_migration_reactivation_requires_explicit_flow', __( 'A rolled-back migrated version requires a new explicit migration activation flow.', 'elementor-implementation-toolkit' ) );
			}
			$from_version_id = $blueprint['active_version_id'];
			$active_version = $this->versions->get( $from_version_id );
			$migration_change_set = is_array( $active_version ) ? $this->change_sets->published_for_checksum( $blueprint_id, $active_version['checksum'] ?? '' ) : null;
			if ( is_wp_error( $migration_change_set ) ) {
				return $migration_change_set;
			}
			$integrity = is_array( $migration_change_set ) ? $this->migration_ledger->validate( $migration_change_set ) : true;
			if ( is_wp_error( $integrity ) ) {
				return $integrity;
			}
			$has_field_migrations = ! empty( $migration_change_set['impact']['migration_plan']['operations'] );
			if ( $has_field_migrations && (int) ( $migration_change_set['from_version_id'] ?? 0 ) !== (int) $target_version_id ) {
				return new \WP_Error( 'eit_migration_rollback_requires_previous_version', __( 'A destructive field migration can only roll back to its immediately preserved source version.', 'elementor-implementation-toolkit' ) );
			}
			$target_artifacts = $this->artifacts->for_version( $target_version_id );
			$active_artifacts = $this->artifacts->for_version( $from_version_id );
			$storage_scopes = LifecycleLeaseGuard::storage_scopes( array_merge( $active_artifacts, $target_artifacts ) );
			$heartbeat = new LifecycleLeaseGuard( $this->locks, [ $ownership_resource => $ownership_lock, $resource => $lock ] );
			if ( $has_field_migrations ) {
				$migration_leases = $this->migration_locks->acquire( $blueprint_id, $migration_change_set['id'], $user_id, $this->field_migrations, [ $ownership_resource => $ownership_lock, $resource => $lock ], $storage_scopes );
				if ( is_wp_error( $migration_leases ) ) {
					return $migration_leases;
				}
				$migration_heartbeat = true;
			} else {
				$migration_leases = $this->migration_locks->acquire_scopes( $storage_scopes, $user_id );
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
			$run = $this->runs->start( $blueprint_id, 'rollback', null, [ 'from_version_id' => $from_version_id, 'to_version_id' => $target_version_id, 'reason_checksum' => hash( 'sha256', (string) $reason ) ] );
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			$pulse = $heartbeat->pulse();
			if ( is_wp_error( $pulse ) ) {
				return $this->fail_rollback( $run, $pulse );
			}
			$compiled = $this->compiler->compile( $target['document'] );
			$compiled_artifacts = $compiled->artifacts();
			$canary = $compiled->is_valid() ? $this->verify_artifact_checksums( $compiled_artifacts, $target_artifacts ) : new \WP_Error( 'eit_rollback_compile_failed', __( 'Rollback target no longer compiles against the current runtime.', 'elementor-implementation-toolkit' ), [ 'errors' => $compiled->errors() ] );
			if ( is_wp_error( $canary ) ) {
				return $this->fail_rollback( $run, $canary );
			}
			$pulse = $heartbeat->pulse();
			if ( is_wp_error( $pulse ) ) {
				return $this->fail_rollback( $run, $pulse );
			}
			$owned = $this->ownership->validate( $blueprint_id, $target_artifacts );
			if ( is_wp_error( $owned ) ) {
				return $this->fail_rollback( $run, $owned );
			}
			$pulse = $heartbeat->pulse();
			if ( is_wp_error( $pulse ) ) {
				return $this->fail_rollback( $run, $pulse );
			}
			$prepared = $this->preparer->prepare( $target_artifacts, [ 'operation' => 'rollback' ] );
			if ( is_wp_error( $prepared ) ) {
				return $this->fail_rollback( $run, $prepared );
			}
			$pulse = $heartbeat->pulse();
			if ( is_wp_error( $pulse ) ) {
				return $this->fail_rollback( $run, $pulse );
			}
			if ( $has_field_migrations ) {
				$migration_ready = $this->transaction->run(
					function () use ( $heartbeat, $blueprint_id, $migration_change_set ) {
						$pulse = $heartbeat->pulse();
						if ( is_wp_error( $pulse ) ) {
							return $pulse;
						}
						$ready = $this->field_migrations->prepare_rollback( $blueprint_id, $migration_change_set['id'] );
						if ( is_wp_error( $ready ) ) {
							return $ready;
						}
						$pulse = $heartbeat->pulse();
						return is_wp_error( $pulse ) ? $pulse : $ready;
					}
				);
				if ( is_wp_error( $migration_ready ) ) {
					return $this->fail_rollback( $run, $migration_ready );
				}
			}
			$result = $this->transaction->run(
				function () use ( $blueprint, $blueprint_id, $from_version_id, $target_version_id, $reason, $user_id, $compiled_artifacts, $has_field_migrations, $migration_change_set, $heartbeat ) {
					$pulse = $heartbeat->pulse();
					if ( is_wp_error( $pulse ) ) {
						return $pulse;
					}
					$activated = $this->blueprints->activate_if_current( $blueprint_id, $target_version_id, $from_version_id, $blueprint['draft_checksum'] ?? '', $blueprint['draft_revision'] ?? null );
					if ( is_wp_error( $activated ) ) {
						return $activated;
					}
					$pulse = $heartbeat->pulse();
					if ( is_wp_error( $pulse ) ) {
						return $pulse;
					}
					$verified = $this->verify_rollback_activation( $blueprint_id, $target_version_id, $compiled_artifacts );
					if ( is_wp_error( $verified ) ) {
						return $verified;
					}
					$pulse = $heartbeat->pulse();
					if ( is_wp_error( $pulse ) ) {
						return $pulse;
					}
					if ( $has_field_migrations ) {
						$migrations = $this->field_migrations->mark_rolled_back( $blueprint_id, $migration_change_set['id'] );
						if ( is_wp_error( $migrations ) ) {
							return $migrations;
						}
						$change_set = $this->change_sets->transition( $migration_change_set['id'], $migration_change_set['status'], 'rolled_back' );
						if ( is_wp_error( $change_set ) ) {
							return $change_set;
						}
					}
					$recorded = $this->rollbacks->record( $blueprint_id, $from_version_id, $target_version_id, $reason, $user_id );
					if ( is_wp_error( $recorded ) ) {
						return $recorded;
					}
					$pulse = $heartbeat->pulse();
					return is_wp_error( $pulse ) ? $pulse : $recorded;
				}
			);
			if ( is_wp_error( $result ) ) {
				return $this->fail_rollback( $run, $result );
			}
			$this->cache->set( $blueprint_id, $target_version_id, $target_artifacts );
			$this->run_events->append( $run['id'], 'version_reactivated', [ 'from_version_id' => $from_version_id, 'to_version_id' => $target_version_id, 'artifact_count' => count( $target_artifacts ) ] );
			$this->runs->finish( $run['id'], 'succeeded' );
			RuntimeDefinitionProvider::invalidate();
			return $result;
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

	private function valid_rollback_target( $blueprint, $target, $blueprint_id ) {
		return is_array( $blueprint ) && is_array( $target ) && (string) ( $target['blueprint_id'] ?? '' ) === (string) $blueprint_id && ! empty( $blueprint['active_version_id'] ) && is_array( $target['document'] ?? null );
	}

	private function same_preparation( array $existing, array $candidate ) {
		$existing_authority = [ 'impact' => $existing['impact'] ?? [], 'compiled_artifacts' => $existing['compiled_artifacts'] ?? [] ];
		$candidate_authority = [ 'impact' => $candidate['impact'] ?? [], 'compiled_artifacts' => $candidate['compiled_artifacts'] ?? [] ];
		return (int) ( $existing['from_version_id'] ?? 0 ) === (int) ( $candidate['from_version_id'] ?? 0 )
			&& hash_equals( (string) ( $existing['draft_checksum'] ?? '' ), (string) ( $candidate['draft_checksum'] ?? '' ) )
			&& hash_equals( $this->canonicalizer->checksum( $existing_authority ), $this->canonicalizer->checksum( $candidate_authority ) );
	}

	private function verify_rollback_activation( $blueprint_id, $target_version_id, array $expected_artifacts ) {
		$blueprint = $this->blueprints->get( $blueprint_id );
		if ( ! $blueprint || (int) $target_version_id !== (int) ( $blueprint['active_version_id'] ?? 0 ) ) {
			return new \WP_Error( 'eit_rollback_activation_mismatch', __( 'Rollback did not persist the requested active version.', 'elementor-implementation-toolkit' ) );
		}
		return $this->verify_artifact_checksums( $expected_artifacts, $this->artifacts->for_version( $target_version_id ) );
	}

	private function verify_artifact_checksums( array $expected_artifacts, array $actual_artifacts ) {
		$expected = array_column( $expected_artifacts, 'checksum', 'id' );
		$actual = array_column( $actual_artifacts, 'checksum', 'id' );
		ksort( $expected );
		ksort( $actual );
		return $expected === $actual ? true : new \WP_Error( 'eit_rollback_artifact_mismatch', __( 'Rollback target artifacts differ from the current compiler output.', 'elementor-implementation-toolkit' ) );
	}

	private function fail_rollback( array $run, $error ) {
		$this->runs->finish( $run['id'], 'failed', $error );
		return $error;
	}
}
