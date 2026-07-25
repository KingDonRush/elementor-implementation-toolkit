<?php
/**
 * Executes compiled Collections through WP_Query without accepting meta keys.
 */

namespace EIT\Collection;

use EIT\Support\CptMultivalueMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CptCollectionProvider extends BaseCollectionProvider {
	private $relations;

	public function __construct( ?CollectionRelationConstraints $relations = null ) {
		$this->relations = $relations ?: new CollectionRelationConstraints();
	}

	public function get_id() {
		return 'wp_query';
	}

	public function get_capabilities() {
		return [ 'field_id_filters', 'typed_sort', 'search', 'facets', 'pagination', 'public_status_only' ];
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
		$request = $this->relations->apply( $contract, $request );
		$request = $this->apply_policy_scope( $contract, $request, $context );
		$args = $this->query_args( $post_type, $request, $fields, $contract['search_field_ids'] ?? [] );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$query = new \WP_Query( $args );
		$items = [];
		foreach ( $query->posts as $post ) {
			$record = $this->record( $post->ID, $fields );
			$items[] = $this->item( $post->ID, get_the_title( $post ), get_permalink( $post ), $record, $fields );
		}
		$facets = $this->facet_counts( $post_type, $contract, $request, $fields );
		if ( is_wp_error( $facets ) ) {
			return $facets;
		}
		return [
			'items' => $items,
			'total' => (int) $query->found_posts,
			'page' => max( 1, absint( $request['page'] ?? 1 ) ),
			'per_page' => (int) $args['posts_per_page'],
			'pages' => max( 1, (int) $query->max_num_pages ),
			'facets' => $facets,
		];
	}

	public function health_check() {
		return [ 'ok' => true, 'version' => $this->get_version(), 'runtime_available' => class_exists( '\WP_Query' ) ];
	}

	private function query_args( $post_type, array $request, array $fields, array $search_field_ids = [] ) {
		$args = [
			'post_type' => $post_type,
			'post_status' => 'publish',
			'paged' => max( 1, absint( $request['page'] ?? 1 ) ),
			'posts_per_page' => max( 1, min( 48, absint( $request['per_page'] ?? 24 ) ) ),
			'no_found_rows' => false,
			'ignore_sticky_posts' => true,
		];
		if ( '' !== ( $request['search'] ?? '' ) ) {
			$search_ids = $this->search_ids( $post_type, $request['search'], $search_field_ids, $fields );
			if ( is_wp_error( $search_ids ) ) {
				return $search_ids;
			}
			$args['post__in'] = $search_ids;
		}
		$this->append_identity_constraints( $args, $request );
		$this->append_filters( $args, $request['filters'] ?? [], $fields );
		$this->append_sort( $args, $request['sort'] ?? [], $fields );
		return $args;
	}

	private function append_identity_constraints( array &$args, array $request ) {
		$include = $request['_relation_include'] ?? [];
		if ( array_key_exists( '_policy_include', $request ) ) {
			$include = $this->intersect_ids( $include, $request['_policy_include'] );
		}
		$include = array_map( 'absint', $include );
		if ( ! empty( $request['_policy_deny'] ) ) {
			$include = [ 0 ];
		}
		if ( $include ) {
			$args['post__in'] = isset( $args['post__in'] ) ? array_values( array_intersect( $args['post__in'], $include ) ) : $include;
			if ( ! $args['post__in'] ) {
				$args['post__in'] = [ 0 ];
			}
		}
		$exclude = array_values( array_filter( array_map( 'absint', $request['_relation_exclude'] ?? [] ) ) );
		if ( $exclude ) {
			$args['post__not_in'] = $exclude;
		}
		if ( ! empty( $request['_policy_author_id'] ) ) {
			$args['author'] = absint( $request['_policy_author_id'] );
		}
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
		$value = $filter['value'] ?? '';
		if ( 'between' === $operator && is_array( $value ) ) {
			$value = [ $value['min'] ?? '', $value['max'] ?? '' ];
		}
		return [
			'key' => $field['storage']['key'],
			'value' => $value,
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
			} elseif ( 'multiple_choice' === ( $field['type'] ?? '' ) ) {
				$record[ $key ] = CptMultivalueMeta::read( $post_id, $key );
			} elseif ( ! in_array( $field['type'] ?? '', [ 'relation', 'repeatable_group' ], true ) ) {
				$record[ $key ] = get_post_meta( $post_id, $key, true );
			}
		}
		return $record;
	}

	private function facet_counts( $post_type, array $contract, array $request, array $fields ) {
		$result = [];
		foreach ( array_slice( $request['facets'] ?? [], 0, 10 ) as $field_id ) {
			$field = $fields[ $field_id ] ?? null;
			if ( ! $field ) {
				continue;
			}
			$facet_request = $request;
			$facet_request['filters'] = array_values( array_filter( $request['filters'] ?? [], fn( $filter ) => $field_id !== ( $filter['field_id'] ?? '' ) ) );
			$facet_request = $this->relations->apply( $contract, $facet_request );
			$args = $this->query_args( $post_type, $facet_request, $fields, $contract['search_field_ids'] ?? [] );
			if ( is_wp_error( $args ) ) {
				return $args;
			}
			$args['posts_per_page'] = CollectionRequestValidator::MAX_PROVIDER_SCAN + 1;
			$args['paged'] = 1;
			$args['fields'] = 'ids';
			$args['no_found_rows'] = true;
			$args['orderby'] = 'none';
			unset( $args['meta_key'], $args['order'] );
			$ids = ( new \WP_Query( $args ) )->posts;
			if ( count( $ids ) > CollectionRequestValidator::MAX_PROVIDER_SCAN ) {
				return $this->scan_limit_error();
			}
			$result[ $field_id ] = 'relation' === ( $field['type'] ?? '' )
				? $this->relations->facet_counts( $contract, $field_id, array_map( 'strval', $ids ) )
				: $this->count_field_values( $ids, $field );
		}
		return $result;
	}

	private function count_field_values( array $post_ids, array $field ) {
		$counts = [];
		if ( 'taxonomy' === ( $field['type'] ?? '' ) ) {
			$terms = wp_get_object_terms( $post_ids, $field['taxonomy']['slug'] ?? $field['storage']['key'], [ 'fields' => 'all_with_object_id' ] );
			foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
				$key = (string) $term->term_id;
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
			return $counts;
		}
		update_meta_cache( 'post', $post_ids );
		foreach ( $post_ids as $post_id ) {
			$value = 'multiple_choice' === ( $field['type'] ?? '' )
				? CptMultivalueMeta::read( $post_id, $field['storage']['key'] )
				: get_post_meta( $post_id, $field['storage']['key'], true );
			if ( is_array( $value ) && array_key_exists( 'amount', $value ) ) {
				$value = $value['amount'];
			}
			foreach ( is_array( $value ) ? $value : [ $value ] as $item ) {
				$key = sanitize_text_field( (string) $item );
				if ( '' !== $key ) {
					$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
				}
			}
		}
		return $counts;
	}

	private function search_ids( $post_type, $search, array $field_ids, array $fields ) {
		$search = sanitize_text_field( $search );
		$title_query = new \WP_Query(
			[
				'post_type' => $post_type,
				'post_status' => 'publish',
				's' => $search,
				'fields' => 'ids',
				'posts_per_page' => CollectionRequestValidator::MAX_PROVIDER_SCAN + 1,
				'no_found_rows' => true,
			]
		);
		$ids = $title_query->posts;
		if ( count( $ids ) > CollectionRequestValidator::MAX_PROVIDER_SCAN ) {
			return $this->scan_limit_error();
		}
		$meta = [];
		$taxonomies = [];
		foreach ( $field_ids as $field_id ) {
			$field = $fields[ $field_id ] ?? null;
			if ( ! $field ) {
				continue;
			}
			if ( 'taxonomy' === ( $field['type'] ?? '' ) ) {
				$taxonomies[] = sanitize_key( $field['taxonomy']['slug'] ?? $field['storage']['key'] );
			} elseif ( ! in_array( $field['type'] ?? '', [ 'relation', 'repeatable_group' ], true ) ) {
				$meta[] = [ 'key' => $field['storage']['key'], 'value' => $search, 'compare' => 'LIKE' ];
			}
		}
		if ( $meta ) {
			$meta_query = new \WP_Query(
				[
					'post_type' => $post_type,
					'post_status' => 'publish',
					'fields' => 'ids',
					'posts_per_page' => CollectionRequestValidator::MAX_PROVIDER_SCAN + 1,
					'no_found_rows' => true,
					'meta_query' => array_merge( [ 'relation' => 'OR' ], $meta ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Search is limited to published Field contracts.
				]
			);
			$ids = array_merge( $ids, $meta_query->posts );
			if ( count( array_unique( array_map( 'absint', $ids ) ) ) > CollectionRequestValidator::MAX_PROVIDER_SCAN ) {
				return $this->scan_limit_error();
			}
		}
		foreach ( array_unique( $taxonomies ) as $taxonomy ) {
			$terms = get_terms( [ 'taxonomy' => $taxonomy, 'search' => $search, 'hide_empty' => false, 'fields' => 'ids', 'number' => 100 ] );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$taxonomy_query = new \WP_Query(
					[
						'post_type' => $post_type,
						'post_status' => 'publish',
						'fields' => 'ids',
						'posts_per_page' => CollectionRequestValidator::MAX_PROVIDER_SCAN + 1,
						'no_found_rows' => true,
						'tax_query' => [ [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $terms ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Published contract and bounded result set.
					]
				);
				$ids = array_merge( $ids, $taxonomy_query->posts );
				if ( count( array_unique( array_map( 'absint', $ids ) ) ) > CollectionRequestValidator::MAX_PROVIDER_SCAN ) {
					return $this->scan_limit_error();
				}
			}
		}
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		return $ids ?: [ 0 ];
	}

	private function scan_limit_error() {
		return new \WP_Error(
			'eit_collection_scan_limit',
			__( 'This search or facet would scan too many records. Add a narrower filter or use an indexed CCT Collection.', 'elementor-implementation-toolkit' ),
			[ 'status' => 422, 'limit' => CollectionRequestValidator::MAX_PROVIDER_SCAN ]
		);
	}
}
