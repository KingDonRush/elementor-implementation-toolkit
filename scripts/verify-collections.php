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
	'group' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:group' ),
	'collection' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:surface' ),
	'filter' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:filters' ),
	'title' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:title' ),
	'price' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:price' ),
	'tier' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:tier' ),
	'secret' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:secret' ),
	'agent' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:agent' ),
];
$slug = 'eit_collection_verify';

$cleanup = function () use ( $ids, $slug ) {
	global $wpdb;

	foreach ( [ Tables::RELATIONS, Tables::MULTIVALUES, Tables::RECONCILIATIONS, Tables::ROLLBACKS, Tables::RUNS, Tables::BINDINGS, Tables::ARTIFACTS, Tables::CHANGE_SETS, Tables::VERSIONS ] as $table_key ) {
		$wpdb->delete( Tables::name( $table_key ), [ 'blueprint_id' => $ids['blueprint'] ] );
	}
	$wpdb->delete( Tables::name( Tables::BLUEPRINTS ), [ 'id' => $ids['blueprint'] ] );
	$locks = Tables::name( Tables::LOCKS );
	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$locks}` WHERE resource_key LIKE %s", '%' . $wpdb->esc_like( $ids['blueprint'] ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	CctSchema::drop_table( $slug );
	delete_option( 'eit_col_gen_' . substr( hash( 'sha256', $ids['entity'] ), 0, 24 ) );
	RuntimeDefinitionProvider::invalidate();
};

try {
	$cleanup();
	$assert( true === SchemaManager::install(), 'Toolkit infrastructure must install.' );
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
			[ 'id' => $ids['group'], 'type' => 'field_group', 'lane' => 'data', 'name' => 'Catalog fields', 'config' => [ 'fields' => $fields ] ],
			[ 'id' => $ids['collection'], 'type' => 'collection', 'lane' => 'experience', 'name' => 'Public catalog', 'config' => [
				'page_size' => 2,
				'access' => 'public',
				'default_sort' => [ 'field_id' => $ids['price'], 'direction' => 'asc' ],
				'explain' => true,
			] ],
			[ 'id' => $ids['filter'], 'type' => 'filter_surface', 'lane' => 'experience', 'name' => 'Catalog filters', 'config' => [
				'fields' => [ $ids['price'], $ids['tier'], $ids['agent'] ],
				'facet_fields' => [ $ids['tier'], $ids['agent'] ],
				'url_state' => true,
				'active_chips' => true,
			] ],
		],
		'connections' => [
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:edge:fields' ), 'type' => 'entity_fields', 'from' => $ids['entity'], 'to' => $ids['group'] ],
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:edge:surface' ), 'type' => 'collection_for', 'from' => $ids['entity'], 'to' => $ids['collection'] ],
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:collection:edge:filters' ), 'type' => 'filters', 'from' => $ids['collection'], 'to' => $ids['filter'] ],
		],
	];

	$lifecycle = BlueprintModule::lifecycle();
	$assert( ! is_wp_error( $lifecycle->save_draft( $document ) ), 'Collection Blueprint draft must save.' );
	$prepared = $lifecycle->prepare( $ids['blueprint'], 1 );
	$assert( ! is_wp_error( $prepared ) && ! empty( $prepared['confirmation_token'] ), 'Collection impact must prepare.' );
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
	$assert( 2 === count( $data['explain'] ) && ! empty( $data['explain'][0]['checks'][0]['result'] ), 'Explain Why did not prove the applied comparison.' );
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

	$html = do_shortcode( '[eit_collection id="' . $ids['collection'] . '"]' );
	$assert( false !== strpos( $html, 'data-eit-collection-item' ) && false === strpos( $html, 'hidden-' ), 'Semantic Collection fallback did not render safely.' );

	echo 'Collection verification passed: ' . $assertions . " assertions.\n";
} finally {
	$cleanup();
}
