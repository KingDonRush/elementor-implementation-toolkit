<?php
/**
 * Executes compiled Collections through WP_Query without accepting meta keys.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CptCollectionProvider extends BaseCollectionProvider {

	public function get_id() {
		return 'wp_query';
	}

	public function get_capabilities() {
		return [ 'field_id_filters', 'typed_sort', 'search', 'pagination', 'public_status_only' ];
	}

	public function query( array $contract, array $request, array $context = [] ) {
		if ( ! class_exists( '\WP_Query' ) ) {
			return new \WP_Error( 'eit_collection_wp_query_unavailable', __( 'WordPress query services are unavailable.', 'elementor-implementation-toolkit' ) );
		}
		$post_type = sanitize_key( $contract['entity']['definition']['slug'] ?? '' );
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'eit_collection_post_type_missing', __( 'The Collection post type is not available.', 'elementor-implementation-toolkit' ) );
		}
		$fields = $this->fields( $contract );
		$args = $this->query_args( $post_type, $request, $fields );
		$query = new \WP_Query( $args );
		$items = [];
		foreach ( $query->posts as $post ) {
			$record = $this->record( $post->ID, $fields );
			$items[] = $this->item( $post->ID, get_the_title( $post ), get_permalink( $post ), $record, $fields );
		}
		return [
			'items' => $items,
			'total' => (int) $query->found_posts,
			'page' => max( 1, absint( $request['page'] ?? 1 ) ),
			'per_page' => (int) $args['posts_per_page'],
			'pages' => max( 1, (int) $query->max_num_pages ),
			'facets' => [],
		];
	}

	public function health_check() {
		return [ 'ok' => true, 'version' => $this->get_version(), 'runtime_available' => class_exists( '\WP_Query' ) ];
	}

	private function query_args( $post_type, array $request, array $fields ) {
		$args = [
			'post_type' => $post_type,
			'post_status' => 'publish',
			'paged' => max( 1, absint( $request['page'] ?? 1 ) ),
			'posts_per_page' => max( 1, min( 48, absint( $request['per_page'] ?? 24 ) ) ),
			'no_found_rows' => false,
			'ignore_sticky_posts' => true,
		];
		if ( '' !== ( $request['search'] ?? '' ) ) {
			$args['s'] = (string) $request['search'];
		}
		$this->append_filters( $args, $request['filters'] ?? [], $fields );
		$this->append_sort( $args, $request['sort'] ?? [], $fields );
		return $args;
	}

	private function append_filters( array &$args, $filters, array $fields ) {
		$meta = [];
		$tax = [];
		foreach ( is_array( $filters ) ? $filters : [] as $filter ) {
			$field = $fields[ $filter['field_id'] ?? '' ] ?? null;
			if ( ! $field ) {
				continue;
			}
			if ( 'taxonomy' === ( $field['type'] ?? '' ) ) {
				$tax[] = $this->taxonomy_clause( $field, $filter );
			} elseif ( ! in_array( $field['type'] ?? '', [ 'relation', 'repeatable_group' ], true ) ) {
				$meta[] = $this->meta_clause( $field, $filter );
			}
		}
		if ( $meta ) {
			$args['meta_query'] = array_merge( [ 'relation' => 'AND' ], array_filter( $meta ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Compiled, bounded fields only.
		}
		if ( $tax ) {
			$args['tax_query'] = array_merge( [ 'relation' => 'AND' ], array_filter( $tax ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Compiled taxonomies only.
		}
	}

	private function meta_clause( array $field, array $filter ) {
		$operator = $filter['operator'] ?? 'equals';
		$compare = [ 'equals' => '=', 'not_equals' => '!=', 'in' => 'IN', 'not_in' => 'NOT IN', 'gte' => '>=', 'lte' => '<=', 'between' => 'BETWEEN' ][ $operator ] ?? '=';
		return [
			'key' => $field['storage']['key'],
			'value' => $filter['value'] ?? '',
			'compare' => $compare,
			'type' => $this->numeric_type( $field ) ? 'NUMERIC' : 'CHAR',
		];
	}

	private function taxonomy_clause( array $field, array $filter ) {
		$operator = in_array( $filter['operator'] ?? '', [ 'not_equals', 'not_in' ], true ) ? 'NOT IN' : 'IN';
		return [
			'taxonomy' => sanitize_key( $field['taxonomy']['slug'] ?? $field['storage']['key'] ?? '' ),
			'field' => 'term_id',
			'terms' => array_map( 'absint', $this->list_value( $filter['value'] ?? [] ) ),
			'operator' => $operator,
		];
	}

	private function append_sort( array &$args, $sort, array $fields ) {
		$sort = is_array( $sort ) ? $sort : [];
		$field = $fields[ $sort['field_id'] ?? '' ] ?? null;
		if ( ! $field || 'taxonomy' === ( $field['type'] ?? '' ) ) {
			$args['orderby'] = [ 'menu_order' => 'ASC', 'ID' => 'ASC' ];
			return;
		}
		$args['meta_key'] = $field['storage']['key']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Compiled field, never browser input.
		$args['orderby'] = $this->numeric_type( $field ) ? 'meta_value_num' : 'meta_value';
		$args['order'] = 'desc' === ( $sort['direction'] ?? '' ) ? 'DESC' : 'ASC';
	}

	private function record( $post_id, array $fields ) {
		$record = [];
		foreach ( $fields as $field ) {
			$key = $field['storage']['key'] ?? '';
			if ( 'taxonomy' === ( $field['type'] ?? '' ) ) {
				$record[ $key ] = wp_get_object_terms( $post_id, $field['taxonomy']['slug'] ?? $key, [ 'fields' => 'ids' ] );
			} elseif ( ! in_array( $field['type'] ?? '', [ 'relation', 'repeatable_group' ], true ) ) {
				$record[ $key ] = get_post_meta( $post_id, $key, true );
			}
		}
		return $record;
	}
}
