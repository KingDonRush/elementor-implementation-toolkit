<?php
/**
 * Restores verified legacy authority after a Blueprint's first activation.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\ChangeSetStore;
use EIT\Infrastructure\LockStore;
use EIT\Infrastructure\RollbackStore;
use EIT\Infrastructure\RunEventStore;
use EIT\Infrastructure\RunStore;
use EIT\Infrastructure\RuntimeCache;
use EIT\Infrastructure\Transaction;
use EIT\Infrastructure\VersionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyRollbackService {

	private $blueprints;
	private $versions;
	private $change_sets;
	private $snapshots;
	private $source_commit;
	private $ledger;
	private $locks;
	private $storage_gates;
	private $runs;
	private $run_events;
	private $rollbacks;
	private $cache;
	private $transaction;
	private $runtime_invalidator;

	public function __construct( array $dependencies = [] ) {
		$this->blueprints = $dependencies['blueprints'] ?? new BlueprintStore();
		$this->versions = $dependencies['versions'] ?? new VersionStore();
		$this->change_sets = $dependencies['change_sets'] ?? new ChangeSetStore();
		$this->snapshots = $dependencies['legacy_authority'] ?? new LegacyAuthoritySnapshot();
		$this->source_commit = $dependencies['source_commit'] ?? new LegacySourceCommitGuard( $this->snapshots );
		$this->ledger = $dependencies['migration_ledger'] ?? new MigrationLedgerValidator();
		$this->locks = $dependencies['locks'] ?? new LockStore();
		$this->storage_gates = $dependencies['storage_gates'] ?? new MigrationStorageLockCoordinator( null, $this->locks );
		$this->runs = $dependencies['runs'] ?? new RunStore();
		$this->run_events = $dependencies['run_events'] ?? new RunEventStore();
		$this->rollbacks = $dependencies['rollbacks'] ?? new RollbackStore();
		$this->cache = $dependencies['cache'] ?? new RuntimeCache();
		$this->transaction = $dependencies['transaction'] ?? new Transaction();
		$this->runtime_invalidator = $dependencies['runtime_invalidator'] ?? [ RuntimeDefinitionProvider::class, 'invalidate' ];
	}

	public function availability( $blueprint_id ) {
		$authority = $this->authority( $blueprint_id );
		return is_wp_error( $authority )
			? $authority
			: [ 'kind' => 'legacy', 'target' => 'legacy', 'label' => __( 'Legacy source', 'elementor-implementation-toolkit' ), 'source_type' => $authority['evidence']['source_type'] ];
	}

	public function rollback( $blueprint_id, $reason, $user_id = 0 ) {
		$preflight = $this->authority( $blueprint_id );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		$resources = [ 'storage-ownership', 'blueprint-' . $blueprint_id ];
		$leases = [];
		$storage_leases = [];
		try {
			foreach ( $resources as $resource ) {
				$lease = $this->locks->acquire( $resource, $user_id, 900 );
				if ( is_wp_error( $lease ) ) {
					return $lease;
				}
				$leases[ $resource ] = $lease;
			}
			$storage_leases = $this->storage_gates->acquire_storage( $preflight['storage_scope']['strategy'], $preflight['storage_scope']['storage_slug'], $user_id );
			if ( is_wp_error( $storage_leases ) ) {
				return $storage_leases;
			}

			$authority = $this->authority( $blueprint_id );
			if ( is_wp_error( $authority ) ) {
				return $authority;
			}
			if ( ! $this->same_authority( $preflight, $authority ) ) {
				return new \WP_Error( 'eit_legacy_rollback_authority_stale', __( 'Legacy rollback authority changed while its storage gate was acquired.', 'elementor-implementation-toolkit' ), [ 'status' => 409 ] );
			}

			$run = $this->runs->start(
				$blueprint_id,
				'rollback',
				$authority['change_set']['id'],
				[
					'from_version_id' => $authority['active_version_id'],
					'to_authority' => 'legacy_source',
					'to_version_id' => 0,
					'source_type' => $authority['evidence']['source_type'],
					'source_key' => $authority['evidence']['source_key'],
					'source_checksum' => $authority['evidence']['source_checksum'],
					'reason_checksum' => hash( 'sha256', (string) $reason ),
				]
			);
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			$renewed = $this->renew( $leases, $storage_leases );
			if ( is_wp_error( $renewed ) ) {
				$this->runs->finish( $run['id'], 'failed', $renewed );
				return $renewed;
			}
			$final_authority = $this->authority( $blueprint_id );
			if ( is_wp_error( $final_authority ) || ! $this->same_authority( $authority, $final_authority ) ) {
				$error = is_wp_error( $final_authority ) ? $final_authority : new \WP_Error( 'eit_legacy_rollback_authority_stale', __( 'Legacy rollback authority changed immediately before deactivation.', 'elementor-implementation-toolkit' ), [ 'status' => 409 ] );
				$this->runs->finish( $run['id'], 'failed', $error );
				return $error;
			}

			$result = $this->transaction->run(
				function () use ( $final_authority, $blueprint_id, $reason, $user_id ) {
					$source = $this->source_commit->validate_snapshot_and_lock( $final_authority['evidence'] );
					if ( is_wp_error( $source ) ) {
						return $source;
					}
					$deactivated = $this->blueprints->deactivate_if_current(
						$blueprint_id,
						$final_authority['active_version_id'],
						$final_authority['blueprint']['draft_checksum'],
						$final_authority['blueprint']['draft_revision']
					);
					if ( is_wp_error( $deactivated ) ) {
						return $deactivated;
					}
					$stored = $this->blueprints->get( $blueprint_id );
					if ( ! is_array( $stored ) || null !== ( $stored['active_version_id'] ?? null ) ) {
						return new \WP_Error( 'eit_legacy_rollback_activation_mismatch', __( 'Legacy authority was not restored after Blueprint deactivation.', 'elementor-implementation-toolkit' ) );
					}
					$lineage = $this->change_sets->transition( $final_authority['change_set']['id'], $final_authority['change_set']['status'], 'rolled_back' );
					if ( is_wp_error( $lineage ) ) {
						return $lineage;
					}
					return $this->rollbacks->record( $blueprint_id, $final_authority['active_version_id'], 0, $reason, $user_id );
				}
			);
			if ( is_wp_error( $result ) ) {
				$this->runs->finish( $run['id'], 'failed', $result );
				return $result;
			}

			$this->cache->invalidate( $blueprint_id, $authority['active_version_id'] );
			call_user_func( $this->runtime_invalidator );
			$this->run_events->append( $run['id'], 'legacy_authority_restored', [ 'from_version_id' => $authority['active_version_id'], 'to_version_id' => 0, 'artifacts_preserved' => true ] );
			$this->runs->finish( $run['id'], 'succeeded' );
			return $result;
		} finally {
			if ( $storage_leases ) {
				$this->storage_gates->release( $storage_leases );
			}
			foreach ( array_reverse( $leases, true ) as $resource => $lease ) {
				$this->locks->release( $resource, $lease );
			}
		}
	}

	private function authority( $blueprint_id ) {
		$blueprint = $this->blueprints->get( $blueprint_id );
		if ( ! is_array( $blueprint ) || empty( $blueprint['active_version_id'] ) ) {
			return new \WP_Error( 'eit_legacy_rollback_unavailable', __( 'Legacy source rollback is available only after an imported Blueprint\'s first activation.', 'elementor-implementation-toolkit' ) );
		}

		$active_version = $this->versions->get( $blueprint['active_version_id'] );
		if ( ! is_array( $active_version ) || (string) ( $active_version['blueprint_id'] ?? '' ) !== (string) $blueprint_id ) {
			return new \WP_Error( 'eit_legacy_rollback_version_invalid', __( 'Active Blueprint version is unavailable for legacy rollback.', 'elementor-implementation-toolkit' ) );
		}
		$change_set = $this->change_sets->published_for_checksum( $blueprint_id, $active_version['checksum'] ?? '' );
		if ( is_wp_error( $change_set ) ) {
			return $change_set;
		}
		if ( ! is_array( $change_set ) || null !== ( $change_set['from_version_id'] ?? null ) ) {
			return new \WP_Error( 'eit_legacy_rollback_not_first_activation', __( 'Legacy source rollback is limited to the Blueprint\'s first activation lineage.', 'elementor-implementation-toolkit' ) );
		}
		$integrity = $this->ledger->validate( $change_set );
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
		}
		$snapshot = $change_set['compiled_artifacts']['legacy_authority'] ?? null;
		if ( ! is_array( $snapshot ) ) {
			return new \WP_Error( 'eit_legacy_authority_snapshot_missing', __( 'First activation has no frozen legacy rollback authority.', 'elementor-implementation-toolkit' ), [ 'status' => 409 ] );
		}
		$published = $this->snapshots->validate_published_version( $snapshot, $active_version );
		if ( is_wp_error( $published ) ) {
			return $published;
		}
		$evidence = $published['snapshot'];
		if ( ! in_array( $evidence['source_type'] ?? '', [ 'cpt', 'cct' ], true ) ) {
			return new \WP_Error( 'eit_legacy_rollback_storage_unsupported', __( 'Legacy rollback requires a CPT or CCT source protected by Toolkit writer gates.', 'elementor-implementation-toolkit' ) );
		}
		$storage_resource = StorageMutationGuard::resource_key( $evidence['source_type'] ?? '', $evidence['source_key'] ?? '' );
		if ( '' === $storage_resource ) {
			return new \WP_Error( 'eit_legacy_rollback_storage_invalid', __( 'Legacy rollback storage authority is incomplete.', 'elementor-implementation-toolkit' ) );
		}
		$source = $this->source_commit->validate_snapshot_and_lock( $evidence );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		return [
			'blueprint' => $blueprint,
			'active_version_id' => (int) $blueprint['active_version_id'],
			'evidence' => $evidence,
			'change_set' => $change_set,
			'storage_resource' => $storage_resource,
			'storage_scope' => $published['storage_scope'],
		];
	}

	private function same_authority( array $before, array $after ) {
		return $before['active_version_id'] === $after['active_version_id']
			&& (string) $before['change_set']['id'] === (string) $after['change_set']['id']
			&& (string) $before['storage_resource'] === (string) $after['storage_resource']
			&& hash_equals( (string) $before['evidence']['authority_checksum'], (string) $after['evidence']['authority_checksum'] )
			&& (int) ( $before['blueprint']['draft_revision'] ?? 0 ) === (int) ( $after['blueprint']['draft_revision'] ?? 0 )
			&& hash_equals( (string) $before['blueprint']['draft_checksum'], (string) $after['blueprint']['draft_checksum'] );
	}

	private function renew( array $leases, array $storage_leases ) {
		foreach ( $leases as $resource => $token ) {
			$renewed = $this->locks->renew( $resource, $token, 900 );
			if ( is_wp_error( $renewed ) || true !== $renewed ) {
				return is_wp_error( $renewed ) ? $renewed : new \WP_Error( 'eit_legacy_rollback_lease_lost', __( 'Legacy rollback lock ownership expired before deactivation.', 'elementor-implementation-toolkit' ) );
			}
		}
		return $this->storage_gates->renew( $storage_leases );
	}
}
