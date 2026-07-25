<?php
/**
 * Closed semantic Field Contract catalog for WooCommerce products.
 */

namespace EIT\Woo;

use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WooFieldContractCatalog {

	private $factory;

	public function __construct( ?FieldContractFactory $factory = null ) {
		$this->factory = $factory ?: new FieldContractFactory( new FieldPrimitiveRegistry() );
	}

	public function all() {
		return [
			$this->field( 'name', __( 'Product name', 'elementor-implementation-toolkit' ), 'short_text', [ 'search' => true, 'sort' => true ] ),
			$this->field( 'sku', __( 'SKU', 'elementor-implementation-toolkit' ), 'short_text', [ 'filter' => true, 'operators' => [ 'equals' ] ] ),
			$this->field( 'permalink', __( 'Product URL', 'elementor-implementation-toolkit' ), 'url', [], true ),
			$this->field( 'price', __( 'Current price', 'elementor-implementation-toolkit' ), 'money', [ 'sort' => true ], true ),
			$this->field( 'regular_price', __( 'Regular price', 'elementor-implementation-toolkit' ), 'money' ),
			$this->field( 'sale_price', __( 'Sale price', 'elementor-implementation-toolkit' ), 'money' ),
			$this->field(
				'stock_status',
				__( 'Stock status', 'elementor-implementation-toolkit' ),
				'single_choice',
				[ 'filter' => true, 'operators' => [ 'equals' ] ],
				false,
				[
					[ 'value' => 'instock', 'label' => __( 'In stock', 'elementor-implementation-toolkit' ) ],
					[ 'value' => 'outofstock', 'label' => __( 'Out of stock', 'elementor-implementation-toolkit' ) ],
					[ 'value' => 'onbackorder', 'label' => __( 'On backorder', 'elementor-implementation-toolkit' ) ],
				]
			),
			$this->field( 'stock_quantity', __( 'Stock quantity', 'elementor-implementation-toolkit' ), 'integer' ),
			$this->field( 'image_id', __( 'Product image', 'elementor-implementation-toolkit' ), 'image' ),
			$this->field( 'gallery_image_ids', __( 'Product gallery', 'elementor-implementation-toolkit' ), 'gallery' ),
			$this->field( 'category', __( 'Product categories', 'elementor-implementation-toolkit' ), 'taxonomy', [ 'filter' => true, 'operators' => [ 'in' ] ], true ),
			$this->field( 'tag', __( 'Product tags', 'elementor-implementation-toolkit' ), 'taxonomy', [ 'filter' => true, 'operators' => [ 'in' ] ], true ),
			$this->field(
				'featured',
				__( 'Featured product', 'elementor-implementation-toolkit' ),
				'boolean',
				[ 'filter' => true, 'operators' => [ 'equals' ] ]
			),
		];
	}

	public function by_id() {
		return array_column( $this->all(), null, 'id' );
	}

	private function field( $key, $name, $type, array $query = [], $read_only = false, array $options = [] ) {
		$indexing = [
			'search' => ! empty( $query['search'] ),
			'filter' => ! empty( $query['filter'] ),
			'sort' => ! empty( $query['sort'] ),
		];
		$validation = [ 'required' => 'name' === $key, 'options' => $options, 'read_only' => $read_only ];
		if ( 'money' === $type ) {
			$validation['currency'] = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		}
		$overrides = [
			'validation' => $validation,
				'exposure' => [
					'public' => 'stock_quantity' !== $key,
					'roles' => 'stock_quantity' === $key ? [ 'administrator', 'shop_manager' ] : [],
				],
			'storage' => [ 'key' => $key, 'aliases' => [] ],
			'indexing' => $indexing,
		];
		if ( ! empty( $query['operators'] ) ) {
			$overrides['capabilities']['filter_operators'] = array_values( $query['operators'] );
		}
		return $this->factory->make(
			Uuid::v5( Uuid::LEGACY_NAMESPACE, 'woocommerce:product:' . $key ),
			$name,
			$type,
			$overrides
		);
	}
}
