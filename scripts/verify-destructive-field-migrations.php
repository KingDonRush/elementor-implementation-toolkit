<?php
/**
 * Exercises source-preserving destructive Field migrations against WordPress/MySQL.
 *
 * Run with:
 * wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-destructive-field-migrations.php
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldMigrationExecutionService;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\LifecycleService;
use EIT\Blueprint\MigrationStorageLockCoordinator;
use EIT\Blueprint\MigrationWriteFence;
use EIT\Blueprint\RuntimeDefinitionProvider;
use EIT\Blueprint\StorageMutationGuard;
use EIT\Blueprint\Uuid;
use EIT\CCT\DefinitionManager as CctDefinitions;
use EIT\CCT\Repository as CctRepository;
use EIT\CCT\SchemaManager as CctSchema;
use EIT\CPT\DefinitionManager as CptDefinitions;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\ChangeSetStore;
use EIT\Infrastructure\MigrationOperationStore;
use EIT\Infrastructure\RuntimeCache;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assertions = 0;
$post_ids = [];
$authority_probe_post_id = 0;
$scenarios = [];
$values = [ '10.0', '0', '-7.000000' ];
$expected = [ 10, 0, -7 ];

$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};

foreach ( [ 'cpt', 'cct' ] as $strategy ) {
	$blueprint_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:destructive-field-migration:' . $strategy );
	$suffix = substr( str_replace( '-', '', $blueprint_id ), 0, 8 );
	$scenarios[ $strategy ] = [
		'strategy' => $strategy,
		'blueprint_id' => $blueprint_id,
		'entity_id' => Uuid::v5( $blueprint_id, 'entity' ),
		'group_id' => Uuid::v5( $blueprint_id, 'field-group' ),
		'field_id' => Uuid::v5( $blueprint_id, 'amount-field' ),
		'edge_id' => Uuid::v5( $blueprint_id, 'entity-fields' ),
		'slug' => 'eit_m' . ( 'cpt' === $strategy ? 'p' : 'c' ) . '_' . $suffix,
		'source_key' => 'amount_' . $suffix,
	];
}

$build_document = function ( array $scenario, $version ) {
	$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
	$type = 1 === $version ? 'decimal' : 'integer';
	$field = $factory->make(
		$scenario['field_id'],
		'Amount',
		$type,
		[
			'storage' => [ 'key' => $scenario['source_key'] ],
			'indexing' => [ 'filter' => true, 'sort' => true ],
		]
	);
	$entity_config = [
		'slug' => $scenario['slug'],
		'mode' => 'structured',
		'public' => 'cpt' === $scenario['strategy'],
		'high_volume' => 'cct' === $scenario['strategy'],
	];

	return [
		'api_version' => BlueprintValidator::API_VERSION,
		'kind' => BlueprintValidator::KIND,
		'id' => $scenario['blueprint_id'],
		'slug' => 'migration-gate-' . substr( $scenario['blueprint_id'], 0, 8 ),
		'name' => strtoupper( $scenario['strategy'] ) . ' destructive migration gate',
		'version' => $version,
		'nodes' => [
			[ 'id' => $scenario['entity_id'], 'type' => 'entity', 'lane' => 'data', 'name' => 'Migration item', 'config' => $entity_config ],
			[ 'id' => $scenario['group_id'], 'type' => 'field_group', 'lane' => 'data', 'name' => 'Migration fields', 'config' => [ 'fields' => [ $field ] ] ],
		],
		'connections' => [
			[ 'id' => $scenario['edge_id'], 'type' => 'entity_fields', 'from' => $scenario['entity_id'], 'to' => $scenario['group_id'] ],
		],
	];
};

$read_values = function ( array $scenario, $storage_key ) use ( &$post_ids ) {
	if ( 'cpt' === $scenario['strategy'] ) {
		return array_map(
			fn( $post_id ) => get_post_meta( $post_id, $storage_key, true ),
			$post_ids[ $scenario['blueprint_id'] ] ?? []
		);
	}

	global $wpdb;
	$table = CctSchema::table_name( $scenario['slug'] );
	$column = CctSchema::column_name( $storage_key );
	return $wpdb->get_col( "SELECT `{$column}` FROM `{$table}` ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Closed fixture identifiers.
};

$numeric_values = fn( array $stored ) => array_map( fn( $value ) => (int) (float) $value, $stored );

$cleanup = function () use ( &$scenarios, &$authority_probe_post_id ) {
	global $wpdb;

	StorageMutationGuard::shared()->release_all();
	if ( $authority_probe_post_id ) {
		wp_delete_post( $authority_probe_post_id, true );
		$authority_probe_post_id = 0;
	}
	$cache = new RuntimeCache();
	foreach ( $scenarios as $scenario ) {
		$blueprint_id = $scenario['blueprint_id'];
		if ( 'cpt' === $scenario['strategy'] ) {
			$fixture_posts = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = %s", $scenario['slug'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $fixture_posts ?: [] as $post_id ) {
				$wpdb->delete( $wpdb->postmeta, [ 'post_id' => absint( $post_id ) ] );
				$wpdb->delete( $wpdb->posts, [ 'ID' => absint( $post_id ) ] );
			}
		}
		$version_table = Tables::name( Tables::VERSIONS );
		$version_exists = $version_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $version_table ) ) );
		$version_ids = $version_exists ? $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$version_table}` WHERE blueprint_id = %s", $blueprint_id ) ) : []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $version_ids ?: [] as $version_id ) {
			$cache->invalidate( $blueprint_id, $version_id );
		}

		$run_table = Tables::name( Tables::RUNS );
		$event_table = Tables::name( Tables::RUN_EVENTS );
		$run_exists = $run_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $run_table ) ) );
		$run_ids = $run_exists ? $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$run_table}` WHERE blueprint_id = %s", $blueprint_id ) ) : []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $run_ids ?: [] as $run_id ) {
			$wpdb->delete( $event_table, [ 'run_id' => $run_id ] );
		}

		foreach ( Tables::keys() as $table_key ) {
			$table = Tables::name( $table_key );
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				continue;
			}
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Closed infrastructure table registry.
			if ( in_array( 'blueprint_id', $columns ?: [], true ) ) {
				$wpdb->delete( $table, [ 'blueprint_id' => $blueprint_id ] );
			}
		}
		$wpdb->delete( Tables::name( Tables::BLUEPRINTS ), [ 'id' => $blueprint_id ] );
		$lock_table = Tables::name( Tables::LOCKS );
		if ( $lock_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $lock_table ) ) ) ) {
			foreach ( [ 'blueprint-' . $blueprint_id, StorageMutationGuard::resource_key( $scenario['strategy'], $scenario['slug'] ) ] as $resource ) {
				$wpdb->delete( $lock_table, [ 'resource_key' => $resource ] );
			}
			$writer_prefix = $wpdb->esc_like( StorageMutationGuard::writer_prefix( $scenario['strategy'], $scenario['slug'] ) ) . '%';
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$lock_table}` WHERE resource_key LIKE %s", $writer_prefix ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( 'cct' === $scenario['strategy'] ) {
			CctSchema::drop_table( $scenario['slug'] );
		} elseif ( post_type_exists( $scenario['slug'] ) ) {
			unregister_post_type( $scenario['slug'] );
		}
	}
	RuntimeDefinitionProvider::invalidate();
};

try {
	global $wpdb;

	$cleanup();
	$assert( true === SchemaManager::install() && true === SchemaManager::verify(), 'Migration infrastructure is unavailable.' );
	$authority_probe_post_id = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Runtime authority failure probe' ], true );
	$assert( ! is_wp_error( $authority_probe_post_id ), 'Runtime authority failure probe post could not be created.' );
	$original_prefix = $wpdb->prefix;
	$previous_suppression = $wpdb->suppress_errors( true );
	try {
		$wpdb->prefix = 'eit_missing_authority_';
		RuntimeDefinitionProvider::invalidate();
		$authority_failure = RuntimeDefinitionProvider::cpt_definitions_result();
		$blocked_write = update_post_meta( $authority_probe_post_id, '_eit_authority_probe', 'must-not-write' );
		$assert( is_wp_error( $authority_failure ) && false === $blocked_write, 'WordPress mutation guard failed open while runtime authority was unavailable.' );
	} finally {
		$wpdb->prefix = $original_prefix;
		$wpdb->suppress_errors( $previous_suppression );
		RuntimeDefinitionProvider::invalidate();
		StorageMutationGuard::shared()->release_all();
	}
	$assert( '' === get_post_meta( $authority_probe_post_id, '_eit_authority_probe', true ), 'A write escaped while runtime authority was unavailable.' );

	foreach ( $scenarios as $strategy => &$scenario ) {
		$lifecycle = new LifecycleService();
		$saved_v1 = $lifecycle->save_draft( $build_document( $scenario, 1 ) );
		$assert( ! is_wp_error( $saved_v1 ), strtoupper( $strategy ) . ' V1 draft could not be saved.' );
		$plan_v1 = $lifecycle->prepare( $scenario['blueprint_id'], 0 );
		$assert( ! is_wp_error( $plan_v1 ) && 'prepared' === ( $plan_v1['status'] ?? '' ), strtoupper( $strategy ) . ' V1 could not prepare.' );
		$version_v1 = $lifecycle->apply( $plan_v1['id'], $plan_v1['confirmation_token'], 0 );
		$assert( ! is_wp_error( $version_v1 ), strtoupper( $strategy ) . ' V1 could not publish.' );
		$reconciled_v1 = $lifecycle->reconcile( $plan_v1['id'] );
		$assert( ! is_wp_error( $reconciled_v1 ) && 'reconciled' === ( $reconciled_v1['status'] ?? '' ), strtoupper( $strategy ) . ' V1 could not reconcile.' );
		$scenario['version_v1_id'] = $version_v1['id'];

		RuntimeDefinitionProvider::invalidate();
		if ( 'cpt' === $strategy ) {
			$definition = CptDefinitions::get( $scenario['slug'] );
			$assert( ! empty( $definition['blueprint_managed'] ), 'Published CPT definition is not Blueprint-owned.' );
			$registered = register_post_type( $scenario['slug'], [ 'public' => false, 'show_ui' => false, 'supports' => [ 'title' ] ] );
			$assert( ! is_wp_error( $registered ), 'Published CPT fixture could not be registered.' );
			foreach ( $values as $offset => $value ) {
				$post_id = wp_insert_post( [ 'post_type' => $scenario['slug'], 'post_status' => 'publish', 'post_title' => 'Migration ' . ( $offset + 1 ), 'meta_input' => [ $scenario['source_key'] => $value ] ], true );
				$assert( ! is_wp_error( $post_id ), 'CPT source record could not be created.' );
				$post_ids[ $scenario['blueprint_id'] ][] = $post_id;
			}
			StorageMutationGuard::shared()->release_all();
		} else {
			$definition = CctDefinitions::get( $scenario['slug'] );
			$assert( ! empty( $definition['blueprint_managed'] ) && CctSchema::table_exists( $scenario['slug'] ), 'Published CCT storage is unavailable.' );
			$repository = new CctRepository();
			foreach ( $values as $offset => $value ) {
				$record_id = $repository->save( $scenario['slug'], [ 'title' => 'Migration ' . ( $offset + 1 ), $scenario['source_key'] => $value ] );
				$assert( ! is_wp_error( $record_id ), 'CCT source record could not be created.' );
				$scenario['record_ids'][] = $record_id;
			}
		}
		$source_before = $read_values( $scenario, $scenario['source_key'] );
		$assert( $expected === $numeric_values( $source_before ), strtoupper( $strategy ) . ' source fixture values drifted.' );

		$saved_v2 = $lifecycle->save_draft( $build_document( $scenario, 2 ) );
		$assert( ! is_wp_error( $saved_v2 ), strtoupper( $strategy ) . ' V2 draft could not be saved.' );
		$field_v2 = $saved_v2['draft_document']['nodes'][1]['config']['fields'][0];
		$target_key = $field_v2['storage']['key'] ?? '';
		$assert( str_starts_with( $target_key, 'eit_m_' ) && $target_key !== $scenario['source_key'] && in_array( $scenario['source_key'], $field_v2['storage']['aliases'] ?? [], true ), strtoupper( $strategy ) . ' did not allocate source-preserving target storage.' );

		$plan_v2 = $lifecycle->prepare( $scenario['blueprint_id'], 0 );
		$operations = $plan_v2['impact']['migration_plan']['operations'] ?? [];
		$assert( ! is_wp_error( $plan_v2 ) && 'prepared' === ( $plan_v2['status'] ?? '' ) && 1 === count( $operations ), strtoupper( $strategy ) . ' V2 did not prepare exactly one migration.' );
		$assert( 'strict_integer' === ( $operations[0]['transform'] ?? '' ) && $target_key === ( $operations[0]['target']['key'] ?? '' ), strtoupper( $strategy ) . ' migration contract drifted.' );

		$change_sets = new ChangeSetStore();
		$interrupted = $change_sets->transition( $plan_v2['id'], 'prepared', 'applying' );
		$assert( ! is_wp_error( $interrupted ), strtoupper( $strategy ) . ' interruption state could not be staged.' );
		StorageMutationGuard::shared()->release_all();
		$migration_locks = new MigrationStorageLockCoordinator();
		$migrations = new FieldMigrationExecutionService();
		$migration_leases = $migration_locks->acquire( $scenario['blueprint_id'], $plan_v2['id'], 0, $migrations );
		$assert( ! is_wp_error( $migration_leases ), strtoupper( $strategy ) . ' interruption could not acquire its storage boundary.' );
		try {
			$preflight = $migrations->before_runtime_prepare( $scenario['blueprint_id'], $plan_v2['id'] );
		} finally {
			$migration_locks->release( $migration_leases, $migrations );
		}
		$records = ( new MigrationOperationStore() )->for_change_set( $scenario['blueprint_id'], $plan_v2['id'] );
		$assert( ! is_wp_error( $preflight ) && 'target_preparing' === ( $records[0]['status'] ?? '' ), strtoupper( $strategy ) . ' durable pre-apply checkpoint was not persisted.' );

		StorageMutationGuard::shared()->release_all();
		$resumed_lifecycle = new LifecycleService();
		$version_v2 = $resumed_lifecycle->apply( $plan_v2['id'], $plan_v2['confirmation_token'], 0 );
		$records = ( new MigrationOperationStore() )->for_change_set( $scenario['blueprint_id'], $plan_v2['id'] );
		$operation = $records[0] ?? [];
		$assert( ! is_wp_error( $version_v2 ) && 'switched' === ( $operation['status'] ?? '' ), strtoupper( $strategy ) . ' interrupted migration did not resume and switch.' );
		$assert( 3 === ( $operation['source_count'] ?? 0 ) && 3 === ( $operation['target_count'] ?? 0 ) && 3 === ( $operation['transformed_count'] ?? 0 ), strtoupper( $strategy ) . ' migration counts do not prove a complete copy.' );
		$assert( ! empty( $operation['source_checksum'] ) && hash_equals( $operation['source_checksum'], $operation['target_checksum'] ?? '' ), strtoupper( $strategy ) . ' migration checksums do not match.' );
		$assert( $expected === $numeric_values( $read_values( $scenario, $target_key ) ), strtoupper( $strategy ) . ' target values do not match transformed source values.' );
		$assert( $source_before === $read_values( $scenario, $scenario['source_key'] ), strtoupper( $strategy ) . ' source storage changed during copy.' );

		$fenced = ( new MigrationWriteFence() )->guard_storage( $strategy, $scenario['slug'] );
		$assert( is_wp_error( $fenced ) && 'eit_migration_write_fenced' === $fenced->get_error_code(), strtoupper( $strategy ) . ' write fence was not active before reconcile.' );
		if ( 'cpt' === $strategy ) {
			$write = update_post_meta( $post_ids[ $scenario['blueprint_id'] ][0], $scenario['source_key'], '99' );
		} else {
			$write = ( new CctRepository() )->save( $scenario['slug'], [ 'title' => 'Blocked', $scenario['source_key'] => 99 ], $scenario['record_ids'][0] );
		}
		$assert( false === $write || ( is_wp_error( $write ) && 'eit_migration_write_fenced' === $write->get_error_code() ), strtoupper( $strategy ) . ' write API bypassed the migration fence.' );
		$assert( $source_before === $read_values( $scenario, $scenario['source_key'] ), strtoupper( $strategy ) . ' blocked write mutated source data.' );

		$reconciled_v2 = $resumed_lifecycle->reconcile( $plan_v2['id'] );
		$assert( ! is_wp_error( $reconciled_v2 ) && true === ( new MigrationWriteFence() )->guard_storage( $strategy, $scenario['slug'] ), strtoupper( $strategy ) . ' reconcile did not release the verified fence.' );
		if ( 'cpt' === $strategy ) {
			$released_write = add_post_meta( $post_ids[ $scenario['blueprint_id'] ][0], '_eit_gate_probe', 'released', true );
		} else {
			$released_record = ( new CctRepository() )->get( $scenario['slug'], $scenario['record_ids'][0] );
			$released_record['title'] = 'Released after reconcile';
			$released_write = ( new CctRepository() )->save( $scenario['slug'], $released_record, $scenario['record_ids'][0] );
		}
		$assert( ! is_wp_error( $released_write ) && false !== $released_write, strtoupper( $strategy ) . ' write API remained blocked after reconcile.' );
		StorageMutationGuard::shared()->release_all();
		$rollback = $resumed_lifecycle->rollback( $scenario['blueprint_id'], $scenario['version_v1_id'], 'Destructive migration integration gate', 0 );
		$records = ( new MigrationOperationStore() )->for_change_set( $scenario['blueprint_id'], $plan_v2['id'] );
		$blueprint = ( new BlueprintStore() )->get( $scenario['blueprint_id'] );
		$assert( ! is_wp_error( $rollback ) && 'rolled_back' === ( $records[0]['status'] ?? '' ) && $scenario['version_v1_id'] === ( $blueprint['active_version_id'] ?? 0 ), strtoupper( $strategy ) . ' immediate rollback did not restore V1 authority.' );
		$assert( $source_before === $read_values( $scenario, $scenario['source_key'] ), strtoupper( $strategy ) . ' rollback did not preserve source data.' );
	}
	unset( $scenario );

	WP_CLI::success( sprintf( 'Destructive CPT/CCT migrations verified with %d real WordPress/MySQL assertions.', $assertions ) );
} finally {
	$cleanup();
}
