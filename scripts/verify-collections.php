<?php
/**
 * WordPress integration verification for compiled Collection queries and facets.
 *
 * Run with:
 * wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-collections.php
 */

use EIT\Blueprint\BlueprintModule;
use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\RuntimeDefinitionProvider;
use EIT\Blueprint\Uuid;
use EIT\CCT\Repository;
use EIT\CCT\SchemaManager as CctSchema;
use EIT\Collection\CollectionCache;
use EIT\Collection\CctCollectionProvider;
use EIT\Collection\CptCollectionProvider;
use EIT\Collection\CollectionSurfaceResolver;
use EIT\Elementor\FilterController\CollectionWidgetBridge;
use EIT\Entry\EntryStorageGateway;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\Tables;
use EIT\Infrastructure\NormalizedValueStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assertions = 0;
$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$ids = [
	'blueprint' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:blueprint' ),
	'entity' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:entity' ),
	'agent_entity' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:agent-entity' ),
	'group' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:group' ),
	'relation' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:agent-relation' ),
	'collection' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:surface' ),
	'agent_collection' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:agent-options' ),
	'filter' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:filters' ),
	'title' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:title' ),
	'price' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:price' ),
	'tier' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:tier' ),
	'secret' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:secret' ),
		'agent' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:agent' ),
		'choices' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:choices' ),
];
$slug = 'eit_collection_verify';
$agent_slug = 'eit_collection_agent';
$scope_post_type = 'eit_scope_verify';
$scope_post_ids = [];
$scope_user_id = 0;

$cleanup = function () use ( $ids, $slug, $agent_slug, $scope_post_type, &$scope_post_ids, &$scope_user_id ) {
	global $wpdb;

	foreach ( [ Tables::RELATIONS, Tables::MULTIVALUES, Tables::RECONCILIATIONS, Tables::ROLLBACKS, Tables::RUNS, Tables::BINDINGS, Tables::ARTIFACTS, Tables::CHANGE_SETS, Tables::VERSIONS ] as $table_key ) {
		$wpdb->delete( Tables::name( $table_key ), [ 'blueprint_id' => $ids['blueprint'] ] );
	}
	$wpdb->delete( Tables::name( Tables::BLUEPRINTS ), [ 'id' => $ids['blueprint'] ] );
	$locks = Tables::name( Tables::LOCKS );
	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$locks}` WHERE resource_key LIKE %s", '%' . $wpdb->esc_like( $ids['blueprint'] ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	CctSchema::drop_table( $slug );
	CctSchema::drop_table( $agent_slug );
	foreach ( $scope_post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	$scope_post_ids = [];
	if ( post_type_exists( $scope_post_type ) ) {
		unregister_post_type( $scope_post_type );
	}
	if ( $scope_user_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $scope_user_id );
		$scope_user_id = 0;
	}
	delete_option( 'eit_col_gen_' . substr( hash( 'sha256', $ids['entity'] ), 0, 24 ) );
	RuntimeDefinitionProvider::invalidate();
};

try {
	$cleanup();
	$assert( true === SchemaManager::install(), 'Toolkit infrastructure must install.' );
	$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
	$assert( ! empty( $admin[0] ), 'An administrator is required for Collection scope verification.' );
	wp_set_current_user( (int) $admin[0] );
	$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
	$fields = [
		$factory->make( $ids['title'], 'Title', 'short_text', [ 'exposure' => [ 'public' => true ], 'indexing' => [ 'search' => true ] ] ),
		$factory->make( $ids['price'], 'Price', 'decimal', [ 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true, 'sort' => true ] ] ),
		$factory->make(
			$ids['tier'],
			'Tier',
			'single_choice',
			[
				'exposure' => [ 'public' => true ],
				'indexing' => [ 'filter' => true ],
				'validation' => [ 'options' => [ [ 'value' => 'basic', 'label' => 'Basic' ], [ 'value' => 'premium', 'label' => 'Premium' ] ] ],
			]
		),
		$factory->make( $ids['secret'], 'Internal note', 'short_text', [ 'exposure' => [ 'public' => false ] ] ),
		$factory->make( $ids['agent'], 'Agent', 'relation', [ 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true ] ] ),
	];
	$document = [
		'api_version' => BlueprintValidator::API_VERSION,
		'kind' => BlueprintValidator::KIND,
		'id' => $ids['blueprint'],
		'slug' => 'verify-collection',
		'name' => 'Collection verification system',
		'version' => 1,
		'nodes' => [
			[ 'id' => $ids['entity'], 'type' => 'entity', 'lane' => 'data', 'name' => 'Catalog item', 'config' => [ 'slug' => $slug, 'mode' => 'structured', 'public' => true, 'high_volume' => true, 'storage' => [ 'strategy' => 'cct', 'override_reason' => 'Exercise the public projection of an indexed operational catalog.' ] ] ],
			[ 'id' => $ids['agent_entity'], 'type' => 'entity', 'lane' => 'data', 'name' => 'Catalog agent', 'config' => [ 'slug' => $agent_slug, 'mode' => 'structured', 'public' => false ] ],
			[ 'id' => $ids['group'], 'type' => 'field_group', 'lane' => 'data', 'name' => 'Catalog fields', 'config' => [ 'fields' => $fields ] ],
			[ 'id' => $ids['relation'], 'type' => 'relation', 'lane' => 'data', 'name' => 'Catalog agent', 'config' => [ 'cardinality' => 'many_to_one', 'field_id' => $ids['agent'] ] ],
			[ 'id' => $ids['collection'], 'type' => 'collection', 'lane' => 'experience', 'name' => 'Public catalog', 'config' => [
				'page_size' => 2,
				'access' => 'public',
				'projection_field_ids' => [ $ids['title'], $ids['tier'], $ids['agent'] ],
				'default_sort' => [ 'field_id' => $ids['price'], 'direction' => 'asc' ],
				'explain' => true,
			] ],
			[ 'id' => $ids['agent_collection'], 'type' => 'collection', 'lane' => 'experience', 'name' => 'Catalog agent options', 'config' => [ 'page_size' => 24 ] ],
			[ 'id' => $ids['filter'], 'type' => 'filter_surface', 'lane' => 'experience', 'name' => 'Catalog filters', 'config' => [
				'fields' => [ $ids['price'], $ids['tier'], $ids['agent'] ],
				'facet_fields' => [ $ids['tier'], $ids['agent'] ],
				'url_state' => true,
				'active_chips' => true,
			] ],
		],
		'connections' => [
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:edge:fields' ), 'type' => 'entity_fields', 'from' => $ids['entity'], 'to' => $ids['group'] ],
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:edge:relation-source' ), 'type' => 'relation_source', 'from' => $ids['entity'], 'to' => $ids['relation'] ],
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:edge:relation-target' ), 'type' => 'relation_target', 'from' => $ids['relation'], 'to' => $ids['agent_entity'] ],
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:edge:agent-surface' ), 'type' => 'collection_for', 'from' => $ids['agent_entity'], 'to' => $ids['agent_collection'] ],
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:edge:relation-options' ), 'type' => 'relation_options', 'from' => $ids['relation'], 'to' => $ids['agent_collection'] ],
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:edge:surface' ), 'type' => 'collection_for', 'from' => $ids['entity'], 'to' => $ids['collection'] ],
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:edge:filters' ), 'type' => 'filters', 'from' => $ids['collection'], 'to' => $ids['filter'] ],
		],
	];

	$lifecycle = BlueprintModule::lifecycle();
	$assert( ! is_wp_error( $lifecycle->save_draft( $document ) ), 'Collection Blueprint draft must save.' );
	$prepared = $lifecycle->prepare( $ids['blueprint'], 1 );
	$assert( ! is_wp_error( $prepared ) && ! empty( $prepared['confirmation_token'] ), 'Collection impact must prepare: ' . ( is_wp_error( $prepared ) ? $prepared->get_error_code() . ' ' . $prepared->get_error_message() : wp_json_encode( $prepared['impact']['blockers'] ?? $prepared ) ) );
	$published = $lifecycle->apply( $prepared['id'], $prepared['confirmation_token'], 1 );
	$assert( ! is_wp_error( $published ), 'Collection Blueprint must publish.' );
	$assert( ! is_wp_error( $lifecycle->reconcile( $prepared['id'] ) ), 'Collection publication must reconcile.' );

	$repository = new Repository();
	$records = [
		[ 'title' => 'Zero', 'status' => 'publish', $fields[0]['storage']['key'] => 'Zero', $fields[1]['storage']['key'] => 0, $fields[2]['storage']['key'] => 'basic', $fields[3]['storage']['key'] => 'hidden-zero' ],
		[ 'title' => 'Starter', 'status' => 'publish', $fields[0]['storage']['key'] => 'Starter', $fields[1]['storage']['key'] => 10, $fields[2]['storage']['key'] => 'basic', $fields[3]['storage']['key'] => 'hidden-starter' ],
		[ 'title' => 'Pro', 'status' => 'publish', $fields[0]['storage']['key'] => 'Pro', $fields[1]['storage']['key'] => 20, $fields[2]['storage']['key'] => 'premium', $fields[3]['storage']['key'] => 'hidden-pro' ],
		[ 'title' => 'Draft leak', 'status' => 'draft', $fields[0]['storage']['key'] => 'Draft leak', $fields[1]['storage']['key'] => 99, $fields[2]['storage']['key'] => 'premium', $fields[3]['storage']['key'] => 'hidden-draft' ],
	];
	$saved_ids = [];
	foreach ( $records as $record ) {
		$saved_record = $repository->save( $slug, $record );
		$assert( ! is_wp_error( $saved_record ), 'Collection fixture record must save: ' . ( is_wp_error( $saved_record ) ? $saved_record->get_error_code() . ' ' . $saved_record->get_error_message() : '' ) );
		$saved_ids[ $record['title'] ] = $saved_record;
	}
	$normalized = new NormalizedValueStore();
	foreach ( [ 'Zero' => 'agent-b', 'Starter' => 'agent-a', 'Pro' => 'agent-b', 'Draft leak' => 'agent-a' ] as $title => $agent ) {
		$assert( true === $normalized->replace_relation_targets( $ids['blueprint'], $ids['agent'], $saved_ids[ $title ], [ $agent ] ), 'Normalized relation fixture must save.' );
	}

	$contract_request = new WP_REST_Request( 'GET', '/eit/v1/collections/' . $ids['collection'] );
	$contract_response = rest_do_request( $contract_request );
	$assert( 200 === $contract_response->get_status(), 'Published Collection contract must be readable.' );
	$projection = $contract_response->get_data();
	$assert( 4 === count( $projection['fields'] ), 'Private Field must not enter the public contract.' );
	$assert( false === strpos( wp_json_encode( $projection ), $fields[1]['storage']['key'] ), 'Browser contract leaked a storage key.' );
	$bridge = CollectionWidgetBridge::resolve( $ids['collection'] );
	$assert( ! is_wp_error( $bridge ), 'Published Collection must resolve in the Elementor compatibility bridge.' );
	$assert( isset( CollectionWidgetBridge::options()[ $ids['collection'] ] ), 'Published Collection must appear in the Elementor selector.' );
	$assert( $ids['price'] === $bridge['filters'][0]['key'] && 'between' === $bridge['filters'][0]['compare'], 'Elementor bridge did not preserve the compiled Field-ID operator.' );
	$assert( empty( $bridge['settings']['target_selector'] ) && [ $ids['tier'], $ids['agent'] ] === $bridge['facet_field_ids'], 'Elementor bridge reintroduced a selector or lost facets.' );

	$query_request = new WP_REST_Request( 'POST', '/eit/v1/collections/' . $ids['collection'] . '/query' );
	$query_request->set_header( 'content-type', 'application/json' );
	$query_request->set_body( wp_json_encode( [
		'filters' => [ [ 'field_id' => $ids['price'], 'operator' => 'gte', 'value' => 10 ] ],
		'explain' => true,
	] ) );
	$query_response = rest_do_request( $query_request );
	$assert( 200 === $query_response->get_status(), 'Field-ID Collection query must succeed.' );
	$data = $query_response->get_data();
	$assert( 2 === $data['pagination']['total'] && [ 'Starter', 'Pro' ] === array_column( $data['items'], 'title' ), 'Indexed filter or sort drifted.' );
	$assert( false === strpos( wp_json_encode( $data ), 'Draft leak' ), 'Non-public draft leaked through Collection query.' );
	$assert( false === strpos( wp_json_encode( $data ), 'hidden-' ), 'Private Field value leaked through Collection projection.' );
	$assert( ! array_key_exists( $ids['price'], $data['items'][0]['values'] ), 'A filter-only Field leaked into the item projection.' );
	$assert( 2 === count( $data['explain'] ) && ! empty( $data['explain'][0]['checks'][0]['result'] ), 'Explain Why did not prove the applied comparison.' );
	$assert( 10.0 === $data['explain'][0]['checks'][0]['actual'], 'Explain Why did not use the typed filter Field outside the item projection.' );
	$tier_facet = current( array_filter( $data['facets'], fn( $facet ) => $ids['tier'] === $facet['field_id'] ) );
	$facet_counts = array_column( $tier_facet['values'], 'count', 'value' );
	$assert( 1 === ( $facet_counts['basic'] ?? 0 ) && 1 === ( $facet_counts['premium'] ?? 0 ), 'Facet counts do not respect the other active filters.' );
	$agent_facet = current( array_filter( $data['facets'], fn( $facet ) => $ids['agent'] === $facet['field_id'] ) );
	$agent_counts = array_column( $agent_facet['values'], 'count', 'value' );
	$assert( 1 === ( $agent_counts['agent-a'] ?? 0 ) && 1 === ( $agent_counts['agent-b'] ?? 0 ), 'Normalized relation facets are not counted across matching sources.' );

	$zero_request = new WP_REST_Request( 'POST', '/eit/v1/collections/' . $ids['collection'] . '/query' );
	$zero_request->set_header( 'content-type', 'application/json' );
	$zero_request->set_body( wp_json_encode( [ 'filters' => [ [ 'field_id' => $ids['price'], 'operator' => 'equals', 'value' => '0' ] ] ] ) );
	$zero = rest_do_request( $zero_request )->get_data();
	$assert( 1 === $zero['pagination']['total'] && 'Zero' === $zero['items'][0]['title'], 'Required zero semantics were lost by Collection filtering.' );

	$relation_request = new WP_REST_Request( 'POST', '/eit/v1/collections/' . $ids['collection'] . '/query' );
	$relation_request->set_header( 'content-type', 'application/json' );
	$relation_request->set_body( wp_json_encode( [ 'filters' => [ [ 'field_id' => $ids['agent'], 'operator' => 'in', 'value' => [ 'agent-a' ] ] ] ] ) );
	$relation = rest_do_request( $relation_request )->get_data();
	$assert( 1 === $relation['pagination']['total'] && 'Starter' === $relation['items'][0]['title'], 'Normalized relation filter did not constrain public source records.' );

	$raw_request = new WP_REST_Request( 'POST', '/eit/v1/collections/' . $ids['collection'] . '/query' );
	$raw_request->set_header( 'content-type', 'application/json' );
	$raw_request->set_body( wp_json_encode( [ 'provider' => 'cct', 'storage_key' => $fields[1]['storage']['key'] ] ) );
	$assert( 400 === rest_do_request( $raw_request )->get_status(), 'Browser could choose a provider or storage key.' );
	$large_request = new WP_REST_Request( 'POST', '/eit/v1/collections/' . $ids['collection'] . '/query' );
	$large_request->set_header( 'content-type', 'application/json' );
	$large_request->set_body( wp_json_encode( [ 'search' => str_repeat( 'x', 33000 ) ] ) );
	$assert( 413 === rest_do_request( $large_request )->get_status(), 'Collection body limit did not reject an oversized request.' );

	$first_id = $data['request_id'];
	$replayed = rest_do_request( $query_request )->get_data();
	$assert( $first_id !== $replayed['request_id'] && $data['items'] === $replayed['items'], 'Cached response did not preserve data with a fresh request ID.' );
	$assert( ! is_wp_error( $repository->save( $slug, [ 'title' => 'Plus', 'status' => 'publish', $fields[0]['storage']['key'] => 'Plus', $fields[1]['storage']['key'] => 30, $fields[2]['storage']['key'] => 'premium' ] ) ), 'Cache invalidation fixture must save.' );
	$after_change = rest_do_request( $query_request )->get_data();
	$assert( 3 === $after_change['pagination']['total'], 'Content mutation did not invalidate Collection cache.' );

	$scope_user = wp_insert_user( [ 'user_login' => 'eit_collection_scope_user', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'eit-collection-scope@example.invalid', 'role' => 'author' ] );
	$assert( ! is_wp_error( $scope_user ), 'Collection scope verification user must be created.' );
	$scope_user_id = (int) $scope_user;
	$foreign_cct = $repository->save( $slug, [ 'title' => 'Foreign owner', 'status' => 'publish', 'author_id' => $scope_user_id, $fields[0]['storage']['key'] => 'Foreign owner', $fields[1]['storage']['key'] => 999, $fields[2]['storage']['key'] => 'premium' ] );
	$assert( ! is_wp_error( $foreign_cct ), 'Foreign-owner CCT fixture must save.' );
	$scoped_contract = ( new CollectionSurfaceResolver() )->get( $ids['collection'] );
	$scoped_contract['access'] = 'authenticated';
	$scoped_contract['policy'] = [ 'capability' => 'read', 'ownership' => 'own', 'object_scope' => 'entity' ];
	$scoped_request = [ 'page' => 1, 'per_page' => 48, 'search' => '', 'filters' => [], 'sort' => [], 'facets' => [ $ids['tier'] ] ];
	$scoped_cct = ( new CctCollectionProvider() )->query( $scoped_contract, $scoped_request, [ 'user_id' => (int) $admin[0] ] );
	$assert( false === in_array( 'Foreign owner', array_column( $scoped_cct['items'], 'title' ), true ), 'CCT provider ignored compiled ownership scope.' );
	$assert( 2 === ( $scoped_cct['facets'][ $ids['tier'] ]['premium'] ?? 0 ), 'CCT facets included a foreign-owner record.' );
	$assigned_scope = fn( $allowed, $contract, $user_id ) => (int) $admin[0] === $user_id ? [ $saved_ids['Starter'] ] : [];
	add_filter( 'eit_collection_object_scope_ids', $assigned_scope, 10, 3 );
	$assigned_contract = $scoped_contract;
	$assigned_contract['policy'] = [ 'capability' => 'read', 'ownership' => 'any', 'object_scope' => 'assigned' ];
	$assigned_cct = ( new CctCollectionProvider() )->query( $assigned_contract, $scoped_request, [ 'user_id' => (int) $admin[0] ] );
	remove_filter( 'eit_collection_object_scope_ids', $assigned_scope, 10 );
	$assert( 1 === $assigned_cct['total'] && 'Starter' === $assigned_cct['items'][0]['title'], 'CCT provider trusted browser identity instead of compiled assignment scope.' );

	register_post_type( $scope_post_type, [ 'public' => true ] );
	$scope_post_ids[] = wp_insert_post( [ 'post_type' => $scope_post_type, 'post_status' => 'publish', 'post_title' => 'Owned CPT', 'post_author' => (int) $admin[0] ] );
	$scope_post_ids[] = wp_insert_post( [ 'post_type' => $scope_post_type, 'post_status' => 'publish', 'post_title' => 'Foreign CPT', 'post_author' => $scope_user_id ] );
	$choice_field = $factory->make(
		$ids['choices'],
		'Capabilities',
		'multiple_choice',
		[ 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true ], 'validation' => [ 'options' => [ [ 'value' => 'pro', 'label' => 'Pro' ], [ 'value' => 'professional', 'label' => 'Professional' ], [ 'value' => 'shared', 'label' => 'Shared' ] ] ] ]
	);
	$cpt_contract = [
		'blueprint_id' => $ids['blueprint'],
		'entity' => [ 'strategy' => 'cpt', 'mode' => 'structured', 'definition' => [ 'slug' => $scope_post_type ] ],
		'title_field_id' => $ids['title'],
		'fields' => [ $fields[0], $choice_field ],
		'search_field_ids' => [],
		'policy' => [ 'ownership' => 'own', 'object_scope' => 'entity' ],
	];
	$gateway = new EntryStorageGateway();
	$assert( ! is_wp_error( $gateway->save( $cpt_contract, [ $ids['title'] => 'Owned CPT', $ids['choices'] => [ 'pro', 'shared' ] ], $scope_post_ids[0], 'publish', (int) $admin[0] ) ), 'CPT multiple choice values must persist as exact rows.' );
	$assert( ! is_wp_error( $gateway->save( $cpt_contract, [ $ids['title'] => 'Foreign CPT', $ids['choices'] => [ 'professional', 'shared' ] ], $scope_post_ids[1], 'publish', $scope_user_id ) ), 'Second CPT multiple choice fixture must persist.' );
	$assert( [ 'pro', 'shared' ] === get_post_meta( $scope_post_ids[0], $choice_field['storage']['key'], false ), 'CPT multiple choices were serialized instead of normalized into repeated meta rows.' );
	$multi_contract = $cpt_contract;
	$multi_contract['policy'] = [ 'ownership' => 'any', 'object_scope' => 'entity' ];
	$multi_request = [
		'page' => 1,
		'per_page' => 24,
		'search' => '',
		'filters' => [ [ 'field_id' => $ids['choices'], 'operator' => 'in', 'value' => [ 'pro' ] ] ],
		'sort' => [],
		'facets' => [ $ids['choices'] ],
	];
	$multi = ( new CptCollectionProvider() )->query( $multi_contract, $multi_request, [ 'user_id' => (int) $admin[0] ] );
	$assert( 1 === $multi['total'] && 'Owned CPT' === $multi['items'][0]['title'], 'CPT multiple-choice filter used substring or serialized matching: ' . wp_json_encode( $multi ) );
	$assert( 1 === ( $multi['facets'][ $ids['choices'] ]['pro'] ?? 0 ) && 1 === ( $multi['facets'][ $ids['choices'] ]['professional'] ?? 0 ) && 2 === ( $multi['facets'][ $ids['choices'] ]['shared'] ?? 0 ), 'CPT multiple-choice facets did not count exact normalized values.' );
	$cpt_request = [ 'page' => 1, 'per_page' => 24, 'search' => '', 'filters' => [], 'sort' => [], 'facets' => [] ];
	$scoped_cpt = ( new CptCollectionProvider() )->query( $cpt_contract, $cpt_request, [ 'user_id' => (int) $admin[0] ] );
	$assert( 1 === $scoped_cpt['total'] && 'Owned CPT' === $scoped_cpt['items'][0]['title'], 'CPT provider ignored compiled ownership scope.' );

	$html = do_shortcode( '[eit_collection id="' . $ids['collection'] . '"]' );
	$assert( false !== strpos( $html, 'data-eit-collection-item' ) && false === strpos( $html, 'hidden-' ), 'Semantic Collection fallback did not render safely.' );

	echo 'Collection verification passed: ' . $assertions . " assertions.\n";
} finally {
	$cleanup();
}
