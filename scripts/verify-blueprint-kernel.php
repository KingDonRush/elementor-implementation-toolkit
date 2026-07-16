<?php
/**
 * WordPress integration verification for compiler, lifecycle and shadow import.
 *
 * Run with:
 * wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-blueprint-kernel.php
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\Compiler;
use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\LegacyImporter;
use EIT\Blueprint\RuntimeDefinitionProvider;
use EIT\Blueprint\RouteRuntime;
use EIT\Blueprint\Uuid;
use EIT\CCT\DefinitionManager as CctDefinitions;
use EIT\CCT\SchemaManager as CctSchema;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\Tables;
use EIT\Infrastructure\VersionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assertions = 0;
$blueprint_id = Uuid::v4();
$suffix = substr( str_replace( '-', '', $blueprint_id ), 0, 8 );
$project_slug = 'qa_project_' . $suffix;
$client_slug = 'qa_client_' . $suffix;
$route_id = Uuid::v5( $blueprint_id, 'route:project-list' );
$presentation_id = Uuid::v5( $blueprint_id, 'presentation:project-list' );

$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$cleanup = function () use ( $blueprint_id, $project_slug, $client_slug ) {
	global $wpdb;

	foreach ( Tables::keys() as $table_key ) {
		$table = Tables::name( $table_key );
		if ( Tables::LOCKS === $table_key ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE resource_key LIKE %s", '%' . $wpdb->esc_like( $blueprint_id ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			continue;
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( in_array( 'blueprint_id', $columns ?: [], true ) ) {
			$wpdb->delete( $table, [ 'blueprint_id' => $blueprint_id ] );
		} elseif ( Tables::BLUEPRINTS === $table_key ) {
			$wpdb->delete( $table, [ 'id' => $blueprint_id ] );
		}
	}
	RuntimeDefinitionProvider::invalidate();
	CctSchema::drop_table( $project_slug );
	CctSchema::drop_table( $client_slug );
};

$build_blueprint = function ( $version ) use ( $blueprint_id, $project_slug, $client_slug, $suffix ) {
	$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
	$entity_project = Uuid::v5( $blueprint_id, 'entity:project' );
	$entity_client = Uuid::v5( $blueprint_id, 'entity:client' );
	$group_project = Uuid::v5( $blueprint_id, 'group:project' );
	$group_client = Uuid::v5( $blueprint_id, 'group:client' );
	$relation = Uuid::v5( $blueprint_id, 'relation:project-client' );
	$entry = Uuid::v5( $blueprint_id, 'entry:project' );
	$collection = Uuid::v5( $blueprint_id, 'collection:project' );
	$client_collection = Uuid::v5( $blueprint_id, 'collection:client-options' );
	$filter = Uuid::v5( $blueprint_id, 'filter:project' );
	$presentation = Uuid::v5( $blueprint_id, 'presentation:project-list' );
	$route = Uuid::v5( $blueprint_id, 'route:project-list' );
	$policy = Uuid::v5( $blueprint_id, 'policy:project-editor' );
	$budget_id = Uuid::v5( $blueprint_id, 'field:budget' );
	$steps_id = Uuid::v5( $blueprint_id, 'field:steps' );
	$client_ref_id = Uuid::v5( $blueprint_id, 'field:client-reference' );
	$step_name_id = Uuid::v5( $blueprint_id, 'field:steps:name' );
	$client_name_id = Uuid::v5( $blueprint_id, 'field:client-name' );
	$budget_key = 3 === $version ? 'budget_v2' : 'budget';
	$budget = $factory->make(
		$budget_id,
		2 === $version ? 'Approved budget' : 'Budget',
		4 === $version ? 'short_text' : 'decimal',
		[ 'storage' => [ 'key' => $budget_key ], 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true, 'sort' => true ] ]
	);
	$steps = $factory->make( $steps_id, 'Delivery steps', 'repeatable_group', [ 'validation' => [ 'children' => [ [ 'id' => $step_name_id, 'name' => 'Step name', 'type' => 'short_text' ] ] ] ] );
	$client_ref = $factory->make( $client_ref_id, 'Client', 'relation' );
	$client_name = $factory->make( $client_name_id, 'Client name', 'short_text', [ 'indexing' => [ 'search' => true, 'sort' => true ] ] );

	return [
		'api_version' => BlueprintValidator::API_VERSION,
		'kind' => BlueprintValidator::KIND,
		'id' => $blueprint_id,
		'slug' => 'qa-blueprint-' . substr( $blueprint_id, 0, 8 ),
		'name' => 'QA Blueprint Kernel',
		'version' => $version,
		'nodes' => [
			[ 'id' => $entity_project, 'type' => 'entity', 'lane' => 'data', 'name' => 'Project', 'config' => [ 'slug' => $project_slug, 'mode' => 'structured', 'high_volume' => true, 'public' => false ] ],
			[ 'id' => $group_project, 'type' => 'field_group', 'lane' => 'data', 'name' => 'Project fields', 'config' => [ 'fields' => [ $budget, $steps, $client_ref ] ] ],
			[ 'id' => $entity_client, 'type' => 'entity', 'lane' => 'data', 'name' => 'Client', 'config' => [ 'slug' => $client_slug, 'mode' => 'structured', 'public' => false ] ],
			[ 'id' => $group_client, 'type' => 'field_group', 'lane' => 'data', 'name' => 'Client fields', 'config' => [ 'fields' => [ $client_name ] ] ],
			[ 'id' => $relation, 'type' => 'relation', 'lane' => 'data', 'name' => 'Project client', 'config' => [ 'cardinality' => 'many_to_one', 'field_id' => $client_ref_id ] ],
			[ 'id' => $entry, 'type' => 'entry_surface', 'lane' => 'experience', 'name' => 'Project entry', 'config' => [ 'operations' => [ 'create', 'update' ] ] ],
			[ 'id' => $collection, 'type' => 'collection', 'lane' => 'experience', 'name' => 'Project collection', 'config' => [ 'page_size' => 24 ] ],
			[ 'id' => $client_collection, 'type' => 'collection', 'lane' => 'experience', 'name' => 'Client options', 'config' => [ 'page_size' => 24 ] ],
			[ 'id' => $filter, 'type' => 'filter_surface', 'lane' => 'experience', 'name' => 'Project filters', 'config' => [ 'fields' => [ $budget_id ] ] ],
			[ 'id' => $presentation, 'type' => 'presentation', 'lane' => 'presentation', 'name' => 'Project list', 'config' => [ 'adapter' => 'elementor' ] ],
			[ 'id' => $route, 'type' => 'route', 'lane' => 'presentation', 'name' => 'Projects route', 'config' => [ 'path' => '/qa-projects-' . $suffix ] ],
			[ 'id' => $policy, 'type' => 'policy', 'lane' => 'governance', 'name' => 'Project editor policy', 'config' => [ 'capability' => 'edit_posts' ] ],
		],
		'connections' => [
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:project-fields' ), 'type' => 'entity_fields', 'from' => $entity_project, 'to' => $group_project ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:client-fields' ), 'type' => 'entity_fields', 'from' => $entity_client, 'to' => $group_client ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:relation-source' ), 'type' => 'relation_source', 'from' => $entity_project, 'to' => $relation ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:relation-target' ), 'type' => 'relation_target', 'from' => $relation, 'to' => $entity_client ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:client-collection' ), 'type' => 'collection_for', 'from' => $entity_client, 'to' => $client_collection ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:relation-options' ), 'type' => 'relation_options', 'from' => $relation, 'to' => $client_collection ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:project-entry' ), 'type' => 'entry_for', 'from' => $entity_project, 'to' => $entry ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:project-collection' ), 'type' => 'collection_for', 'from' => $entity_project, 'to' => $collection ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:collection-filter' ), 'type' => 'filters', 'from' => $collection, 'to' => $filter ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:collection-presentation' ), 'type' => 'presents_collection', 'from' => $collection, 'to' => $presentation ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:presentation-route' ), 'type' => 'routes', 'from' => $presentation, 'to' => $route ],
			[ 'id' => Uuid::v5( $blueprint_id, 'edge:policy-entry' ), 'type' => 'governs_entry', 'from' => $policy, 'to' => $entry ],
		],
	];
};

try {
	$cleanup();
	$assert( true === SchemaManager::install(), 'Blueprint infrastructure is unavailable.' );
	$blueprint_v1 = $build_blueprint( 1 );
	$compiler = new Compiler();
	$first_compile = $compiler->compile( $blueprint_v1 );
	$second_compile = $compiler->compile( $blueprint_v1 );
	$assert( $first_compile->is_valid(), 'Valid Blueprint did not compile: ' . wp_json_encode( $first_compile->errors() ) );
	$assert( $first_compile->checksum() === $second_compile->checksum(), 'Compiler output is not idempotent.' );
	$assert( 4 === count( $first_compile->bindings() ), 'Compiler did not bind all stable Field IDs.' );
	$assert( in_array( 'relation_contract', array_column( $first_compile->artifacts(), 'kind' ), true ), 'Normalized relation artifact is missing.' );
	$required_artifacts = [ 'entry_contract', 'collection_contract', 'filter_contract', 'presentation_contract', 'route_contract', 'policy_contract' ];
	$assert( [] === array_diff( $required_artifacts, array_column( $first_compile->artifacts(), 'kind' ) ), 'Experience, presentation or governance artifacts are missing.' );
	$assert( 2 === count( array_filter( $first_compile->to_array()['recommendations'], function ( $recommendation ) { return 'cct' === $recommendation['selected']; } ) ), 'CCT recommendation drifted.' );

	$lifecycle = eit_blueprint_lifecycle();
	$draft = $lifecycle->save_draft( $blueprint_v1 );
	$assert( ! is_wp_error( $draft ), 'Blueprint draft save failed.' );
	$prepared_v1 = $lifecycle->prepare( $blueprint_id, 1 );
	$assert( ! is_wp_error( $prepared_v1 ) && 'prepared' === $prepared_v1['status'] && ! empty( $prepared_v1['confirmation_token'] ), 'Impact plan was not prepared.' );
	$version_v1 = $lifecycle->apply( $prepared_v1['id'], $prepared_v1['confirmation_token'], 1 );
	$assert( ! is_wp_error( $version_v1 ) && 1 === $version_v1['version'], 'Blueprint V1 publication failed.' );
	$assert( ! is_wp_error( $lifecycle->reconcile( $prepared_v1['id'] ) ), 'Blueprint V1 reconciliation failed.' );
	$route_runtime = new RouteRuntime();
	$active_routes = $route_runtime->contracts();
	$assert( isset( $active_routes[ $route_id ] ) && $presentation_id === $active_routes[ $route_id ]['presentation_id'] && 'qa-projects-' . $suffix === $active_routes[ $route_id ]['path'], 'Published Route did not resolve its owning Presentation and executable path.' );
	$route_runtime->register_routes();
	global $wp_rewrite;
	$assert( isset( $wp_rewrite->extra_rules_top[ '^qa\-projects\-' . $suffix . '/?$' ] ), 'Published virtual Route did not register a bounded WordPress rewrite.' );
	$assert( CctSchema::table_exists( $project_slug ) && CctSchema::table_exists( $client_slug ), 'Compiled CCT storage was not prepared.' );
	$assert( isset( CctDefinitions::all()[ $project_slug ] ), 'Active compiled definition is not projected into runtime.' );
	$assert( ! isset( get_option( CctDefinitions::OPTION, [] )[ $project_slug ] ), 'Compiled definition leaked into legacy options.' );
	$legacy_write = CctDefinitions::save( array_merge( CctDefinitions::all()[ $project_slug ], [ 'original_slug' => $project_slug ] ) );
	$assert( is_wp_error( $legacy_write ) && 'eit_cct_blueprint_owned' === $legacy_write->get_error_code(), 'Legacy manager can mutate a Blueprint-owned definition.' );
	$assert( ! isset( get_option( CctDefinitions::OPTION, [] )[ $project_slug ] ), 'Rejected legacy write persisted a compiled definition.' );

	$blueprint_v2 = $build_blueprint( 2 );
	$assert( ! is_wp_error( $lifecycle->save_draft( $blueprint_v2 ) ), 'Blueprint V2 draft save failed.' );
	$prepared_v2 = $lifecycle->prepare( $blueprint_id, 1 );
	$assert( ! is_wp_error( $prepared_v2 ) && ! $prepared_v2['impact']['blocked'], 'Safe V2 impact was blocked.' );
	$version_v2 = $lifecycle->apply( $prepared_v2['id'], $prepared_v2['confirmation_token'], 1 );
	$assert( ! is_wp_error( $version_v2 ) && 2 === $version_v2['version'], 'Blueprint V2 publication failed.' );
	$assert( ! is_wp_error( $lifecycle->reconcile( $prepared_v2['id'] ) ), 'Blueprint V2 reconciliation failed.' );

	$blueprint_type_change = $build_blueprint( 4 );
	$assert( ! is_wp_error( $lifecycle->save_draft( $blueprint_type_change ) ), 'Blueprint type-change draft save failed.' );
	$blocked_type = $lifecycle->prepare( $blueprint_id, 1 );
	$assert( ! is_wp_error( $blocked_type ) && 'blocked' === $blocked_type['status'] && null === $blocked_type['confirmation_token'], 'Published field type change was not blocked before storage preparation.' );
	$assert( in_array( 'published_field_semantics_locked', array_column( $blocked_type['impact']['blockers'], 'code' ), true ), 'Field semantic migration blocker is not explicit.' );

	$blueprint_v3 = $build_blueprint( 3 );
	$assert( ! is_wp_error( $lifecycle->save_draft( $blueprint_v3 ) ), 'Blueprint V3 draft save failed.' );
	$blocked = $lifecycle->prepare( $blueprint_id, 1 );
	$assert( ! is_wp_error( $blocked ) && 'blocked' === $blocked['status'] && null === $blocked['confirmation_token'], 'Published storage key change was not blocked.' );
	$assert( 'published_storage_key_locked' === $blocked['impact']['blockers'][0]['code'], 'Storage migration blocker is not explicit.' );

	$rollback = $lifecycle->rollback( $blueprint_id, $version_v1['id'], 'Integration rollback', 1 );
	$assert( ! is_wp_error( $rollback ), 'Blueprint rollback failed.' );
	$assert( $version_v1['id'] === ( new BlueprintStore() )->get( $blueprint_id )['active_version_id'], 'Rollback did not reactivate the target version.' );
	$assert( 2 === count( ( new VersionStore() )->for_blueprint( $blueprint_id ) ), 'Rollback deleted immutable versions.' );
	$assert( ! empty( ( new ArtifactStore() )->for_version( $version_v2['id'] ) ), 'Rollback deleted newer artifacts.' );

	$importer = new LegacyImporter();
	$legacy_definition = [
		'slug' => 'legacy_sample', 'singular' => 'Legacy sample', 'plural' => 'Legacy samples', 'public' => true,
		'fields' => [ [ 'key' => 'legacy_price', 'label' => 'Legacy price', 'type' => 'number', 'filterable' => true, 'active' => true ] ],
	];
	$imported_once = $importer->import_entity( 'cct', 'legacy_sample', $legacy_definition );
	$imported_twice = $importer->import_entity( 'cct', 'legacy_sample', $legacy_definition );
	$assert( $imported_once['checksum'] === $imported_twice['checksum'], 'Legacy import identities are not deterministic.' );
	$assert( ( new BlueprintValidator() )->validate( $imported_once )->is_valid(), 'Shadow-imported legacy definition is not a valid Blueprint.' );
	$assert( $compiler->compile( $imported_once )->is_valid(), 'Shadow-imported legacy definition cannot be compiled.' );
	$shadow = $importer->inspect_all();
	$assert( 'shadow_read_only' === $shadow['mode'] && $shadow['read_only'], 'Legacy importer mutated its option sources.' );
	$assert( isset( eit_blueprint_registries()->storage_adapters()->health()['legacy_dom'] ), 'Legacy adapter is not contextualized in the registry.' );

	WP_CLI::success( sprintf( 'Blueprint compiler and lifecycle verified with %d assertions.', $assertions ) );
} catch ( Throwable $error ) {
	WP_CLI::error( $error->getMessage() );
} finally {
	$cleanup();
}
