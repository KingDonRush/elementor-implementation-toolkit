<?php
/**
 * WordPress integration verification for Blueprint infrastructure stores.
 *
 * Run with:
 * wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-blueprint-infrastructure.php
 */

use EIT\Blueprint\Canonicalizer;
use EIT\Blueprint\Uuid;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BindingStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\ChangeSetStore;
use EIT\Infrastructure\LockStore;
use EIT\Infrastructure\MigrationStore;
use EIT\Infrastructure\NormalizedValueStore;
use EIT\Infrastructure\ReconciliationStore;
use EIT\Infrastructure\RollbackStore;
use EIT\Infrastructure\RunEventStore;
use EIT\Infrastructure\RunStore;
use EIT\Infrastructure\RuntimeCache;
use EIT\Infrastructure\ScenarioStore;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\StorageClaimStore;
use EIT\Infrastructure\Tables;
use EIT\Infrastructure\Transaction;
use EIT\Infrastructure\VersionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assertions = 0;
$blueprint_id = Uuid::v4();
$transaction_blueprint_id = Uuid::v4();

$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$cleanup = function () use ( $blueprint_id, $transaction_blueprint_id ) {
	global $wpdb;

	$run_table = Tables::name( Tables::RUNS );
	$event_table = Tables::name( Tables::RUN_EVENTS );
	$run_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$run_table}` WHERE blueprint_id IN (%s,%s)", $blueprint_id, $transaction_blueprint_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $run_ids ?: [] as $run_id ) {
		$wpdb->delete( $event_table, [ 'run_id' => $run_id ] );
	}

	foreach ( Tables::keys() as $table_key ) {
		$table = Tables::name( $table_key );
		if ( Tables::LOCKS === $table_key ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE resource_key LIKE %s", '%' . $wpdb->esc_like( $blueprint_id ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			continue;
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( in_array( 'blueprint_id', $columns ?: [], true ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE blueprint_id IN (%s,%s)", $blueprint_id, $transaction_blueprint_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( in_array( 'id', $columns ?: [], true ) && Tables::BLUEPRINTS === $table_key ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id IN (%s,%s)", $blueprint_id, $transaction_blueprint_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
};

try {
	$cleanup();
	$assert( true === SchemaManager::install(), 'Infrastructure installation failed.' );
	$assert( true === SchemaManager::verify(), 'Infrastructure verification failed.' );
	$assert( count( Tables::keys() ) === 19, 'Dedicated infrastructure table count drifted.' );

	$document = [
		'api_version' => 'eit.dev/v1',
		'kind' => 'Blueprint',
		'id' => $blueprint_id,
		'slug' => 'qa-' . substr( str_replace( '-', '', $blueprint_id ), 0, 12 ),
		'name' => 'QA Blueprint Infrastructure',
		'version' => 1,
		'nodes' => [],
		'connections' => [],
	];
	$canonicalizer = new Canonicalizer();
	$checksum = $canonicalizer->checksum( $document );
	$blueprints = new BlueprintStore();
	$draft = $blueprints->save_draft( $document, $checksum );
	$assert( ! is_wp_error( $draft ) && 1 === $draft['draft_revision'], 'Draft insert failed.' );
	$draft = $blueprints->save_draft( $document, $checksum );
	$assert( ! is_wp_error( $draft ) && 2 === $draft['draft_revision'], 'Draft revision did not increment.' );

	$versions = new VersionStore();
	$first_version = $versions->insert( $blueprint_id, $document, $checksum );
	$same_version = $versions->insert( $blueprint_id, $document, $checksum );
	$assert( ! is_wp_error( $first_version ) && $first_version['id'] === $same_version['id'], 'Version insert is not idempotent.' );
	$document['version'] = 2;
	$document['name'] = 'QA Blueprint Infrastructure V2';
	$second_checksum = $canonicalizer->checksum( $document );
	$second_version = $versions->insert( $blueprint_id, $document, $second_checksum );
	$assert( 2 === $second_version['version'], 'Immutable version sequence is invalid.' );

	$artifact_payload = [ 'strategy' => 'cct', 'slug' => $document['slug'] ];
	$artifact = [
		'id' => hash( 'sha256', $blueprint_id . '|storage' ),
		'node_id' => Uuid::v4(),
		'kind' => 'storage_contract',
		'checksum' => hash( 'sha256', wp_json_encode( $artifact_payload ) ),
		'payload' => $artifact_payload,
	];
	$artifacts = new ArtifactStore();
	$assert( true === $artifacts->insert( $blueprint_id, $first_version['id'], $artifact ), 'Artifact insert failed.' );
	$assert( true === $artifacts->insert( $blueprint_id, $first_version['id'], $artifact ), 'Artifact retry is not idempotent.' );
	$collision = $artifact;
	$collision['checksum'] = str_repeat( '0', 64 );
	$assert( is_wp_error( $artifacts->insert( $blueprint_id, $first_version['id'], $collision ) ), 'Artifact collision was not blocked.' );

	$field_id = Uuid::v4();
	$bindings = new BindingStore();
	$binding_result = $bindings->insert_many(
		$blueprint_id,
		$first_version['id'],
		[ [ 'field_id' => $field_id, 'entity_id' => $artifact['node_id'], 'adapter' => 'cct', 'storage_key' => 'eit_price', 'aliases' => [ 'legacy_price' ], 'migration' => null ] ]
	);
	$stored_binding = $bindings->get_checked( $first_version['id'], $field_id );
	$assert( true === $binding_result && ! is_wp_error( $stored_binding ) && [ 'legacy_price' ] === $stored_binding['aliases'] && $artifact['node_id'] === $stored_binding['entity_id'], 'Complete stable binding payload failed.' );

	$change_set_id = Uuid::v4();
	$change_sets = new ChangeSetStore();
	$change_set = $change_sets->create(
		[
			'id' => $change_set_id,
			'blueprint_id' => $blueprint_id,
			'from_version_id' => $first_version['id'],
			'draft_checksum' => $second_checksum,
			'impact' => [ 'changed' => 1 ],
			'compiled_artifacts' => [ $artifact ],
			'confirmation_hash' => hash( 'sha256', 'confirmation' ),
		]
	);
	$assert( ! is_wp_error( $change_set ) && 'prepared' === $change_set['status'], 'Change set preparation failed.' );
	$assert( ! is_wp_error( $change_sets->transition( $change_set_id, 'prepared', 'applying' ) ), 'Change set transition failed.' );
	$assert( is_wp_error( $change_sets->transition( $change_set_id, 'prepared', 'applied' ) ), 'Stale change set transition was not blocked.' );

	$claim_slug = 'claim_' . substr( str_replace( '-', '', $blueprint_id ), 0, 12 );
	$cpt_claim_slug = substr( $claim_slug, 0, 20 );
	$claim_artifact = function ( $strategy, $slug ) use ( $blueprint_id ) {
		$payload = [ 'strategy' => $strategy, 'definition' => [ 'slug' => $slug ] ];
		return [
			'id' => hash( 'sha256', $blueprint_id . '|claim|' . $strategy . '|' . $slug ),
			'kind' => 'entity_definition',
			'checksum' => hash( 'sha256', wp_json_encode( $payload ) ),
			'payload' => $payload,
		];
	};
	$claim_artifacts = [ $claim_artifact( 'cpt', $cpt_claim_slug ), $claim_artifact( 'cct', $claim_slug ) ];
	$storage_claims = new StorageClaimStore();
	$claimed = $storage_claims->claim_many( $blueprint_id, $change_set_id, $claim_artifacts );
	$assert( ! is_wp_error( $claimed ) && 2 === count( $claimed ), 'Core Entity storage identities were not claimed atomically.' );
	$assert( ! array_filter( $claimed, fn( $record ) => 'claimed' !== $record['status'] || $record['existed_before'] ), 'A new storage identity claim has invalid provenance.' );
	$idempotent_claim = $storage_claims->claim_many( $blueprint_id, $change_set_id, $claim_artifacts );
	$assert( ! is_wp_error( $idempotent_claim ) && array_column( $claimed, 'identity_hash' ) === array_column( $idempotent_claim, 'identity_hash' ), 'Storage claim retry is not idempotent.' );

	$changed_artifacts = $claim_artifacts;
	$changed_artifacts[0]['checksum'] = str_repeat( 'a', 64 );
	$changed_claim = $storage_claims->claim_many( $blueprint_id, $change_set_id, $changed_artifacts );
	$assert( is_wp_error( $changed_claim ) && 'eit_storage_claim_artifact_mismatch' === $changed_claim->get_error_code(), 'A change set reused its storage claim with different compiled content.' );

	$other_change_set_id = Uuid::v4();
	$other_change_set = $change_sets->create(
		[
			'id' => $other_change_set_id,
			'blueprint_id' => $transaction_blueprint_id,
			'draft_checksum' => $checksum,
			'impact' => [],
			'compiled_artifacts' => [ 'artifacts' => $claim_artifacts ],
			'confirmation_hash' => hash( 'sha256', $other_change_set_id ),
		]
	);
	$assert( ! is_wp_error( $other_change_set ) && ! is_wp_error( $change_sets->transition( $other_change_set_id, 'prepared', 'applying' ) ), 'Competing claim fixture could not enter applying state.' );
	$conflict_artifact = StorageClaimStore::identity_hash( 'cpt', $cpt_claim_slug ) > StorageClaimStore::identity_hash( 'cct', $claim_slug ) ? $claim_artifacts[0] : $claim_artifacts[1];
	$conflict_hash = StorageClaimStore::identity_hash( $conflict_artifact['payload']['strategy'], $conflict_artifact['payload']['definition']['slug'] );
	$atomic_slug = '';
	for ( $candidate = 0; $candidate < 512; ++$candidate ) {
		$proposed_slug = 'atomic_' . $candidate . '_' . substr( str_replace( '-', '', $blueprint_id ), 0, 8 );
		if ( StorageClaimStore::identity_hash( 'cct', $proposed_slug ) < $conflict_hash ) {
			$atomic_slug = $proposed_slug;
			break;
		}
	}
	$assert( '' !== $atomic_slug, 'Atomic claim fixture could not order its insert before the owned identity.' );
	$foreign_claim = $storage_claims->claim_many( $transaction_blueprint_id, $other_change_set_id, [ $claim_artifact( 'cct', $atomic_slug ), $conflict_artifact ] );
	$assert( is_wp_error( $foreign_claim ) && 'eit_storage_claim_conflict' === $foreign_claim->get_error_code() && null === $storage_claims->owner( 'cct', $atomic_slug ), 'A conflicting multi-claim retained its earlier partial insert.' );

	$failed_claims = $storage_claims->mark_failed( $blueprint_id, $change_set_id, 'eit_qa_storage_failure' );
	$assert( ! is_wp_error( $failed_claims ) && ! array_filter( $failed_claims, fn( $record ) => 'failed' !== $record['status'] || 'eit_qa_storage_failure' !== $record['failure_code'] ), 'Failed storage preparation was not retained durably.' );
	$next_change_set_id = Uuid::v4();
	$next_change_set = $change_sets->create(
		[
			'id' => $next_change_set_id,
			'blueprint_id' => $blueprint_id,
			'draft_checksum' => $checksum,
			'impact' => [],
			'compiled_artifacts' => [ 'artifacts' => $claim_artifacts ],
			'confirmation_hash' => hash( 'sha256', $next_change_set_id ),
		]
	);
	$assert( ! is_wp_error( $next_change_set ) && ! is_wp_error( $change_sets->transition( $next_change_set_id, 'prepared', 'applying' ) ), 'Retry claim fixture could not enter applying state.' );
	$reclaimed = $storage_claims->claim_many( $blueprint_id, $next_change_set_id, $claim_artifacts );
	$assert( ! is_wp_error( $reclaimed ) && ! array_filter( $reclaimed, fn( $record ) => 'claimed' !== $record['status'] || $record['existed_before'] ), 'The owning Blueprint could not reclaim failed storage without losing original provenance.' );
	$prepared_claims = $storage_claims->mark_prepared( $blueprint_id, $next_change_set_id );
	$owner = $storage_claims->owner( 'cct', $claim_slug );
	$assert( ! is_wp_error( $prepared_claims ) && 'prepared' === $owner['status'] && $next_change_set_id === $owner['change_set_id'], 'Prepared storage ownership was not persisted.' );
	$assert( is_wp_error( $storage_claims->release( $blueprint_id, $next_change_set_id ) ), 'Prepared storage ownership was released without reconciliation.' );

	$release_slug = substr( 'release_' . str_replace( '-', '', $blueprint_id ), 0, 20 );
	$release_artifact = $claim_artifact( 'cpt', $release_slug );
	$release_claim = $storage_claims->claim_many( $blueprint_id, $next_change_set_id, [ $release_artifact ] );
	$release_hash = StorageClaimStore::identity_hash( 'cpt', $release_slug );
	register_post_type( $release_slug );
	$assert( is_wp_error( $storage_claims->release( $blueprint_id, $next_change_set_id, [ $release_hash ] ) ), 'A claim was released after its previously absent storage appeared.' );
	unregister_post_type( $release_slug );
	$assert( ! is_wp_error( $release_claim ) && true === $storage_claims->release( $blueprint_id, $next_change_set_id, [ $release_hash ] ) && null === $storage_claims->owner( 'cpt', $release_slug ), 'An untouched storage claim could not be released safely.' );
	$assert( ! \EIT\CCT\SchemaManager::table_exists( $claim_slug ) && ! post_type_exists( $cpt_claim_slug ), 'Storage claim bookkeeping created or removed external storage.' );

	$locks = new LockStore();
	$resource = 'blueprint-' . $blueprint_id;
	$lock_token = $locks->acquire( $resource, 1 );
	$assert( is_string( $lock_token ) && is_wp_error( $locks->acquire( $resource, 2 ) ), 'Exclusive Blueprint lock failed.' );
	$assert( is_wp_error( $locks->renew( $resource, 'stale-token' ) ) && true === $locks->is_active( $resource ), 'A stale token renewed or hid an active lock.' );
	$assert( true === $locks->renew( $resource, $lock_token ) && false === $locks->release( $resource, 'stale-token' ), 'Lock renewal or compare-and-swap ownership failed.' );
	$assert( true === $locks->release( $resource, $lock_token ), 'Blueprint lock release failed.' );

	$runs = new RunStore();
	$run = $runs->start( $blueprint_id, 'compile', $change_set_id, [ 'token' => 'never-store-me', 'count' => 2 ] );
	$assert( ! is_wp_error( $run ) && '[redacted]' === $run['context']['token'], 'Run context redaction failed.' );
	$event = ( new RunEventStore() )->append( $run['id'], 'query_plan', [ 'authorization' => 'never-store-me', 'content' => 'private payload', 'queries' => 2 ], 4.25 );
	$run_with_events = $runs->with_events( $run['id'] );
	$assert( ! is_wp_error( $event ) && '[redacted]' === $run_with_events['events'][0]['payload']['authorization'], 'Flight Recorder event redaction failed.' );
	$assert( true === $run_with_events['events'][0]['payload']['content']['redacted'] && 4.25 === $run_with_events['events'][0]['duration_ms'], 'Flight Recorder content summary or timing failed.' );
	$assert( 'succeeded' === $runs->finish( $run['id'], 'succeeded' )['status'], 'Run completion failed.' );

	$migration = ( new MigrationStore() )->save( [ 'source_type' => 'qa', 'source_key' => $blueprint_id, 'blueprint_id' => $blueprint_id, 'source_checksum' => $checksum, 'draft_checksum' => $checksum, 'status' => 'verified', 'comparison' => [ 'checks' => [ 'count' => [ 'match' => true ] ] ] ] );
	$assert( ! is_wp_error( $migration ) && 'verified' === $migration['status'], 'Migration evidence store failed.' );
	$scenario_store = new ScenarioStore();
	$scenario = $scenario_store->save( [ 'blueprint_id' => $blueprint_id, 'name' => 'Infrastructure replay', 'kind' => 'migration_shadow', 'request' => [ 'source_type' => 'qa', 'source_key' => $blueprint_id ], 'expected' => [ 'status' => 'verified' ] ] );
	$assert( ! is_wp_error( $scenario ) && 'never_run' === $scenario['status'], 'QA Scenario store failed.' );
	$scenario = $scenario_store->record_result( $scenario['id'], 'passed', [ 'passed' => true ] );
	$assert( 'passed' === $scenario['status'] && true === $scenario['last_result']['passed'], 'QA Scenario result persistence failed.' );
	$scenario = $scenario_store->save( [ 'id' => $scenario['id'], 'blueprint_id' => $blueprint_id, 'name' => 'Infrastructure replay', 'kind' => 'migration_shadow', 'request' => [ 'source_type' => 'qa', 'source_key' => $blueprint_id ], 'expected' => [ 'status' => 'mismatch' ] ] );
	$assert( 'never_run' === $scenario['status'] && null === $scenario['last_result'], 'Changed QA Scenario retained stale passing evidence.' );

	$normalized = new NormalizedValueStore();
	$relation_id = Uuid::v4();
	$assert( true === $normalized->replace_relation_targets( $blueprint_id, $relation_id, 'source-1', [ 'target-1', [ 'id' => 'target-2', 'payload' => [ 'role' => 'secondary' ] ] ] ), 'Relation normalization failed.' );
	$assert( 2 === count( $normalized->relation_targets( $blueprint_id, $relation_id, 'source-1' ) ), 'Relation query failed.' );
	$assert( true === $normalized->replace_relation_targets( $blueprint_id, $relation_id, 'source-1', [ 'target-unique' ], true ), 'Unique relation target could not be assigned.' );
	$conflict = $normalized->replace_relation_targets( $blueprint_id, $relation_id, 'source-2', [ 'target-unique' ], true );
	$assert( is_wp_error( $conflict ) && 'eit_relation_cardinality_conflict' === $conflict->get_error_code(), 'Unique relation target was assigned to a second source.' );
	$rows = [ [ 'id' => Uuid::v4(), 'value' => [ 'label' => 'First' ] ], [ 'label' => 'Second' ] ];
	$assert( true === $normalized->replace_multivalue_rows( $blueprint_id, $field_id, 'owner-1', $rows ), 'Repeatable row normalization failed.' );
	$assert( 2 === count( $normalized->multivalue_rows( $blueprint_id, $field_id, 'owner-1' ) ), 'Repeatable row query failed.' );

	$cache = new RuntimeCache();
	$assert( true === $cache->set( $blueprint_id, $first_version['id'], [ $artifact ] ), 'Runtime cache write failed.' );
	$assert( 1 === count( $cache->get( $blueprint_id, $first_version['id'] ) ), 'Runtime cache read failed.' );
	$cache->invalidate( $blueprint_id, $first_version['id'] );
	$assert( null === $cache->get( $blueprint_id, $first_version['id'] ), 'Runtime cache invalidation failed.' );

	$reconciliation = ( new ReconciliationStore() )->record( $blueprint_id, $change_set_id, 'verified', [ 'artifacts' => 1 ], [ 'document' => $checksum ] );
	$rollback = ( new RollbackStore() )->record( $blueprint_id, $second_version['id'], $first_version['id'], 'QA rollback' );
	$assert( ! is_wp_error( $reconciliation ) && ! is_wp_error( $rollback ), 'Reconciliation or rollback audit record failed.' );

	$transaction = new Transaction();
	$rolled_back = $transaction->run(
		function () use ( $transaction_blueprint_id, $document ) {
			global $wpdb;
			$wpdb->insert(
				Tables::name( Tables::BLUEPRINTS ),
				[
					'id' => $transaction_blueprint_id,
					'slug' => 'transaction-' . substr( $transaction_blueprint_id, 0, 8 ),
					'name' => $document['name'],
					'draft_revision' => 0,
					'created_at' => current_time( 'mysql', true ),
					'updated_at' => current_time( 'mysql', true ),
				]
			);
			return new WP_Error( 'intentional_rollback', 'QA rollback.' );
		}
	);
	$assert( is_wp_error( $rolled_back ) && null === $blueprints->get( $transaction_blueprint_id ), 'Transaction rollback did not remove partial metadata.' );

	$nested_outer_id = Uuid::v4();
	$nested_inner_id = Uuid::v4();
	$nested = $transaction->run(
		function () use ( $transaction, $nested_outer_id, $nested_inner_id, $document ) {
			global $wpdb;
			$table = Tables::name( Tables::BLUEPRINTS );
			$record = function ( $id, $slug ) use ( $wpdb, $table, $document ) {
				return $wpdb->insert(
					$table,
					[
						'id' => $id,
						'slug' => $slug,
						'name' => $document['name'],
						'draft_revision' => 0,
						'created_at' => current_time( 'mysql', true ),
						'updated_at' => current_time( 'mysql', true ),
					]
				);
			};
			if ( false === $record( $nested_outer_id, 'nested-outer-' . substr( $nested_outer_id, 0, 8 ) ) ) {
				return new WP_Error( 'nested_outer_insert_failed', 'QA nested outer insert.' );
			}
			$inner = $transaction->run(
				function () use ( $record, $nested_inner_id ) {
					return false === $record( $nested_inner_id, 'nested-inner-' . substr( $nested_inner_id, 0, 8 ) )
						? new WP_Error( 'nested_inner_insert_failed', 'QA nested inner insert.' )
						: true;
				}
			);
			return is_wp_error( $inner ) ? $inner : new WP_Error( 'nested_outer_rollback', 'QA nested outer rollback.' );
		}
	);
	$assert( is_wp_error( $nested ) && null === $blueprints->get( $nested_outer_id ) && null === $blueprints->get( $nested_inner_id ), 'Nested transaction committed partial Entry-style writes.' );

	WP_CLI::success( sprintf( 'Blueprint infrastructure verified with %d assertions.', $assertions ) );
} catch ( Throwable $error ) {
	WP_CLI::error( sprintf( '%s (%s:%d)', $error->getMessage(), basename( $error->getFile() ), $error->getLine() ) );
} finally {
	$cleanup();
}
