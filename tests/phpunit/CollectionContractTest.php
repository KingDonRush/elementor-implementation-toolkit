<?php
/**
 * Pure contracts for compiled Field-ID Collections and Filter Surfaces.
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\Compiler;
use EIT\Blueprint\CoreRegistryFactory;
use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\Uuid;
use EIT\Contracts\CollectionProviderInterface;
use EIT\Registry\RegistryHub;
use PHPUnit\Framework\TestCase;

class CollectionContractTest extends TestCase {

	public function test_core_registry_declares_all_query_providers(): void {
		$providers = ( new CoreRegistryFactory() )->create()->collection_providers()->all();

		self::assertSame( [ 'cct_indexed', 'legacy_dom', 'woocommerce', 'wp_query' ], array_keys( $providers ) );
		self::assertContains( 'field_id_filters', $providers['wp_query']->get_capabilities() );
		self::assertContains( 'exact_token_match', $providers['legacy_dom']->get_capabilities() );
	}

	public function test_collection_and_filters_compile_from_field_ids(): void {
		$blueprint = $this->blueprint();
		$result = ( new Compiler() )->compile( $blueprint );
		$collection = $this->artifact( $result->artifacts(), 'collection_contract' );
		$surface = $this->artifact( $result->artifacts(), 'filter_contract' );
		$price_id = $blueprint['nodes'][1]['config']['fields'][0]['id'];

		self::assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		self::assertSame( 'wp_query', $collection['payload']['provider']['id'] );
		self::assertSame( 'automatic', $collection['payload']['provider']['selection'] );
		self::assertSame( [ 'field_id_filters', 'pagination', 'typed_sort' ], $collection['payload']['provider']['required_capabilities'] );
		self::assertSame( [ $price_id ], $collection['payload']['sort_field_ids'] );
		self::assertSame( $blueprint['nodes'][3]['id'], $collection['payload']['filter_surface_id'] );
		self::assertSame( $price_id, $surface['payload']['controls'][0]['field_id'] );
		self::assertSame( 'range', $surface['payload']['controls'][0]['control'] );
		self::assertContains( 'between', $surface['payload']['controls'][0]['operators'] );
		self::assertArrayNotHasKey( 'storage_key', $surface['payload']['controls'][0] );
	}

	public function test_invalid_query_decisions_block_compilation(): void {
		$blueprint = $this->blueprint();
		$blueprint['nodes'][0]['config']['public'] = false;
		$blueprint['nodes'][2]['config']['page_size'] = 80;
		$blueprint['nodes'][2]['config']['default_sort']['direction'] = 'sideways';
		$errors = ( new BlueprintValidator() )->validate( $blueprint )->errors();
		$codes = array_column( $errors, 'code' );

		self::assertContains( 'collection_public_entity_required', $codes );
		self::assertContains( 'collection_page_size_invalid', $codes );
		self::assertContains( 'collection_sort_direction_invalid', $codes );
	}

	public function test_invalid_numeric_filter_limits_are_rejected(): void {
		$blueprint = $this->blueprint();
		$blueprint['nodes'][1]['config']['fields'][0]['validation'] = [ 'required' => false, 'min' => 10, 'max' => 0, 'step' => 0 ];
		$codes = array_column( ( new BlueprintValidator() )->validate( $blueprint )->errors(), 'code' );

		self::assertContains( 'numeric_range_invalid', $codes );
		self::assertContains( 'numeric_step_invalid', $codes );
	}

	public function test_private_fields_cannot_compile_into_any_collection_query_surface(): void {
		$blueprint = $this->blueprint();
		$private = ( new FieldContractFactory( new FieldPrimitiveRegistry() ) )->make(
			Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:private' ),
			'Internal note',
			'short_text',
			[ 'exposure' => [ 'public' => false ], 'indexing' => [ 'search' => true, 'filter' => true, 'sort' => true ] ]
		);
		$blueprint['nodes'][1]['config']['fields'][] = $private;
		$compiled = ( new Compiler() )->compile( $blueprint );
		$contract = $this->artifact( $compiled->artifacts(), 'collection_contract' )['payload'];

		self::assertTrue( $compiled->is_valid(), wp_json_encode( $compiled->errors() ) );
		self::assertNotContains( $private['id'], array_column( $contract['fields'], 'id' ) );
		self::assertNotContains( $private['id'], $contract['projection_field_ids'] );
		self::assertNotContains( $private['id'], $contract['filter_field_ids'] );
		self::assertNotContains( $private['id'], $contract['sort_field_ids'] );
		self::assertNotContains( $private['id'], $contract['search_field_ids'] );

		$blueprint['nodes'][2]['config']['projection_field_ids'] = [ $private['id'] ];
		$blueprint['nodes'][2]['config']['sort_field_ids'] = [ $private['id'] ];
		$blueprint['nodes'][3]['config']['fields'] = [ $private['id'] ];
		$blueprint['nodes'][3]['config']['facet_fields'] = [ $private['id'] ];
		$codes = array_column( ( new BlueprintValidator() )->validate( $blueprint )->errors(), 'code' );
		self::assertContains( 'collection_projection_invalid', $codes );
		self::assertContains( 'collection_sort_field_invalid', $codes );
		self::assertContains( 'filter_surface_field_invalid', $codes );
		self::assertContains( 'filter_surface_facet_invalid', $codes );
	}

	public function test_collection_policy_preserves_scope_and_cpt_facets_are_capped(): void {
		$blueprint = $this->blueprint();
		$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
		$facet_ids = [ $blueprint['nodes'][1]['config']['fields'][0]['id'] ];
		for ( $index = 1; $index <= 3; ++$index ) {
			$field = $factory->make(
				Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:facet:' . $index ),
				'Facet ' . $index,
				'single_choice',
				[ 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true ] ]
			);
			$blueprint['nodes'][1]['config']['fields'][] = $field;
			$facet_ids[] = $field['id'];
		}
		$blueprint['nodes'][2]['config']['access'] = 'authenticated';
		$blueprint['nodes'][3]['config']['fields'] = $facet_ids;
		$blueprint['nodes'][3]['config']['facet_fields'] = $facet_ids;
		$policy_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:policy' );
		$blueprint['nodes'][] = [ 'id' => $policy_id, 'type' => 'policy', 'lane' => 'governance', 'name' => 'Assigned listings', 'config' => [ 'read_capability' => 'read', 'ownership' => 'own', 'object_scope' => 'assigned' ] ];
		$blueprint['connections'][] = [ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:edge:policy' ), 'type' => 'governs_collection', 'from' => $policy_id, 'to' => $blueprint['nodes'][2]['id'] ];

		$result = ( new Compiler() )->compile( $blueprint );
		$collection = $this->artifact( $result->artifacts(), 'collection_contract' )['payload'];
		$surface = $this->artifact( $result->artifacts(), 'filter_contract' )['payload'];

		self::assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		self::assertSame( 'own', $collection['policy']['ownership'] );
		self::assertSame( 'assigned', $collection['policy']['object_scope'] );
		self::assertCount( 3, $surface['facet_field_ids'] );
	}

	public function test_registered_sdk_provider_is_reachable_from_entity_contract(): void {
		$registries = ( new CoreRegistryFactory() )->create();
		$registries->collection_providers()->register(
			$this->provider( 'sdk_records', '2.3.0', [ 'pagination', 'field_id_filters', 'facets', 'typed_sort' ] )
		);
		$blueprint = $this->blueprint_with_provider(
			[
				'id' => 'sdk_records',
				'required_capabilities' => [ 'field_id_filters', 'facets' ],
			]
		);

		$result = ( new Compiler( null, null, null, $registries ) )->compile( $blueprint );
		$provider = $this->artifact( $result->artifacts(), 'collection_contract' )['payload']['provider'];

		self::assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		self::assertSame( 'sdk_records', $provider['id'] );
		self::assertSame( '2.3.0', $provider['version'] );
		self::assertSame( [ 'facets', 'field_id_filters', 'pagination', 'typed_sort' ], $provider['capabilities'] );
		self::assertSame( [ 'facets', 'field_id_filters', 'pagination', 'typed_sort' ], $provider['required_capabilities'] );
		self::assertSame( 'explicit', $provider['selection'] );
	}

	public function test_connected_adapter_is_authority_for_explicit_provider_contract(): void {
		$registries = ( new CoreRegistryFactory() )->create();
		$registries->collection_providers()->register(
			$this->provider( 'adapter_records', '1.4.0', [ 'pagination', 'facets', 'field_id_filters', 'typed_sort' ] )
		);
		$blueprint = $this->blueprint_with_provider( [ 'id' => 'missing_entity_choice' ] );
		$entity_id = $blueprint['nodes'][0]['id'];
		$adapter_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:adapter' );
		$blueprint['nodes'][] = [
			'id' => $adapter_id,
			'type' => 'adapter',
			'lane' => 'governance',
			'name' => 'External records',
			'config' => [
				'adapter_id' => 'legacy_dom',
				'collection_provider' => [ 'id' => 'adapter_records', 'required_capabilities' => [ 'facets' ] ],
			],
		];
		$blueprint['connections'][] = [
			'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:edge:adapter' ),
			'type' => 'adapts',
			'from' => $adapter_id,
			'to' => $entity_id,
		];

		$result = ( new Compiler( null, null, null, $registries ) )->compile( $blueprint );
		$collection = $this->artifact( $result->artifacts(), 'collection_contract' )['payload'];

		self::assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		self::assertSame( 'adapter_records', $collection['provider']['id'] );
		self::assertSame( 'legacy_dom', $collection['entity']['adapter']['id'] );
	}

	public function test_explicit_provider_contract_fails_closed(): void {
		$registries = ( new CoreRegistryFactory() )->create();
		$registries->collection_providers()->register( $this->provider( 'offline_records', '1.0.0', [ 'pagination' ], false ) );
		$registries->collection_providers()->register( $this->provider( 'unpaged_records', '1.0.0', [ 'facets' ] ) );

		self::assertContains(
			'collection_provider_missing',
			$this->compile_codes( $this->blueprint_with_provider( [ 'id' => 'missing_records' ] ), $registries )
		);
		self::assertContains(
			'collection_provider_unhealthy',
			$this->compile_codes( $this->blueprint_with_provider( [ 'id' => 'offline_records' ] ), $registries )
		);
		self::assertContains(
			'collection_provider_incompatible',
			$this->compile_codes( $this->blueprint_with_provider( [ 'id' => 'unpaged_records' ] ), $registries )
		);
		self::assertContains(
			'collection_provider_contract_invalid',
			$this->compile_codes(
				$this->blueprint_with_provider( [ 'id' => 'wp_query', 'query_args' => [ 'post_status' => 'publish' ] ] ),
				$registries
			)
		);
	}

	public function test_cct_multiple_choice_cannot_compile_a_facet_without_indexed_counts(): void {
		$blueprint = $this->blueprint();
		$field = ( new FieldContractFactory( new FieldPrimitiveRegistry() ) )->make(
			Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:cct-multiple' ),
			'Amenities',
			'multiple_choice',
			[ 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true ] ]
		);
		$blueprint['nodes'][0]['config']['high_volume'] = true;
		$blueprint['nodes'][0]['config']['storage'] = [ 'strategy' => 'cct', 'override_reason' => 'Operational records require dedicated storage.' ];
		$blueprint['nodes'][1]['config']['fields'] = [ $field ];
		$blueprint['nodes'][2]['config']['default_sort'] = [];
		$blueprint['nodes'][3]['config']['fields'] = [ $field['id'] ];
		$blueprint['nodes'][3]['config']['facet_fields'] = [ $field['id'] ];

		$codes = array_column( ( new BlueprintValidator() )->validate( $blueprint )->errors(), 'code' );

		self::assertContains( 'filter_surface_facet_unsupported', $codes );
	}

	private function blueprint(): array {
		$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
		$entity_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:entity' );
		$group_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:fields' );
		$collection_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:surface' );
		$filter_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:filters' );
		$price = $factory->make(
			Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:price' ),
			'Price',
			'decimal',
			[ 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true, 'sort' => true ] ]
		);
		return [
			'api_version' => BlueprintValidator::API_VERSION,
			'kind' => BlueprintValidator::KIND,
			'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:blueprint' ),
			'name' => 'Collection system',
			'version' => 1,
			'nodes' => [
				[ 'id' => $entity_id, 'type' => 'entity', 'lane' => 'data', 'name' => 'Listing', 'config' => [ 'mode' => 'structured', 'public' => true ] ],
				[ 'id' => $group_id, 'type' => 'field_group', 'lane' => 'data', 'name' => 'Listing fields', 'config' => [ 'fields' => [ $price ] ] ],
				[ 'id' => $collection_id, 'type' => 'collection', 'lane' => 'experience', 'name' => 'Listings', 'config' => [ 'page_size' => 24, 'access' => 'public', 'default_sort' => [ 'field_id' => $price['id'], 'direction' => 'asc' ] ] ],
				[ 'id' => $filter_id, 'type' => 'filter_surface', 'lane' => 'experience', 'name' => 'Listing filters', 'config' => [ 'fields' => [ $price['id'] ], 'facet_fields' => [] ] ],
			],
			'connections' => [
				[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:edge:fields' ), 'type' => 'entity_fields', 'from' => $entity_id, 'to' => $group_id ],
				[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:edge:entity' ), 'type' => 'collection_for', 'from' => $entity_id, 'to' => $collection_id ],
				[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:edge:filters' ), 'type' => 'filters', 'from' => $collection_id, 'to' => $filter_id ],
			],
		];
	}

	private function blueprint_with_provider( array $provider ): array {
		$blueprint = $this->blueprint();
		$blueprint['nodes'][0]['config']['collection_provider'] = $provider;
		return $blueprint;
	}

	private function compile_codes( array $blueprint, RegistryHub $registries ): array {
		$result = ( new Compiler( null, null, null, $registries ) )->compile( $blueprint );
		return array_column( $result->errors(), 'code' );
	}

	private function provider( $id, $version, array $capabilities, $healthy = true ) {
		return new class( $id, $version, $capabilities, $healthy ) implements CollectionProviderInterface {
			private $id;
			private $version;
			private $capabilities;
			private $healthy;

			public function __construct( $id, $version, array $capabilities, $healthy ) {
				$this->id = $id;
				$this->version = $version;
				$this->capabilities = $capabilities;
				$this->healthy = (bool) $healthy;
			}

			public function get_id() {
				return $this->id;
			}

			public function get_version() {
				return $this->version;
			}

			public function get_capabilities() {
				return $this->capabilities;
			}

			public function query( array $contract, array $request, array $context = [] ) {
				return [ 'items' => [], 'pagination' => [ 'page' => 1, 'per_page' => 24, 'total' => 0, 'total_pages' => 0 ], 'facets' => [] ];
			}

			public function health_check() {
				return [ 'ok' => $this->healthy, 'version' => $this->version ];
			}
		};
	}

	private function artifact( array $artifacts, string $kind ): array {
		return current( array_filter( $artifacts, fn( $artifact ) => $kind === $artifact['kind'] ) );
	}
}
