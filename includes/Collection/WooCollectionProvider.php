<?php
/**
 * Reads WooCommerce products exclusively through public Woo CRUD/query APIs.
 */

namespace EIT\Collection;

use EIT\Woo\WooValueGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WooCollectionProvider extends BaseCollectionProvider {

	private $values;
	private $query_factory;

	public function __construct( WooValueGateway $values = null, callable $query_factory = null ) {
		$this->values = $values ?: new WooValueGateway();
		$this->query_factory = $query_factory;
	}

	public function get_id() {
		return 'woocommerce';
	}

	public function get_capabilities() {
		return [ 'woo_crud', 'catalog_filters', 'catalog_sort', 'search', 'facets', 'pagination', 'public_status_only' ];
	}

	public function query( array $contract, array $request, array $context = [] ) {
		if ( ! $this->available() ) {
			return new \WP_Error( 'eit_collection_woo_unavailable', __( 'WooCommerce product queries are unavailable.', 'elementor-implementation-toolkit' ) );
		}
		$fields = $this->fields( $contract );
		$request = $this->apply_policy_scope( $contract, $request, $context );
		$request = $this->apply_product_scope( $request );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		$args = $this->query_args( $request, $fields );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$result = $this->query_products( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$products = is_object( $result ) ? (array) ( $result->products ?? [] ) : (array) $result;
		$items = [];
		foreach ( $products as $product ) {
			$record = [];
			foreach ( $fields as $field ) {
				$record[ $field['storage']['key'] ] = $this->values->read( $product, $field['storage']['key'] );
			}
			$items[] = $this->item( $product->get_id(), $product->get_name(), $product->get_permalink(), $record, $fields );
		}
		$total = is_object( $result ) ? (int) ( $result->total ?? count( $items ) ) : count( $items );
		$pages = is_object( $result ) ? (int) ( $result->max_num_pages ?? 1 ) : 1;
		$facets = $this->facets( $contract, $request, $fields );
		if ( is_wp_error( $facets ) ) {
			return $facets;
		}
		return [
			'items' => $items,
			'total' => $total,
			'page' => max( 1, absint( $request['page'] ?? 1 ) ),
			'per_page' => max( 1, min( 48, absint( $request['per_page'] ?? 24 ) ) ),
			'pages' => max( 1, $pages ),
			'facets' => $facets,
		];
	}

	public function health_check() {
		return [ 'ok' => $this->available(), 'version' => $this->get_version() ];
	}

	private function query_args( array $request, array $fields ) {
		$args = [
			'status' => 'publish',
			'limit' => max( 1, min( 48, absint( $request['per_page'] ?? 24 ) ) ),
			'page' => max( 1, absint( $request['page'] ?? 1 ) ),
			'paginate' => true,
		];
		if ( ! empty( $request['_policy_deny'] ) ) {
			$args['include'] = [ 0 ];
		} elseif ( ! empty( $request['_policy_include'] ) ) {
			$args['include'] = array_values( array_filter( array_map( 'absint', $request['_policy_include'] ) ) );
		}
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

	private function apply_product_scope( array $request ) {
		if ( empty( $request['_policy_author_id'] ) || ! empty( $request['_policy_deny'] ) ) {
			return $request;
		}
		if ( ! function_exists( 'get_posts' ) ) {
			$request['_policy_deny'] = true;
			return $request;
		}
		$owned = get_posts(
			[
				'post_type' => 'product',
				'post_status' => 'publish',
				'author' => absint( $request['_policy_author_id'] ),
				'fields' => 'ids',
				'posts_per_page' => CollectionRequestValidator::MAX_PROVIDER_SCAN + 1,
				'no_found_rows' => true,
			]
		);
		if ( count( $owned ) > CollectionRequestValidator::MAX_PROVIDER_SCAN ) {
			return $this->scan_limit_error();
		}
		$owned = array_values( array_map( 'strval', $owned ) );
		if ( array_key_exists( '_policy_include', $request ) ) {
			$owned = $this->intersect_ids( $owned, $request['_policy_include'] );
		}
		if ( ! $owned ) {
			$request['_policy_deny'] = true;
		} else {
			$request['_policy_include'] = $owned;
		}
		return $request;
	}

	private function facets( array $contract, array $request, array $fields ) {
		$facets = [];
		foreach ( array_slice( $request['facets'] ?? [], 0, 10 ) as $field_id ) {
			$field = $fields[ $field_id ] ?? null;
			if ( ! $field ) {
				continue;
			}
			$facet_request = $request;
			$facet_request['filters'] = array_values( array_filter( $request['filters'] ?? [], fn( $filter ) => $field_id !== ( $filter['field_id'] ?? '' ) ) );
			$args = $this->query_args( $facet_request, $fields );
			if ( is_wp_error( $args ) ) {
				continue;
			}
			$args['limit'] = CollectionRequestValidator::MAX_PROVIDER_SCAN + 1;
			$args['page'] = 1;
			$args['paginate'] = false;
			unset( $args['orderby'], $args['order'] );
			$products = $this->query_products( $args );
			if ( is_wp_error( $products ) ) {
				return $products;
			}
			$products = (array) $products;
			if ( count( $products ) > CollectionRequestValidator::MAX_PROVIDER_SCAN ) {
				return $this->scan_limit_error();
			}
			$counts = $this->count_values( $products, $field );
			if ( is_wp_error( $counts ) ) {
				return $counts;
			}
			$facets[ $field_id ] = $counts;
		}
		return $facets;
	}

	private function count_values( array $products, array $field ) {
		$key = $field['storage']['key'] ?? '';
		if ( in_array( $key, [ 'category', 'tag' ], true ) ) {
			return $this->count_terms( $products, $key );
		}
		$counts = [];
		foreach ( $products as $product ) {
			$value = $this->values->read( $product, $key );
			foreach ( is_array( $value ) ? $value : [ $value ] as $item ) {
				$item = is_bool( $item ) ? ( $item ? '1' : '0' ) : sanitize_text_field( (string) $item );
				if ( '' !== $item ) {
					$counts[ $item ] = ( $counts[ $item ] ?? 0 ) + 1;
				}
			}
		}
		return $counts;
	}

	private function count_terms( array $products, $key ) {
		$taxonomy = 'category' === $key ? 'product_cat' : 'product_tag';
		$ids = [];
		foreach ( $products as $product ) {
			$ids = array_merge( $ids, (array) $this->values->read( $product, $key ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( count( $ids ) > 5000 ) {
			return $this->scan_limit_error();
		}
		$terms = $ids ? get_terms( [ 'taxonomy' => $taxonomy, 'include' => $ids, 'hide_empty' => false ] ) : [];
		$terms = is_wp_error( $terms ) ? [] : array_column( $terms, null, 'term_id' );
		$counts = [];
		foreach ( $products as $product ) {
			foreach ( (array) $this->values->read( $product, $key ) as $term_id ) {
				$term = $terms[ absint( $term_id ) ] ?? null;
				if ( ! $term ) {
					continue;
				}
				$slug = sanitize_title( $term->slug );
				$counts[ $slug ] = [
					'count' => ( $counts[ $slug ]['count'] ?? 0 ) + 1,
					'label' => sanitize_text_field( $term->name ),
				];
			}
		}
		return $counts;
	}

	private function available() {
		return null !== $this->query_factory || class_exists( '\WC_Product_Query' );
	}

	private function query_products( array $args ) {
		try {
			$query = $this->query_factory ? call_user_func( $this->query_factory, $args ) : new \WC_Product_Query( $args );
			return is_object( $query ) && is_callable( [ $query, 'get_products' ] ) ? $query->get_products() : [];
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'eit_collection_woo_query_failed', sanitize_text_field( $error->getMessage() ) );
		}
	}

	private function scan_limit_error() {
		return new \WP_Error(
			'eit_collection_scan_limit',
			__( 'This WooCommerce scope or facet would scan too many products. Narrow the Collection before requesting counts.', 'elementor-implementation-toolkit' ),
			[ 'status' => 422, 'limit' => CollectionRequestValidator::MAX_PROVIDER_SCAN ]
		);
	}
}
