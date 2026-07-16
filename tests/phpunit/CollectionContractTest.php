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

	private function artifact( array $artifacts, string $kind ): array {
		return current( array_filter( $artifacts, fn( $artifact ) => $kind === $artifact['kind'] ) );
	}
}
