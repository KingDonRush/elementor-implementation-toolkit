<?php
/**
 * Compiles WooCommerce-owned product entities without creating plugin storage.
 */

namespace EIT\Woo;

use EIT\Contracts\FieldContractSourceInterface;
use EIT\Contracts\StorageAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WooStorageAdapter implements StorageAdapterInterface, FieldContractSourceInterface {

	private $catalog;

	public function __construct( WooFieldContractCatalog $catalog = null ) {
		$this->catalog = $catalog ?: new WooFieldContractCatalog();
	}

	public function get_id() {
		return 'woocommerce';
	}

	public function get_version() {
		return '1.0.0';
	}

	public function get_capabilities() {
		return [
			'product_crud',
			'entry_create_update',
			'catalog_query',
			'public_product_fields',
			'no_direct_meta',
		];
	}

	public function get_field_contracts( array $entity = [], array $context = [] ) {
		return $this->catalog->all();
	}

	public function compile( array $entity, array $fields, array $context = [] ) {
		return [
			'strategy' => 'adapter',
			'definition' => [
				'owner' => 'woocommerce',
				'object_type' => 'product',
				'singular' => sanitize_text_field( $entity['name'] ?? 'Product' ),
				'plural' => sanitize_text_field( $entity['config']['plural'] ?? $entity['name'] ?? 'Products' ),
				'public' => true,
				'blueprint_managed' => true,
				'blueprint_id' => (string) ( $context['blueprint_id'] ?? '' ),
				'entity_id' => (string) ( $entity['id'] ?? '' ),
			],
		];
	}

	public function prepare( array $artifact, array $context = [] ) {
		return true;
	}

	public function health_check() {
		$available = class_exists( '\WC_Product_Query' ) && function_exists( 'wc_get_product' );
		return [
			'ok' => $available,
			'version' => $this->get_version(),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'message' => $available
				? __( 'WooCommerce product CRUD and query APIs are available.', 'elementor-implementation-toolkit' )
				: __( 'WooCommerce is not active; product Blueprints cannot be published.', 'elementor-implementation-toolkit' ),
		];
	}
}
