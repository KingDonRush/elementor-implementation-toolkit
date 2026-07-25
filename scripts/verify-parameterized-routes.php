<?php
/**
 * Verifies parameterized Route rewrites and public CCT item resolution in WordPress.
 */

use EIT\Blueprint\RouteRuntime;
use EIT\CCT\CurrentItemContext;
use EIT\CCT\DefinitionManager;
use EIT\CCT\Repository;
use EIT\CCT\SchemaManager;
use EIT\Collection\CctCollectionProvider;
use EIT\Contracts\PresentationAdapterInterface;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Registry\ExtensionContract;
use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eit_route_qa_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

class EitRouteQaBlueprintStore extends BlueprintStore {
	public function all() {
		return [ [ 'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'active_version_id' => 1 ] ];
	}
}

class EitRouteQaArtifactStore extends ArtifactStore {
	private $records;

	public function __construct( array $records ) {
		$this->records = $records;
	}

	public function for_version( $version_id, $kind = null ) {
		return null === $kind ? $this->records : array_values( array_filter( $this->records, fn( $artifact ) => $kind === $artifact['kind'] ) );
	}
}

class EitRouteQaPresentationAdapter implements PresentationAdapterInterface {
	public function get_id() {
		return 'route_qa';
	}

	public function get_version() {
		return '1.0.0';
	}

	public function get_capabilities() {
		return [ 'parameterized_item_context' ];
	}

	public function compile( array $presentation, array $context = [] ) {
		return [];
	}

	public function render( array $contract, array $context = [] ) {
		$item = CurrentItemContext::item();
		return $item && (int) $item['id'] === (int) ( $context['item_id'] ?? 0 )
			? '<article>' . esc_html( $item['title'] ) . '</article>'
			: '';
	}

	public function health_check() {
		return [ 'ok' => true, 'version' => '1.0.0' ];
	}
}

$type = 'eit_route_qa';
$field_id = '11111111-1111-4111-8111-111111111111';
$route_id = '22222222-2222-4222-8222-222222222222';
$presentation_id = '33333333-3333-4333-8333-333333333333';
$collection_id = '44444444-4444-4444-8444-444444444444';
$entity_id = '55555555-5555-4555-8555-555555555555';
$path = 'route-qa/{' . $field_id . '}';
$old_checksum = get_option( RouteRuntime::CHECKSUM_OPTION, null );
global $wp, $wp_query, $wp_rewrite;
$old_request = $wp->request ?? '';
$old_route_var = get_query_var( RouteRuntime::QUERY_VAR );
$old_rules = $wp_rewrite->extra_rules_top;
$old_compiled_rules = $wp_rewrite->rules;
$old_rewrite_option = get_option( 'rewrite_rules', [] );

if ( DefinitionManager::get( $type ) ) {
	DefinitionManager::archive( $type );
	DefinitionManager::delete_permanently( $type );
} elseif ( SchemaManager::table_exists( $type ) ) {
	SchemaManager::drop_table( $type );
}

try {
	$created = DefinitionManager::save(
		[
			'slug' => $type,
			'singular' => 'Route QA Item',
			'plural' => 'Route QA Items',
			'public' => true,
			'fields' => [ [ 'key' => 'route_key', 'label' => 'Route key', 'type' => 'text', 'filterable' => true ] ],
		]
	);
	eit_route_qa_assert( ! is_wp_error( $created ), 'Could not create the disposable Route CCT.' );
	$item_id = ( new Repository() )->save( $type, [ 'title' => 'Resolved item', 'status' => 'publish', 'route_key' => 'alpha' ] );
	eit_route_qa_assert( ! is_wp_error( $item_id ), 'Could not create the disposable Route item.' );

	$provider = new CctCollectionProvider();
	$adapter = new EitRouteQaPresentationAdapter();
	$hub = new RegistryHub();
	$hub->collection_providers()->register( $provider );
	$hub->presentation_adapters()->register( $adapter );
	$provider_contract = ExtensionContract::snapshot( $provider );
	$adapter_contract = ExtensionContract::snapshot( $adapter );
	$field = [
		'id' => $field_id,
		'name' => 'Route key',
		'type' => 'short_text',
		'shape' => 'scalar',
		'storage' => [ 'key' => 'route_key' ],
		'exposure' => [ 'public' => true ],
		'indexing' => [ 'filter' => true ],
		'capabilities' => [ 'filter' => true ],
	];
	$records = [
		[ 'kind' => 'route_contract', 'node_id' => $route_id, 'payload' => [ 'route_id' => $route_id, 'name' => 'Route QA', 'presentation_id' => $presentation_id, 'path' => $path, 'kind' => 'virtual', 'exposure' => 'public' ] ],
		[ 'kind' => 'presentation_contract', 'node_id' => $presentation_id, 'payload' => [ 'name' => 'Route QA presentation', 'adapter' => $adapter_contract, 'sources' => [ [ 'connection' => 'presents_collection', 'node_id' => $collection_id ] ] ] ],
		[ 'kind' => 'collection_contract', 'node_id' => $collection_id, 'payload' => [
			'collection_id' => $collection_id,
			'entity_id' => $entity_id,
			'access' => 'public',
			'entity' => [ 'strategy' => 'cct', 'definition' => [ 'slug' => $type, 'public' => true ], 'adapter' => [] ],
			'provider' => array_merge( $provider_contract, [ 'required_capabilities' => [ 'field_id_filters', 'pagination' ] ] ),
			'fields' => [ $field ],
			'filter_field_ids' => [ $field_id ],
			'policy' => [ 'ownership' => 'any', 'object_scope' => 'entity' ],
		] ],
	];
	$runtime = new RouteRuntime( new EitRouteQaBlueprintStore(), new EitRouteQaArtifactStore( $records ), $hub );
	$expected_rules = [ strtolower( $path ) => $route_id ];
	update_option( RouteRuntime::CHECKSUM_OPTION, hash( 'sha256', wp_json_encode( $expected_rules ) ), false );
	$runtime->register_routes();
	$rewrite = '^route\-qa/([A-Za-z0-9._~%-]{1,200})/?$';
	eit_route_qa_assert( isset( $wp_rewrite->extra_rules_top[ $rewrite ] ), 'Parameterized rewrite was not registered.' );
	eit_route_qa_assert( 'index.php?eit_route=' . $route_id === $wp_rewrite->extra_rules_top[ $rewrite ], 'Rewrite exposed parameter query vars or lost the stable Route ID.' );

	set_query_var( RouteRuntime::QUERY_VAR, $route_id );
	$wp->request = 'route-qa/alpha';
	$wp_query->is_404 = true;
	$runtime->prepare_current();
	$context = RouteRuntime::current_context();
	eit_route_qa_assert( (string) $item_id === $context['item_id'], 'Route did not resolve the unique public item.' );
	eit_route_qa_assert( '<article>Resolved item</article>' === RouteRuntime::current_content(), 'Presentation did not receive the resolved CCT item context.' );
	eit_route_qa_assert( false === $wp_query->is_404, 'A resolved Route remained a 404.' );

	( new Repository() )->save( $type, [ 'title' => 'Native collision', 'status' => 'publish', 'route_key' => 'beta' ] );
	$wp->request = 'route-qa/beta';
	update_option( 'rewrite_rules', [ '^route-qa/beta/?$' => 'index.php?pagename=claimed-by-wordpress' ], false );
	$wp_rewrite->rules = null;
	( new RouteRuntime( new EitRouteQaBlueprintStore(), new EitRouteQaArtifactStore( $records ), $hub ) )->prepare_current();
	eit_route_qa_assert( '' === RouteRuntime::current_content(), 'A native WordPress rewrite collision was not blocked.' );
	update_option( 'rewrite_rules', $old_rewrite_option, false );
	$wp_rewrite->rules = $old_compiled_rules;
	$wp->request = 'route-qa/alpha';

	( new Repository() )->save( $type, [ 'title' => 'Ambiguous item', 'status' => 'publish', 'route_key' => 'alpha' ] );
	$wp_query->is_404 = true;
	( new RouteRuntime( new EitRouteQaBlueprintStore(), new EitRouteQaArtifactStore( $records ), $hub ) )->prepare_current();
	eit_route_qa_assert( '' === RouteRuntime::current_content(), 'An ambiguous Route selected one item instead of failing closed.' );

	$wp->request = 'route-qa/a%2Fb';
	( new RouteRuntime( new EitRouteQaBlueprintStore(), new EitRouteQaArtifactStore( $records ), $hub ) )->prepare_current();
	eit_route_qa_assert( '' === RouteRuntime::current_content(), 'An encoded slash was accepted as a Route parameter.' );
} finally {
	$wp->request = $old_request;
	set_query_var( RouteRuntime::QUERY_VAR, $old_route_var );
	$wp_rewrite->extra_rules_top = $old_rules;
	update_option( 'rewrite_rules', $old_rewrite_option, false );
	$wp_rewrite->rules = $old_compiled_rules;
	if ( null === $old_checksum ) {
		delete_option( RouteRuntime::CHECKSUM_OPTION );
	} else {
		update_option( RouteRuntime::CHECKSUM_OPTION, $old_checksum, false );
	}
	if ( DefinitionManager::get( $type ) ) {
		DefinitionManager::archive( $type );
		DefinitionManager::delete_permanently( $type );
	} elseif ( SchemaManager::table_exists( $type ) ) {
		SchemaManager::drop_table( $type );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( 'Parameterized rewrite, stable Field ID resolution, CCT context, native collision, ambiguity and encoded-segment rejection verified.' );
}
