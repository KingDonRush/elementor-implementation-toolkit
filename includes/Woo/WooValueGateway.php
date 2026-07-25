<?php
/**
 * Reads and writes supported product values through WooCommerce CRUD objects.
 */

namespace EIT\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WooValueGateway {

	private $loader;
	private $creator;

	public function __construct( ?callable $loader = null, ?callable $creator = null ) {
		$this->loader = $loader;
		$this->creator = $creator;
	}

	public function get( $product_id ) {
		return $this->product( $product_id );
	}

	public function read_current( $key ) {
		global $product;
		$current = is_object( $product ) && is_callable( [ $product, 'get_id' ] ) ? $product : $this->product( get_the_ID() );
		return $current ? $this->read( $current, $key ) : null;
	}

	public function read( $product, $key ) {
		$key = sanitize_key( $key );
		$methods = [
			'name' => 'get_name',
			'sku' => 'get_sku',
			'price' => 'get_price',
			'regular_price' => 'get_regular_price',
			'sale_price' => 'get_sale_price',
			'stock_status' => 'get_stock_status',
			'stock_quantity' => 'get_stock_quantity',
			'image_id' => 'get_image_id',
			'gallery_image_ids' => 'get_gallery_image_ids',
			'featured' => 'get_featured',
			'category' => 'get_category_ids',
			'tag' => 'get_tag_ids',
			'category_ids' => 'get_category_ids',
			'tag_ids' => 'get_tag_ids',
			'permalink' => 'get_permalink',
		];
		$method = $methods[ $key ] ?? '';
		return $method && is_callable( [ $product, $method ] ) ? $product->{$method}() : null;
	}

	public function write( $product_id, array $values ) {
		$product = $this->product( $product_id );
		if ( ! $product ) {
			return new \WP_Error( 'eit_woo_product_missing', __( 'The WooCommerce product could not be loaded.', 'elementor-implementation-toolkit' ) );
		}
		return $this->persist( $product, $values );
	}

	public function create( array $values ) {
		$product = $this->creator ? call_user_func( $this->creator ) : ( class_exists( '\WC_Product_Simple' ) ? new \WC_Product_Simple() : null );
		return $product ? $this->persist( $product, $values ) : new \WP_Error( 'eit_woo_product_create_unavailable', __( 'WooCommerce product creation is unavailable.', 'elementor-implementation-toolkit' ) );
	}

	private function persist( $product, array $values ) {
		$setters = [
			'name' => 'set_name',
			'sku' => 'set_sku',
			'regular_price' => 'set_regular_price',
			'sale_price' => 'set_sale_price',
			'stock_status' => 'set_stock_status',
			'image_id' => 'set_image_id',
			'gallery_image_ids' => 'set_gallery_image_ids',
			'featured' => 'set_featured',
			'category' => 'set_category_ids',
			'tag' => 'set_tag_ids',
			'category_ids' => 'set_category_ids',
			'tag_ids' => 'set_tag_ids',
			'status' => 'set_status',
		];
		try {
			foreach ( $values as $key => $value ) {
				$key = sanitize_key( $key );
				if ( 'stock_quantity' === $key ) {
					$product->set_manage_stock( true );
					$product->set_stock_quantity( null === $value ? null : (int) $value );
					continue;
				}
				$method = $setters[ $key ] ?? '';
				if ( ! $method || ! is_callable( [ $product, $method ] ) ) {
					return new \WP_Error( 'eit_woo_field_read_only', __( 'This product field is read-only or unsupported.', 'elementor-implementation-toolkit' ), [ 'field' => $key ] );
				}
				$product->{$method}( $this->sanitize( $key, $value ) );
			}
			return is_callable( [ $product, 'save' ] ) ? $product->save() : new \WP_Error( 'eit_woo_save_unavailable', __( 'WooCommerce product persistence is unavailable.', 'elementor-implementation-toolkit' ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'eit_woo_save_failed', sanitize_text_field( $error->getMessage() ) );
		}
	}

	private function product( $product_id ) {
		if ( $this->loader ) {
			return call_user_func( $this->loader, absint( $product_id ) );
		}
		return function_exists( 'wc_get_product' ) ? wc_get_product( absint( $product_id ) ) : null;
	}

	private function sanitize( $key, $value ) {
		if ( in_array( $key, [ 'image_id' ], true ) ) {
			return absint( $value );
		}
		if ( in_array( $key, [ 'gallery_image_ids', 'category', 'tag', 'category_ids', 'tag_ids' ], true ) ) {
			return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
		}
		if ( 'featured' === $key ) {
			return (bool) $value;
		}
		if ( in_array( $key, [ 'regular_price', 'sale_price' ], true ) ) {
			return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value ) : (string) $value;
		}
		if ( 'status' === $key ) {
			$status = sanitize_key( $value );
			return in_array( $status, [ 'draft', 'pending', 'publish', 'private' ], true ) ? $status : 'draft';
		}
		return sanitize_text_field( $value );
	}
}
