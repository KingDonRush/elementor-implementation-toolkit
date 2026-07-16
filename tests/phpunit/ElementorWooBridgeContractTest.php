<?php
/**
 * Pure contracts for the Elementor presentation and WooCommerce adapter bridge.
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\EntryContractCompiler;
use EIT\Blueprint\Uuid;
use EIT\Collection\CollectionRequestValidator;
use EIT\Collection\WooCollectionProvider;
use EIT\Registry\RegistryHub;
use EIT\Woo\WooFieldContractCatalog;
use EIT\Woo\WooStorageAdapter;
use EIT\Woo\WooValueGateway;
use PHPUnit\Framework\TestCase;

class ElementorWooBridgeContractTest extends TestCase {

	public function test_woo_catalog_uses_stable_field_ids_and_honest_query_capabilities(): void {
		$fields = ( new WooFieldContractCatalog() )->all();
		$by_key = $this->fields_by_storage( $fields );

		self::assertCount( 13, $fields );
		self::assertSame( Uuid::v5( Uuid::LEGACY_NAMESPACE, 'woocommerce:product:sku' ), $by_key['sku']['id'] );
		self::assertTrue( $by_key['price']['indexing']['sort'] );
		self::assertFalse( $by_key['price']['indexing']['filter'] );
		self::assertSame( [ 'equals' ], $by_key['stock_status']['capabilities']['filter_operators'] );
		self::assertSame( [ 'in' ], $by_key['category']['capabilities']['filter_operators'] );
		self::assertTrue( $by_key['permalink']['validation']['read_only'] );
		self::assertFalse( $by_key['stock_quantity']['exposure']['public'] );
		self::assertContains( 'shop_manager', $by_key['stock_quantity']['exposure']['roles'] );
	}

	public function test_woo_gateway_writes_only_supported_crud_setters_and_saves_once(): void {
		$product = new class() {
			public $name = '';
			public $categories = [];
			public $saves = 0;

			public function set_name( $value ) {
				$this->name = $value;
			}

			public function set_category_ids( $value ) {
				$this->categories = $value;
			}

			public function save() {
				++$this->saves;
				return 42;
			}
		};
		$gateway = new WooValueGateway( fn() => $product );
		$result = $gateway->write( 42, [ 'name' => '  New product  ', 'category' => [ '7', 0, 9 ] ] );

		self::assertSame( 42, $result );
		self::assertSame( 'New product', $product->name );
		self::assertSame( [ 7, 9 ], $product->categories );
		self::assertSame( 1, $product->saves );
		self::assertSame( 'eit_woo_field_read_only', $gateway->write( 42, [ 'price' => 10 ] )->get_error_code() );
	}

	public function test_woo_gateway_creates_products_through_a_crud_object(): void {
		$product = new class() {
			public $name = '';
			public $status = '';

			public function set_name( $value ) {
				$this->name = $value;
			}

			public function set_status( $value ) {
				$this->status = $value;
			}

			public function save() {
				return 77;
			}
		};
		$gateway = new WooValueGateway( null, fn() => $product );

		self::assertSame( 77, $gateway->create( [ 'name' => 'New product', 'status' => 'publish' ] ) );
		self::assertSame( 'New product', $product->name );
		self::assertSame( 'publish', $product->status );
	}

	public function test_adapter_catalog_fields_are_valid_blueprint_query_references(): void {
		$catalog = $this->fields_by_storage( ( new WooFieldContractCatalog() )->all() );
		$blueprint = $this->woo_blueprint( $catalog['category']['id'], $catalog['name']['id'] );
		$registries = $this->healthy_woo_registries();
		$result = ( new BlueprintValidator( null, null, null, $registries ) )->validate( $blueprint );

		self::assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
	}

	public function test_declared_woo_filter_operators_reject_unsupported_negation(): void {
		$field = $this->fields_by_storage( ( new WooFieldContractCatalog() )->all() )['stock_status'];
		$contract = [
			'provider' => [ 'id' => 'woocommerce' ],
			'page_size' => 24,
			'filter_field_ids' => [ $field['id'] ],
			'sort_field_ids' => [],
			'search_field_ids' => [],
			'default_sort' => [ 'field_id' => '', 'direction' => 'asc' ],
			'filter_surface' => [ 'facet_field_ids' => [] ],
			'fields' => [ $field ],
		];
		$request = [ 'filters' => [ [ 'field_id' => $field['id'], 'operator' => 'not_equals', 'value' => 'instock' ] ] ];
		$result = ( new CollectionRequestValidator() )->validate( $contract, $request );

		self::assertSame( 'eit_collection_filter_not_allowed', $result->get_error_code() );
	}

	public function test_woo_provider_uses_public_query_objects_for_results_and_facets(): void {
		$fields = $this->fields_by_storage( ( new WooFieldContractCatalog() )->all() );
		$products = [ $this->product( 1, 'First', 'instock' ), $this->product( 2, 'Second', 'outofstock' ) ];
		$queries = [];
		$factory = function ( $args ) use ( &$queries, $products ) {
			$queries[] = $args;
			return new class( $args, $products ) {
				private $args;
				private $products;

				public function __construct( $args, $products ) {
					$this->args = $args;
					$this->products = $products;
				}

				public function get_products() {
					return empty( $this->args['paginate'] )
						? $this->products
						: (object) [ 'products' => $this->products, 'total' => 2, 'max_num_pages' => 1 ];
				}
			};
		};
		$field = $fields['stock_status'];
		$contract = [ 'fields' => [ $field ] ];
		$request = [ 'page' => 1, 'per_page' => 24, 'search' => '', 'filters' => [], 'sort' => [], 'facets' => [ $field['id'] ] ];
		$result = ( new WooCollectionProvider( null, $factory ) )->query( $contract, $request );

		self::assertSame( 2, $result['total'] );
		self::assertSame( 1, $result['facets'][ $field['id'] ]['instock'] );
		self::assertSame( 1, $result['facets'][ $field['id'] ]['outofstock'] );
		self::assertCount( 2, $queries );
		self::assertSame( CollectionRequestValidator::MAX_PROVIDER_SCAN + 1, $queries[1]['limit'] );
		self::assertFalse( $queries[1]['paginate'] );
	}

	public function test_woo_entry_contract_exposes_only_fields_with_a_supported_editor(): void {
		$entity_id = $this->id( 'woo-entry:entity' );
		$entry_id = $this->id( 'woo-entry:surface' );
		$policy_id = $this->id( 'woo-entry:policy' );
		$fields = ( new WooFieldContractCatalog() )->all();
		$nodes = [
			$entity_id => [ 'id' => $entity_id, 'type' => 'entity', 'name' => 'Products', 'config' => [] ],
			$entry_id => [ 'id' => $entry_id, 'type' => 'entry_surface', 'name' => 'Product workspace', 'config' => [ 'operations' => [ 'create', 'update' ] ] ],
			$policy_id => [ 'id' => $policy_id, 'type' => 'policy', 'name' => 'Product editors', 'config' => [ 'ownership' => 'any' ] ],
		];
		$connections = [
			[ 'type' => 'entry_for', 'from' => $entity_id, 'to' => $entry_id ],
			[ 'type' => 'governs_entry', 'from' => $policy_id, 'to' => $entry_id ],
		];
		$entities = [ $entity_id => [ 'strategy' => 'adapter', 'name' => 'Products', 'definition' => [], 'adapter' => [ 'id' => 'woocommerce' ], 'fields' => $fields ] ];
		$contract = ( new EntryContractCompiler() )->compile( $nodes[ $entry_id ], $nodes, $connections, $entities );
		$storage_keys = array_column( array_column( $contract['fields'], 'storage' ), 'key' );

		self::assertContains( 'name', $storage_keys );
		self::assertContains( 'regular_price', $storage_keys );
		self::assertNotContains( 'price', $storage_keys );
		self::assertNotContains( 'permalink', $storage_keys );
		self::assertNotContains( 'category', $storage_keys );
	}

	public function test_woo_entry_validation_accepts_stable_fields_but_blocks_guest_product_creation(): void {
		$fields = $this->fields_by_storage( ( new WooFieldContractCatalog() )->all() );
		$blueprint = $this->woo_blueprint( $fields['category']['id'], $fields['name']['id'] );
		$entity_id = $blueprint['nodes'][0]['id'];
		$entry_id = $this->id( 'woo:entry' );
		$policy_id = $this->id( 'woo:entry-policy' );
		$blueprint['nodes'][] = [
			'id' => $entry_id,
			'type' => 'entry_surface',
			'lane' => 'experience',
			'name' => 'Product workspace',
			'config' => [
				'operations' => [ 'create', 'update' ],
				'initial_status' => 'draft',
				'field_ids' => [ $fields['name']['id'] ],
				'title_field_id' => $fields['name']['id'],
				'steps' => [],
				'conditions' => [],
				'actions' => [],
				'guest' => [ 'enabled' => false ],
			],
		];
		$blueprint['nodes'][] = [ 'id' => $policy_id, 'type' => 'policy', 'lane' => 'governance', 'name' => 'Product editors', 'config' => [ 'capability' => 'edit_products', 'ownership' => 'any' ] ];
		$blueprint['connections'][] = [ 'id' => $this->id( 'woo:edge:entry' ), 'type' => 'entry_for', 'from' => $entity_id, 'to' => $entry_id ];
		$blueprint['connections'][] = [ 'id' => $this->id( 'woo:edge:entry-policy' ), 'type' => 'governs_entry', 'from' => $policy_id, 'to' => $entry_id ];
		$validator = new BlueprintValidator( null, null, null, $this->healthy_woo_registries() );

		self::assertTrue( $validator->validate( $blueprint )->is_valid() );
		$blueprint['nodes'][4]['config']['guest'] = [ 'enabled' => true, 'moderation_status' => 'draft' ];
		$codes = array_column( $validator->validate( $blueprint )->errors(), 'code' );
		self::assertContains( 'adapter_guest_intake_forbidden', $codes );
	}

	private function woo_blueprint( $category_id, $name_id ): array {
		$entity_id = $this->id( 'woo:entity' );
		$adapter_id = $this->id( 'woo:adapter' );
		$collection_id = $this->id( 'woo:collection' );
		$filter_id = $this->id( 'woo:filters' );
		return [
			'api_version' => BlueprintValidator::API_VERSION,
			'kind' => BlueprintValidator::KIND,
			'id' => $this->id( 'woo:blueprint' ),
			'name' => 'Woo catalog',
			'version' => 1,
			'nodes' => [
				[ 'id' => $entity_id, 'type' => 'entity', 'lane' => 'data', 'name' => 'Products', 'config' => [ 'owner' => 'woocommerce', 'public' => true ] ],
				[ 'id' => $collection_id, 'type' => 'collection', 'lane' => 'experience', 'name' => 'Catalog', 'config' => [ 'access' => 'public', 'sort_field_ids' => [ $name_id ] ] ],
				[ 'id' => $filter_id, 'type' => 'filter_surface', 'lane' => 'experience', 'name' => 'Catalog filters', 'config' => [ 'fields' => [ $category_id ], 'facet_fields' => [ $category_id ] ] ],
				[ 'id' => $adapter_id, 'type' => 'adapter', 'lane' => 'governance', 'name' => 'WooCommerce', 'config' => [ 'adapter_id' => 'woocommerce' ] ],
			],
			'connections' => [
				[ 'id' => $this->id( 'woo:edge:adapter' ), 'type' => 'adapts', 'from' => $adapter_id, 'to' => $entity_id ],
				[ 'id' => $this->id( 'woo:edge:collection' ), 'type' => 'collection_for', 'from' => $entity_id, 'to' => $collection_id ],
				[ 'id' => $this->id( 'woo:edge:filter' ), 'type' => 'filters', 'from' => $collection_id, 'to' => $filter_id ],
			],
		];
	}

	private function id( $seed ): string {
		return Uuid::v5( Uuid::LEGACY_NAMESPACE, $seed );
	}

	private function fields_by_storage( array $fields ): array {
		$result = [];
		foreach ( $fields as $field ) {
			$result[ $field['storage']['key'] ] = $field;
		}
		return $result;
	}

	private function healthy_woo_registries(): RegistryHub {
		$registries = new RegistryHub();
		$registries->storage_adapters()->register( new WooStorageAdapter() );
		$registries->collection_providers()->register(
			new class() extends WooCollectionProvider {
				public function health_check() {
					return [ 'ok' => true, 'version' => $this->get_version(), 'fixture' => true ];
				}
			}
		);
		return $registries;
	}

	private function product( $id, $name, $status ) {
		return new class( $id, $name, $status ) {
			private $id;
			private $name;
			private $status;

			public function __construct( $id, $name, $status ) {
				$this->id = $id;
				$this->name = $name;
				$this->status = $status;
			}

			public function get_id() {
				return $this->id;
			}

			public function get_name() {
				return $this->name;
			}

			public function get_permalink() {
				return 'https://example.test/product/' . $this->id;
			}

			public function get_stock_status() {
				return $this->status;
			}
		};
	}
}
