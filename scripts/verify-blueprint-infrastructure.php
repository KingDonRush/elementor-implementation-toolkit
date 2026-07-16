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
	$assert( count( Tables::keys() ) === 16, 'Dedicated infrastructure table count drifted.' );

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
		[ [ 'field_id' => $field_id, 'adapter' => 'cct', 'storage_key' => 'eit_price', 'aliases' => [ 'legacy_price' ] ] ]
	);
	$assert( true === $binding_result && [ 'legacy_price' ] === $bindings->get( $first_version['id'], $field_id )['aliases'], 'Stable binding aliases failed.' );

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

	$locks = new LockStore();
	$resource = 'blueprint-' . $blueprint_id;
	$lock_token = $locks->acquire( $resource, 1 );
	$assert( is_string( $lock_token ) && is_wp_error( $locks->acquire( $resource, 2 ) ), 'Exclusive Blueprint lock failed.' );
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

	WP_CLI::success( sprintf( 'Blueprint infrastructure verified with %d assertions.', $assertions ) );
} catch ( Throwable $error ) {
	WP_CLI::error( sprintf( '%s (%s:%d)', $error->getMessage(), basename( $error->getFile() ), $error->getLine() ) );
} finally {
	$cleanup();
}
