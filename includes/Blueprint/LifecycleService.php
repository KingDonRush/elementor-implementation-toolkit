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
	private $compiler;
	private $impact_planner;
	private $impact_map;
	private $publication;
	private $ownership;
	private $apply_service;
	private $preparer;
	private $blueprints;
	private $versions;
	private $artifacts;
	private $bindings;
	private $change_sets;
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
		$this->compiler = $dependencies['compiler'] ?? new Compiler();
		$this->impact_planner = $dependencies['impact_planner'] ?? new ImpactPlanner();
		$this->impact_map = $dependencies['impact_map'] ?? new ImpactMapBuilder();
		$this->blueprints = $dependencies['blueprints'] ?? new BlueprintStore();
		$this->versions = $dependencies['versions'] ?? new VersionStore();
		$this->artifacts = $dependencies['artifacts'] ?? new ArtifactStore();
		$this->bindings = $dependencies['bindings'] ?? new BindingStore();
		$this->change_sets = $dependencies['change_sets'] ?? new ChangeSetStore();
		$migrations = $dependencies['migration_guard'] ?? new MigrationPublicationGuard();
		$claims = $dependencies['claims'] ?? new \EIT\Infrastructure\StorageClaimStore();
		$this->publication = $dependencies['publication'] ?? new PublicationAuthority( $this->blueprints, $this->change_sets, $migrations );
		$this->ownership = $dependencies['ownership'] ?? new StorageOwnershipValidator( $this->blueprints, $this->artifacts, $migrations, $claims );
		$this->preparer = $dependencies['preparer'] ?? new RuntimeArtifactPreparer();
		$this->locks = $dependencies['locks'] ?? new LockStore();
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
				'claims' => $claims,
				'preparer' => $this->preparer,
				'blueprints' => $this->blueprints,
				'versions' => $this->versions,
				'artifacts' => $this->artifacts,
				'bindings' => $this->bindings,
				'change_sets' => $this->change_sets,
				'locks' => $this->locks,
				'runs' => $this->runs,
				'run_events' => $this->run_events,
				'cache' => $this->cache,
				'transaction' => $this->transaction,
			]
		);
	}

	public function save_draft( array $document ) {
		unset( $document['checksum'] );
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
		$token = bin2hex( random_bytes( 32 ) );
		$record = $this->change_sets->create(
			[
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
				],
				'confirmation_hash' => hash( 'sha256', $token ),
				'created_by' => $user_id,
			]
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
		$change_set = $this->change_sets->get( $change_set_id );
		if ( ! $change_set || 'applied' !== $change_set['status'] ) {
			return new \WP_Error( 'eit_change_set_not_applied', __( 'Only an applied change set can be reconciled.', 'elementor-implementation-toolkit' ) );
		}
		$blueprint = $this->blueprints->get( $change_set['blueprint_id'] );
		$run = $this->runs->start( $change_set['blueprint_id'], 'reconcile', $change_set_id, [ 'active_version_id' => $blueprint['active_version_id'] ?? null ] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$stored = $this->artifacts->for_version( $blueprint['active_version_id'] );
		$expected = array_column( $change_set['compiled_artifacts']['artifacts'], 'checksum', 'id' );
		$actual = array_column( $stored, 'checksum', 'id' );
		ksort( $expected );
		ksort( $actual );
		$status = $expected === $actual ? 'verified' : 'mismatch';
		$proof = $this->reconciliations->record( $change_set['blueprint_id'], $change_set_id, $status, [ 'expected' => count( $expected ), 'actual' => count( $actual ) ], [ 'expected' => hash( 'sha256', wp_json_encode( $expected ) ), 'actual' => hash( 'sha256', wp_json_encode( $actual ) ) ] );
		$this->run_events->append( $run['id'], 'artifacts_compared', [ 'status' => $status, 'expected_count' => count( $expected ), 'actual_count' => count( $actual ), 'expected_checksum' => hash( 'sha256', wp_json_encode( $expected ) ), 'actual_checksum' => hash( 'sha256', wp_json_encode( $actual ) ) ] );
		if ( is_wp_error( $proof ) || 'verified' !== $status ) {
			$error = is_wp_error( $proof ) ? $proof : new \WP_Error( 'eit_reconciliation_mismatch', __( 'Published artifacts do not match the prepared change set.', 'elementor-implementation-toolkit' ) );
			$this->runs->finish( $run['id'], 'failed', $error );
			return $error;
		}
		$result = $this->change_sets->transition( $change_set_id, 'applied', 'reconciled' );
		$this->runs->finish( $run['id'], is_wp_error( $result ) ? 'failed' : 'succeeded', is_wp_error( $result ) ? $result : null );
		return $result;
	}

	public function rollback( $blueprint_id, $target_version_id, $reason, $user_id = 0 ) {
		$blueprint = $this->blueprints->get( $blueprint_id );
		$target = $this->versions->get( $target_version_id );
		if ( ! $this->valid_rollback_target( $blueprint, $target, $blueprint_id ) ) {
			return new \WP_Error( 'eit_rollback_target_invalid', __( 'Blueprint rollback target is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$ownership_resource = 'storage-ownership';
		$ownership_lock = $this->locks->acquire( $ownership_resource, $user_id, 900 );
		if ( is_wp_error( $ownership_lock ) ) {
			return $ownership_lock;
		}
		$resource = 'blueprint-' . $blueprint_id;
		$lock = null;
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
			$from_version_id = $blueprint['active_version_id'];
			$run = $this->runs->start( $blueprint_id, 'rollback', null, [ 'from_version_id' => $from_version_id, 'to_version_id' => $target_version_id, 'reason_checksum' => hash( 'sha256', (string) $reason ) ] );
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			$target_artifacts = $this->artifacts->for_version( $target_version_id );
			$compiled = $this->compiler->compile( $target['document'] );
			$compiled_artifacts = $compiled->artifacts();
			$canary = $compiled->is_valid() ? $this->verify_artifact_checksums( $compiled_artifacts, $target_artifacts ) : new \WP_Error( 'eit_rollback_compile_failed', __( 'Rollback target no longer compiles against the current runtime.', 'elementor-implementation-toolkit' ), [ 'errors' => $compiled->errors() ] );
			if ( is_wp_error( $canary ) ) {
				return $this->fail_rollback( $run, $canary );
			}
			$owned = $this->ownership->validate( $blueprint_id, $target_artifacts );
			if ( is_wp_error( $owned ) ) {
				return $this->fail_rollback( $run, $owned );
			}
			$prepared = $this->preparer->prepare( $target_artifacts, [ 'operation' => 'rollback' ] );
			if ( is_wp_error( $prepared ) ) {
				return $this->fail_rollback( $run, $prepared );
			}
			$result = $this->transaction->run(
				function () use ( $blueprint_id, $from_version_id, $target_version_id, $reason, $user_id, $compiled_artifacts ) {
					$activated = $this->blueprints->set_active_version( $blueprint_id, $target_version_id );
					if ( is_wp_error( $activated ) ) {
						return $activated;
					}
					$verified = $this->verify_rollback_activation( $blueprint_id, $target_version_id, $compiled_artifacts );
					return is_wp_error( $verified ) ? $verified : $this->rollbacks->record( $blueprint_id, $from_version_id, $target_version_id, $reason, $user_id );
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
			if ( null !== $lock && ! is_wp_error( $lock ) ) {
				$this->locks->release( $resource, $lock );
			}
			$this->locks->release( $ownership_resource, $ownership_lock );
		}
	}

	private function valid_rollback_target( $blueprint, $target, $blueprint_id ) {
		return is_array( $blueprint ) && is_array( $target ) && (string) ( $target['blueprint_id'] ?? '' ) === (string) $blueprint_id && ! empty( $blueprint['active_version_id'] ) && is_array( $target['document'] ?? null );
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
