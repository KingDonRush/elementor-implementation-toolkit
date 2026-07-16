<?php
/**
 * Reads WooCommerce products exclusively through public Woo CRUD/query APIs.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WooCollectionProvider extends BaseCollectionProvider {

	public function get_id() {
		return 'woocommerce';
	}

	public function get_capabilities() {
		return [ 'woo_crud', 'catalog_filters', 'catalog_sort', 'search', 'pagination', 'public_status_only' ];
	}

	public function query( array $contract, array $request, array $context = [] ) {
		if ( ! class_exists( '\WC_Product_Query' ) ) {
			return new \WP_Error( 'eit_collection_woo_unavailable', __( 'WooCommerce product queries are unavailable.', 'elementor-implementation-toolkit' ) );
		}
		$fields = $this->fields( $contract );
		$args = $this->query_args( $request, $fields );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$result = ( new \WC_Product_Query( $args ) )->get_products();
		$products = is_object( $result ) ? (array) ( $result->products ?? [] ) : (array) $result;
		$items = [];
		foreach ( $products as $product ) {
			$record = [];
			foreach ( $fields as $field ) {
				$record[ $field['storage']['key'] ] = $this->product_value( $product, $field['storage']['key'] );
			}
			$items[] = $this->item( $product->get_id(), $product->get_name(), $product->get_permalink(), $record, $fields );
		}
		$total = is_object( $result ) ? (int) ( $result->total ?? count( $items ) ) : count( $items );
		$pages = is_object( $result ) ? (int) ( $result->max_num_pages ?? 1 ) : 1;
		return [
			'items' => $items,
			'total' => $total,
			'page' => max( 1, absint( $request['page'] ?? 1 ) ),
			'per_page' => max( 1, min( 48, absint( $request['per_page'] ?? 24 ) ) ),
			'pages' => max( 1, $pages ),
			'facets' => [],
		];
	}

	public function health_check() {
		return [ 'ok' => class_exists( '\WC_Product_Query' ), 'version' => $this->get_version() ];
	}

	private function query_args( array $request, array $fields ) {
		$args = [
			'status' => 'publish',
			'limit' => max( 1, min( 48, absint( $request['per_page'] ?? 24 ) ) ),
			'page' => max( 1, absint( $request['page'] ?? 1 ) ),
			'paginate' => true,
		];
		if ( '' !== ( $request['search'] ?? '' ) ) {
			$args['search'] = '*' . wc_clean( $request['search'] ) . '*';
		}
		foreach ( $request['filters'] ?? [] as $filter ) {
			$field = $fields[ $filter['field_id'] ?? '' ] ?? null;
			$key = $field['storage']['key'] ?? '';
			$allowed = [ 'sku', 'category', 'tag', 'stock_status', 'featured', 'virtual' ];
			if ( ! in_array( $key, $allowed, true ) || ! in_array( $filter['operator'] ?? '', [ 'equals', 'in' ], true ) ) {
				return new \WP_Error( 'eit_collection_woo_filter_unsupported', __( 'This WooCommerce field does not support the requested catalog filter.', 'elementor-implementation-toolkit' ) );
			}
			$args[ $key ] = 'in' === $filter['operator'] ? $this->list_value( $filter['value'] ) : $filter['value'];
		}
		$sort = is_array( $request['sort'] ?? null ) ? $request['sort'] : [];
		$key = $fields[ $sort['field_id'] ?? '' ]['storage']['key'] ?? 'menu_order';
		$args['orderby'] = in_array( $key, [ 'date', 'id', 'include', 'title', 'name', 'menu_order', 'price', 'popularity', 'rating' ], true ) ? $key : 'menu_order';
		$args['order'] = 'desc' === ( $sort['direction'] ?? '' ) ? 'DESC' : 'ASC';
		return $args;
	}

	private function product_value( $product, $key ) {
		$map = [
			'name' => 'get_name', 'sku' => 'get_sku', 'price' => 'get_price', 'regular_price' => 'get_regular_price',
			'sale_price' => 'get_sale_price', 'stock_status' => 'get_stock_status', 'stock_quantity' => 'get_stock_quantity',
			'image_id' => 'get_image_id', 'gallery_image_ids' => 'get_gallery_image_ids', 'featured' => 'get_featured',
		];
		$method = $map[ $key ] ?? '';
		return $method && is_callable( [ $product, $method ] ) ? $product->{$method}() : null;
	}
}
