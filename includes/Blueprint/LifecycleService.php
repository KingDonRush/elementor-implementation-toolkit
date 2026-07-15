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
	private $preparer;
	private $blueprints;
	private $versions;
	private $artifacts;
	private $bindings;
	private $change_sets;
	private $locks;
	private $runs;
	private $reconciliations;
	private $rollbacks;
	private $cache;
	private $transaction;

	public function __construct( array $dependencies = [] ) {
		$this->validator = $dependencies['validator'] ?? new BlueprintValidator();
		$this->canonicalizer = $dependencies['canonicalizer'] ?? new Canonicalizer();
		$this->compiler = $dependencies['compiler'] ?? new Compiler();
		$this->impact_planner = $dependencies['impact_planner'] ?? new ImpactPlanner();
		$this->preparer = $dependencies['preparer'] ?? new RuntimeArtifactPreparer();
		$this->blueprints = $dependencies['blueprints'] ?? new BlueprintStore();
		$this->versions = $dependencies['versions'] ?? new VersionStore();
		$this->artifacts = $dependencies['artifacts'] ?? new ArtifactStore();
		$this->bindings = $dependencies['bindings'] ?? new BindingStore();
		$this->change_sets = $dependencies['change_sets'] ?? new ChangeSetStore();
		$this->locks = $dependencies['locks'] ?? new LockStore();
		$this->runs = $dependencies['runs'] ?? new RunStore();
		$this->reconciliations = $dependencies['reconciliations'] ?? new ReconciliationStore();
		$this->rollbacks = $dependencies['rollbacks'] ?? new RollbackStore();
		$this->cache = $dependencies['cache'] ?? new RuntimeCache();
		$this->transaction = $dependencies['transaction'] ?? new Transaction();
	}

	public function save_draft( array $document ) {
		unset( $document['checksum'] );
		$validation = $this->validator->validate( $document );
		if ( ! $validation->is_valid() ) {
			return new \WP_Error( 'eit_blueprint_invalid', __( 'Blueprint draft is not valid.', 'elementor-implementation-toolkit' ), [ 'validation' => $validation->to_array() ] );
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
		$change_set = $this->change_sets->get( $change_set_id );
		if ( ! $change_set || 'prepared' !== $change_set['status'] ) {
			return new \WP_Error( 'eit_change_set_not_prepared', __( 'Blueprint change set is not prepared for publication.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! hash_equals( $change_set['confirmation_hash'], hash( 'sha256', (string) $confirmation_token ) ) ) {
			return new \WP_Error( 'eit_change_set_confirmation_invalid', __( 'Blueprint publication confirmation is invalid.', 'elementor-implementation-toolkit' ) );
		}

		$blueprint = $this->blueprints->get( $change_set['blueprint_id'] );
		if ( ! $blueprint || ! hash_equals( $change_set['draft_checksum'], (string) $blueprint['draft_checksum'] ) ) {
			return new \WP_Error( 'eit_change_set_stale', __( 'Blueprint draft changed after this impact plan was prepared.', 'elementor-implementation-toolkit' ) );
		}
		$resource = 'blueprint-' . $change_set['blueprint_id'];
		$lock = $this->locks->acquire( $resource, $user_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$transition = $this->change_sets->transition( $change_set_id, 'prepared', 'applying' );
		if ( is_wp_error( $transition ) ) {
			$this->locks->release( $resource, $lock );
			return $transition;
		}
		$run = $this->runs->start( $change_set['blueprint_id'], 'apply', $change_set_id, [ 'user_id' => $user_id, 'compiler_checksum' => $change_set['compiled_artifacts']['compiler_checksum'] ] );
		if ( is_wp_error( $run ) ) {
			$this->change_sets->transition( $change_set_id, 'applying', 'failed' );
			$this->locks->release( $resource, $lock );
			return $run;
		}

		$prepared = $this->preparer->prepare( $change_set['compiled_artifacts']['artifacts'], [ 'change_set_id' => $change_set_id ] );
		if ( is_wp_error( $prepared ) ) {
			$this->change_sets->transition( $change_set_id, 'applying', 'failed' );
			$this->runs->finish( $run['id'], 'failed', $prepared );
			$this->locks->release( $resource, $lock );
			return $prepared;
		}

		$result = $this->transaction->run(
			function () use ( $blueprint, $change_set, $change_set_id ) {
				$version = $this->versions->insert( $change_set['blueprint_id'], $blueprint['draft_document'], $change_set['draft_checksum'] );
				if ( is_wp_error( $version ) ) {
					return $version;
				}
				$stored_artifacts = $this->artifacts->insert_many( $change_set['blueprint_id'], $version['id'], $change_set['compiled_artifacts']['artifacts'] );
				if ( is_wp_error( $stored_artifacts ) ) {
					return $stored_artifacts;
				}
				$stored_bindings = $this->bindings->insert_many( $change_set['blueprint_id'], $version['id'], $change_set['compiled_artifacts']['bindings'] );
				if ( is_wp_error( $stored_bindings ) ) {
					return $stored_bindings;
				}
				$activated = $this->blueprints->set_active_version( $change_set['blueprint_id'], $version['id'] );
				if ( is_wp_error( $activated ) ) {
					return $activated;
				}
				$transitioned = $this->change_sets->transition( $change_set_id, 'applying', 'applied', [ 'applied_at' => current_time( 'mysql', true ) ] );
				return is_wp_error( $transitioned ) ? $transitioned : $version;
			}
		);

		if ( is_wp_error( $result ) ) {
			$this->change_sets->transition( $change_set_id, 'applying', 'failed' );
			$this->runs->finish( $run['id'], 'failed', $result );
		} else {
			$this->cache->set( $change_set['blueprint_id'], $result['id'], $change_set['compiled_artifacts']['artifacts'] );
			$this->runs->finish( $run['id'], 'succeeded' );
			RuntimeDefinitionProvider::invalidate();
		}
		$this->locks->release( $resource, $lock );
		return $result;
	}

	public function reconcile( $change_set_id ) {
		$change_set = $this->change_sets->get( $change_set_id );
		if ( ! $change_set || 'applied' !== $change_set['status'] ) {
			return new \WP_Error( 'eit_change_set_not_applied', __( 'Only an applied change set can be reconciled.', 'elementor-implementation-toolkit' ) );
		}
		$blueprint = $this->blueprints->get( $change_set['blueprint_id'] );
		$stored = $this->artifacts->for_version( $blueprint['active_version_id'] );
		$expected = array_column( $change_set['compiled_artifacts']['artifacts'], 'checksum', 'id' );
		$actual = array_column( $stored, 'checksum', 'id' );
		ksort( $expected );
		ksort( $actual );
		$status = $expected === $actual ? 'verified' : 'mismatch';
		$proof = $this->reconciliations->record( $change_set['blueprint_id'], $change_set_id, $status, [ 'expected' => count( $expected ), 'actual' => count( $actual ) ], [ 'expected' => hash( 'sha256', wp_json_encode( $expected ) ), 'actual' => hash( 'sha256', wp_json_encode( $actual ) ) ] );
		if ( is_wp_error( $proof ) || 'verified' !== $status ) {
			return is_wp_error( $proof ) ? $proof : new \WP_Error( 'eit_reconciliation_mismatch', __( 'Published artifacts do not match the prepared change set.', 'elementor-implementation-toolkit' ) );
		}
		return $this->change_sets->transition( $change_set_id, 'applied', 'reconciled' );
	}

	public function rollback( $blueprint_id, $target_version_id, $reason, $user_id = 0 ) {
		$blueprint = $this->blueprints->get( $blueprint_id );
		$target = $this->versions->get( $target_version_id );
		if ( ! $blueprint || ! $target || $target['blueprint_id'] !== $blueprint_id || ! $blueprint['active_version_id'] ) {
			return new \WP_Error( 'eit_rollback_target_invalid', __( 'Blueprint rollback target is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$resource = 'blueprint-' . $blueprint_id;
		$lock = $this->locks->acquire( $resource, $user_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$target_artifacts = $this->artifacts->for_version( $target_version_id );
		$prepared = $this->preparer->prepare( $target_artifacts, [ 'operation' => 'rollback' ] );
		if ( is_wp_error( $prepared ) ) {
			$this->locks->release( $resource, $lock );
			return $prepared;
		}
		$from_version_id = $blueprint['active_version_id'];
		$result = $this->transaction->run(
			function () use ( $blueprint_id, $from_version_id, $target_version_id, $reason, $user_id ) {
				$activated = $this->blueprints->set_active_version( $blueprint_id, $target_version_id );
				if ( is_wp_error( $activated ) ) {
					return $activated;
				}
				return $this->rollbacks->record( $blueprint_id, $from_version_id, $target_version_id, $reason, $user_id );
			}
		);
		if ( ! is_wp_error( $result ) ) {
			$this->cache->set( $blueprint_id, $target_version_id, $target_artifacts );
			RuntimeDefinitionProvider::invalidate();
		}
		$this->locks->release( $resource, $lock );
		return $result;
	}
}
