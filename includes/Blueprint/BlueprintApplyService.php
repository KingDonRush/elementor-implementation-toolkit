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
	private $preparer;
	private $blueprints;
	private $versions;
	private $artifacts;
	private $bindings;
	private $change_sets;
	private $locks;
	private $runs;
	private $run_events;
	private $cache;
	private $transaction;

	public function __construct( array $dependencies ) {
		foreach ( [ 'publication', 'ownership', 'preparer', 'blueprints', 'versions', 'artifacts', 'bindings', 'change_sets', 'locks', 'runs', 'run_events', 'cache', 'transaction' ] as $dependency ) {
			$this->{$dependency} = $dependencies[ $dependency ];
		}
		$this->claims = $dependencies['claims'] ?? new StorageClaimStore();
	}

	public function apply( $change_set_id, $confirmation_token, $user_id = 0 ) {
		$change_set = $this->change_sets->get( $change_set_id );
		if ( ! $change_set || ! in_array( $change_set['status'] ?? '', [ 'prepared', 'applying' ], true ) ) {
			return new \WP_Error( 'eit_change_set_not_prepared', __( 'Blueprint change set is neither prepared nor recoverable.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! hash_equals( $change_set['confirmation_hash'], hash( 'sha256', (string) $confirmation_token ) ) ) {
			return new \WP_Error( 'eit_change_set_confirmation_invalid', __( 'Blueprint publication confirmation is invalid.', 'elementor-implementation-toolkit' ) );
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
		try {
			$authorized = $this->publication->authorize_locked( $change_set_id, $confirmation_token, $change_set['blueprint_id'] );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}
			$change_set = $authorized['change_set'];
			return $this->apply_owned( $change_set_id, $change_set, $authorized['blueprint'], $user_id, $run, $claim_records );
		} catch ( \Throwable $error ) {
			$failure = new \WP_Error( 'eit_blueprint_apply_exception', __( 'Blueprint publication stopped after an unexpected runtime failure.', 'elementor-implementation-toolkit' ) );
			$this->fail_claims( $change_set, $change_set_id, $claim_records, $failure );
			$current = $this->change_sets->get( $change_set_id );
			if ( 'applying' === ( $current['status'] ?? '' ) ) {
				$this->change_sets->transition( $change_set_id, 'applying', 'failed' );
			}
			if ( is_array( $run ) && ! empty( $run['id'] ) ) {
				$this->runs->finish( $run['id'], 'failed', $failure );
			}
			do_action( 'eit_blueprint_apply_failed', $change_set['blueprint_id'], get_class( $error ) );
			return $failure;
		} finally {
			$this->locks->release( $resource, $lock );
			$this->locks->release( $ownership_resource, $ownership_lock );
		}
	}

	private function apply_owned( $change_set_id, array $change_set, array $blueprint, $user_id, &$run, array &$claim_records ) {
		$owned = $this->ownership->validate( $change_set['blueprint_id'], $change_set['compiled_artifacts']['artifacts'] );
		if ( is_wp_error( $owned ) ) {
			return $owned;
		}

		$recovering = 'applying' === ( $change_set['status'] ?? '' );
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

		$claim_records = $this->claims->claim_many( $change_set['blueprint_id'], $change_set_id, $change_set['compiled_artifacts']['artifacts'] );
		if ( is_wp_error( $claim_records ) ) {
			return $this->fail_apply( $change_set_id, $run, $claim_records );
		}
		$this->run_events->append( $run['id'], 'storage_claimed', [ 'count' => count( $claim_records ), 'recovery' => $recovering ] );

		$prepare_started = microtime( true );
		$prepared = $this->preparer->prepare( $change_set['compiled_artifacts']['artifacts'], [ 'change_set_id' => $change_set_id ] );
		$this->run_events->append( $run['id'], 'runtime_prepared', [ 'ok' => ! is_wp_error( $prepared ) ], ( microtime( true ) - $prepare_started ) * 1000 );
		if ( is_wp_error( $prepared ) ) {
			$this->fail_claims( $change_set, $change_set_id, $claim_records, $prepared );
			return $this->fail_apply( $change_set_id, $run, $prepared );
		}
		$storage_prepared = $claim_records ? $this->claims->mark_prepared( $change_set['blueprint_id'], $change_set_id, $this->claim_hashes( $claim_records ) ) : [];
		if ( is_wp_error( $storage_prepared ) ) {
			$this->fail_claims( $change_set, $change_set_id, $claim_records, $storage_prepared );
			return $this->fail_apply( $change_set_id, $run, $storage_prepared );
		}
		$this->run_events->append( $run['id'], 'storage_prepared', [ 'count' => count( $claim_records ) ] );

		$result = $this->persist_version( $blueprint, $change_set, $change_set_id );
		if ( is_wp_error( $result ) ) {
			$this->run_events->append( $run['id'], 'transaction_failed', [ 'error' => [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ] ] );
			return $this->fail_apply( $change_set_id, $run, $result );
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

	private function persist_version( array $blueprint, array $change_set, $change_set_id ) {
		return $this->transaction->run(
			function () use ( $blueprint, $change_set, $change_set_id ) {
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
				$activated = $this->blueprints->activate_if_current( $change_set['blueprint_id'], $version['id'], $change_set['from_version_id'], $change_set['draft_checksum'] );
				if ( is_wp_error( $activated ) ) {
					return $activated;
				}
				$transitioned = $this->change_sets->transition( $change_set_id, 'applying', 'applied', [ 'applied_at' => current_time( 'mysql', true ) ] );
				return is_wp_error( $transitioned ) ? $transitioned : $version;
			}
		);
	}

	private function fail_apply( $change_set_id, array $run, $error ) {
		$this->change_sets->transition( $change_set_id, 'applying', 'failed' );
		$this->runs->finish( $run['id'], 'failed', $error );
		return $error;
	}

	private function fail_claims( array $change_set, $change_set_id, $claim_records, $error ) {
		if ( is_array( $claim_records ) && $claim_records ) {
			$this->claims->mark_failed( $change_set['blueprint_id'], $change_set_id, is_wp_error( $error ) ? $error->get_error_code() : 'eit_storage_prepare_failed', $this->claim_hashes( $claim_records ) );
		}
	}

	private function claim_hashes( array $claims ) {
		return array_values( array_filter( array_column( $claims, 'identity_hash' ) ) );
	}
}
