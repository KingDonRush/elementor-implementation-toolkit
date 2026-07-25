<?php
/**
 * Parameterized Route validation and runtime resolution contracts.
 */

use EIT\Blueprint\RouteContractValidator;
use EIT\Blueprint\RouteRuntime;
use EIT\Contracts\CollectionProviderInterface;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Registry\RegistryHub;
use PHPUnit\Framework\TestCase;

class ParameterizedRouteContractTest extends TestCase {

	private const FIELD_ID = '11111111-1111-4111-8111-111111111111';
	private const OTHER_FIELD_ID = '22222222-2222-4222-8222-222222222222';

	public function test_validator_accepts_stable_field_placeholder_with_public_collection_authority(): void {
		$codes = array_column( ( new RouteContractValidator() )->validate( ...$this->graph( 'catalog/{' . self::FIELD_ID . '}' ) ), 'code' );

		self::assertNotContains( 'route_path_invalid', $codes );
		self::assertNotContains( 'route_parameter_field_invalid', $codes );
		self::assertNotContains( 'route_presentation_unrenderable', $codes );
	}

	public function test_validator_rejects_raw_keys_private_fields_and_missing_filter_authority(): void {
		$raw_codes = array_column( ( new RouteContractValidator() )->validate( ...$this->graph( 'catalog/{listing_slug}' ) ), 'code' );
		[ $private_nodes, $private_connections ] = $this->graph( 'catalog/{' . self::FIELD_ID . '}' );
		$private_nodes['group']['config']['fields'][0]['exposure']['public'] = false;
		[ $unfiltered_nodes, $unfiltered_connections ] = $this->graph( 'catalog/{' . self::FIELD_ID . '}' );
		$unfiltered_connections = array_values( array_filter( $unfiltered_connections, fn( $edge ) => 'filters' !== $edge['type'] ) );

		self::assertContains( 'route_path_invalid', $raw_codes );
		self::assertContains( 'route_parameter_field_invalid', array_column( ( new RouteContractValidator() )->validate( $private_nodes, $private_connections ), 'code' ) );
		self::assertContains( 'route_parameter_filter_required', array_column( ( new RouteContractValidator() )->validate( $unfiltered_nodes, $unfiltered_connections ), 'code' ) );
	}

	public function test_validator_blocks_patterns_that_can_match_the_same_request(): void {
		[ $nodes, $connections ] = $this->graph( 'catalog/{' . self::FIELD_ID . '}' );
		$nodes['literal-route'] = [ 'type' => 'route', 'config' => [ 'path' => 'catalog/new', 'exposure' => 'public' ] ];
		$nodes['literal-presentation'] = [ 'type' => 'presentation', 'config' => [ 'template_id' => 72 ] ];
		$connections[] = [ 'type' => 'routes', 'from' => 'literal-presentation', 'to' => 'literal-route' ];

		$codes = array_column( ( new RouteContractValidator() )->validate( $nodes, $connections ), 'code' );

		self::assertContains( 'ambiguous_route_pattern', $codes );
	}

	public function test_parameterized_presentation_allows_one_document_plus_its_collection_context(): void {
		[ $nodes, $connections ] = $this->graph( 'catalog/{' . self::FIELD_ID . '}' );
		$nodes['presentation']['config'] = [ 'template_id' => 41, 'document_id' => 42 ];

		$codes = array_column( ( new RouteContractValidator() )->validate( $nodes, $connections ), 'code' );

		self::assertContains( 'route_presentation_unrenderable', $codes );
	}

	public function test_runtime_decodes_bounded_parameters_and_rejects_spoofed_or_unsafe_paths(): void {
		$runtime = $this->runtime_proxy();
		$pattern = 'catalog/{' . self::FIELD_ID . '}';

		self::assertSame( [ self::FIELD_ID => 'cafe-premium' ], $runtime->match( $pattern, 'catalog/cafe-premium' )['parameters'] );
		self::assertSame( [ self::FIELD_ID => 'café' ], $runtime->match( $pattern, 'catalog/caf%C3%A9' )['parameters'] );
		self::assertSame( 'eit_route_request_mismatch', $runtime->match( $pattern, 'catalog' )->get_error_code() );
		self::assertSame( 'eit_route_parameter_invalid', $runtime->match( $pattern, 'catalog/a%2Fb' )->get_error_code() );
		self::assertSame( 'eit_route_parameter_invalid', $runtime->match( $pattern, 'catalog/%ZZ' )->get_error_code() );
	}

	public function test_runtime_marks_cross_blueprint_parameter_and_literal_overlap_as_collision(): void {
		$blueprints = new ParameterizedRouteBlueprintStore(
			[
				[ 'id' => 'owner', 'active_version_id' => 1 ],
				[ 'id' => 'contender', 'active_version_id' => 2 ],
			]
		);
		$artifacts = new ParameterizedRouteArtifactStore(
			[
				1 => [ $this->route_artifact( 'owner-route', 'catalog/{' . self::FIELD_ID . '}' ) ],
				2 => [ $this->route_artifact( 'contender-route', 'catalog/new' ) ],
			]
		);
		$contracts = ( new RouteRuntime( $blueprints, $artifacts, new RegistryHub() ) )->contracts();

		self::assertTrue( $contracts['owner-route']['collision'] );
		self::assertTrue( $contracts['contender-route']['collision'] );
	}

	public function test_runtime_queries_by_field_id_and_requires_one_public_item(): void {
		$provider = new ParameterizedRouteProvider(
			[
				'items' => [ [ 'id' => '17', 'title' => 'Premium item', 'values' => [ self::FIELD_ID => 'premium' ] ] ],
				'total' => 1,
			]
		);
		$runtime = $this->runtime_proxy( $provider );
		$context = $runtime->resolve( $this->route_contract(), $this->presentation_contract(), [ self::FIELD_ID => 'premium' ] );

		self::assertSame( 17, $context['item_id'] );
		self::assertSame( self::FIELD_ID, $provider->last_request['filters'][0]['field_id'] );
		self::assertStringNotContainsString( 'eit_slug', wp_json_encode( $provider->last_request ) );
		self::assertSame( 2, $provider->last_request['per_page'] );
	}

	public function test_runtime_fails_closed_for_ambiguous_and_private_collection_results(): void {
		$provider = new ParameterizedRouteProvider(
			[
				'items' => [ [ 'id' => '17' ], [ 'id' => '18' ] ],
				'total' => 2,
			]
		);
		$runtime = $this->runtime_proxy( $provider );
		$ambiguous = $runtime->resolve( $this->route_contract(), $this->presentation_contract(), [ self::FIELD_ID => 'premium' ] );

		self::assertSame( 'eit_route_item_ambiguous', $ambiguous->get_error_code() );

		$private_runtime = $this->runtime_proxy( $provider, [ 'access' => 'authenticated' ] );
		$private = $private_runtime->resolve( $this->route_contract(), $this->presentation_contract(), [ self::FIELD_ID => 'premium' ] );
		self::assertSame( 'eit_route_context_forbidden', $private->get_error_code() );
	}

	private function graph( string $path ): array {
		$field = [
			'id' => self::FIELD_ID,
			'type' => 'short_text',
			'shape' => 'scalar',
			'exposure' => [ 'public' => true ],
			'indexing' => [ 'filter' => true ],
			'capabilities' => [ 'filter' => true, 'filter_operators' => [ 'equals' ] ],
		];
		$nodes = [
			'route' => [ 'type' => 'route', 'config' => [ 'path' => $path, 'exposure' => 'public' ] ],
			'presentation' => [ 'type' => 'presentation', 'config' => [ 'template_id' => 41 ] ],
			'collection' => [ 'type' => 'collection', 'config' => [ 'access' => 'public' ] ],
			'filter' => [ 'type' => 'filter_surface', 'config' => [ 'fields' => [ self::FIELD_ID ] ] ],
			'entity' => [ 'type' => 'entity', 'config' => [ 'public' => true ] ],
			'group' => [ 'type' => 'field_group', 'config' => [ 'fields' => [ $field ] ] ],
		];
		$connections = [
			[ 'type' => 'routes', 'from' => 'presentation', 'to' => 'route' ],
			[ 'type' => 'presents_collection', 'from' => 'collection', 'to' => 'presentation' ],
			[ 'type' => 'collection_for', 'from' => 'entity', 'to' => 'collection' ],
			[ 'type' => 'filters', 'from' => 'collection', 'to' => 'filter' ],
			[ 'type' => 'entity_fields', 'from' => 'entity', 'to' => 'group' ],
		];
		return [ $nodes, $connections ];
	}

	private function runtime_proxy( ?ParameterizedRouteProvider $provider = null, array $collection_overrides = [] ): ParameterizedRouteRuntimeProxy {
		$provider = $provider ?: new ParameterizedRouteProvider( [ 'items' => [], 'total' => 0 ] );
		$hub = new RegistryHub();
		$hub->collection_providers()->register( $provider );
		$collection = array_replace_recursive( $this->collection_contract(), $collection_overrides );
		$artifacts = new ParameterizedRouteArtifactStore(
			[
				1 => [
					[ 'kind' => 'presentation_contract', 'node_id' => 'presentation', 'payload' => $this->presentation_contract() ],
					[ 'kind' => 'collection_contract', 'node_id' => 'collection', 'payload' => $collection ],
				],
			]
		);
		return new ParameterizedRouteRuntimeProxy( new ParameterizedRouteBlueprintStore( [] ), $artifacts, $hub );
	}

	private function route_contract(): array {
		return [ 'route_id' => 'route', 'presentation_id' => 'presentation', 'blueprint_id' => 'blueprint', 'version_id' => 1 ];
	}

	private function presentation_contract(): array {
		return [ 'sources' => [ [ 'connection' => 'presents_collection', 'node_id' => 'collection' ] ] ];
	}

	private function collection_contract(): array {
		return [
			'collection_id' => 'collection',
			'entity_id' => 'entity',
			'access' => 'public',
			'entity' => [ 'strategy' => 'cct', 'definition' => [ 'slug' => 'items', 'public' => true ], 'adapter' => [] ],
			'provider' => [ 'id' => 'route_test_provider', 'version' => '1.0.0', 'capabilities' => [ 'field_id_filters', 'pagination', 'public_status_only' ], 'required_capabilities' => [ 'field_id_filters', 'pagination' ] ],
			'fields' => [ [ 'id' => self::FIELD_ID, 'type' => 'short_text', 'shape' => 'scalar', 'storage' => [ 'key' => 'eit_slug' ], 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true ], 'capabilities' => [ 'filter' => true ] ] ],
			'filter_field_ids' => [ self::FIELD_ID ],
			'policy' => [ 'ownership' => 'any', 'object_scope' => 'entity' ],
		];
	}

	private function route_artifact( string $id, string $path ): array {
		return [ 'kind' => 'route_contract', 'node_id' => $id, 'payload' => [ 'route_id' => $id, 'path' => $path, 'kind' => 'virtual', 'exposure' => 'public' ] ];
	}
}

class ParameterizedRouteRuntimeProxy extends RouteRuntime {
	public function match( $pattern, $request_path ) {
		return $this->match_route( $pattern, $request_path );
	}

	public function resolve( array $route, array $presentation, array $parameters ) {
		return $this->resolve_parameter_context( $route, $presentation, $parameters );
	}
}

class ParameterizedRouteProvider implements CollectionProviderInterface {
	public $last_request = [];
	private $result;

	public function __construct( array $result ) {
		$this->result = $result;
	}

	public function get_id() {
		return 'route_test_provider';
	}

	public function get_version() {
		return '1.0.0';
	}

	public function get_capabilities() {
		return [ 'field_id_filters', 'pagination', 'public_status_only' ];
	}

	public function query( array $contract, array $request, array $context = [] ) {
		$this->last_request = $request;
		return array_merge( [ 'page' => 1, 'per_page' => 2, 'pages' => 1, 'facets' => [] ], $this->result );
	}

	public function health_check() {
		return [ 'ok' => true, 'version' => '1.0.0' ];
	}
}

class ParameterizedRouteBlueprintStore extends BlueprintStore {
	private $records;

	public function __construct( array $records ) {
		$this->records = $records;
	}

	public function all() {
		return $this->records;
	}
}

class ParameterizedRouteArtifactStore extends ArtifactStore {
	private $records;

	public function __construct( array $records ) {
		$this->records = $records;
	}

	public function for_version( $version_id, $kind = null ) {
		$records = $this->records[ $version_id ] ?? [];
		return null === $kind ? $records : array_values( array_filter( $records, fn( $artifact ) => $kind === ( $artifact['kind'] ?? '' ) ) );
	}
}
