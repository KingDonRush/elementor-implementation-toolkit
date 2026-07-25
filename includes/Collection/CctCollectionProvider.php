<?php
/**
 * Executes compiled Collections through indexed CCT repository queries.
 */

namespace EIT\Collection;

use EIT\CCT\DefinitionManager;
use EIT\CCT\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctCollectionProvider extends BaseCollectionProvider {

	private $repository;
	private $relations;

	public function __construct( ?Repository $repository = null, ?CollectionRelationConstraints $relations = null ) {
		$this->repository = $repository ?: new Repository();
		$this->relations = $relations ?: new CollectionRelationConstraints();
	}

	public function get_id() {
		return 'cct_indexed';
	}

	public function get_capabilities() {
		return [ 'field_id_filters', 'indexed_sort', 'search', 'facets', 'pagination', 'public_status_only' ];
	}

	public function query( array $contract, array $request, array $context = [] ) {
		$type = DefinitionManager::sanitize_slug( $contract['entity']['definition']['slug'] ?? '' );
		if ( '' === $type || ! DefinitionManager::get( $type ) ) {
			return new \WP_Error( 'eit_collection_cct_missing', __( 'The Collection content table is not available.', 'elementor-implementation-toolkit' ) );
		}
		$fields = $this->fields( $contract );
		$request = $this->relations->apply( $contract, $request );
		$request = $this->apply_policy_scope( $contract, $request, $context );
		$args = $this->query_args( $contract, $request, $fields );
		$result = $this->repository->query_public( $type, $args );
		$items = [];
		foreach ( $result['items'] as $record ) {
			$items[] = $this->item( $record['id'], $record['title'], '', $record, $fields );
		}
		return [
			'items' => $items,
			'total' => (int) $result['total'],
			'page' => (int) $result['page'],
			'per_page' => (int) $result['per_page'],
			'pages' => (int) $result['pages'],
			'facets' => $this->facets( $type, $contract, $request, $args, $fields ),
		];
	}

	public function health_check() {
		return [ 'ok' => class_exists( Repository::class ), 'version' => $this->get_version() ];
	}

	private function query_args( array $contract, array $request, array $fields ) {
		$include = $request['_relation_include'] ?? [];
		if ( array_key_exists( '_policy_include', $request ) ) {
			$include = $this->intersect_ids( $include, $request['_policy_include'] );
		}
		if ( ! empty( $request['_policy_deny'] ) ) {
			$include = [ 0 ];
		}
		$args = [
			'page' => max( 1, absint( $request['page'] ?? 1 ) ),
			'per_page' => max( 1, min( 48, absint( $request['per_page'] ?? 24 ) ) ),
			'status' => [ 'publish' ],
			'filters' => $this->filters( $request['filters'] ?? [], $fields ),
			'include' => $include,
			'exclude' => $request['_relation_exclude'] ?? [],
		];
		if ( ! empty( $request['_policy_author_id'] ) ) {
			$args['author_id'] = absint( $request['_policy_author_id'] );
		}
		if ( '' !== ( $request['search'] ?? '' ) ) {
			$args['search'] = (string) $request['search'];
			$args['search_fields'] = $this->storage_keys( $contract['search_field_ids'] ?? [], $fields );
		}
		$this->sort( $args, $request['sort'] ?? [], $fields );
		return $args;
	}

	private function facets( $type, array $contract, array $request, array $args, array $fields ) {
		$facet_keys = [];
		$facet_ids = [];
		foreach ( $request['facets'] ?? [] as $field_id ) {
			if ( isset( $fields[ $field_id ] ) && 'relation' !== ( $fields[ $field_id ]['type'] ?? '' ) ) {
				$facet_keys[] = $fields[ $field_id ]['storage']['key'];
				$facet_ids[ $fields[ $field_id ]['storage']['key'] ] = $field_id;
			}
		}
		$storage_facets = $this->repository->facet_counts( $type, $args, $facet_keys );
		$facets = [];
		foreach ( $storage_facets as $key => $counts ) {
			$facets[ $facet_ids[ $key ] ] = $counts;
		}
		foreach ( $request['facets'] ?? [] as $field_id ) {
			if ( 'relation' !== ( $fields[ $field_id ]['type'] ?? '' ) ) {
				continue;
			}
			$facet_request = $request;
			$facet_request['filters'] = array_values( array_filter( $request['filters'] ?? [], fn( $filter ) => $field_id !== ( $filter['field_id'] ?? '' ) ) );
			$facet_request = $this->relations->apply( $contract, $facet_request );
			$facet_args = $this->query_args( $contract, $facet_request, $fields );
			$source_ids = $this->repository->matching_ids( $type, $facet_args );
			$facets[ $field_id ] = $this->relations->facet_counts( $contract, $field_id, $source_ids );
		}
		return $facets;
	}

	private function filters( $filters, array $fields ) {
		$result = [];
		foreach ( is_array( $filters ) ? $filters : [] as $filter ) {
			$field = $fields[ $filter['field_id'] ?? '' ] ?? null;
			$key = (string) ( $field['storage']['key'] ?? '' );
			if ( '' === $key || 'relation' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			$result[] = [ 'key' => $key, 'compare' => $filter['operator'] ?? 'equals', 'value' => $filter['value'] ?? '' ];
		}
		return $result;
	}

	private function sort( array &$args, $sort, array $fields ) {
		$sort = is_array( $sort ) ? $sort : [];
		$field = $fields[ $sort['field_id'] ?? '' ] ?? null;
		if ( $field ) {
			$args['orderby'] = $field['storage']['key'];
			$args['order'] = 'desc' === ( $sort['direction'] ?? '' ) ? 'DESC' : 'ASC';
			return;
		}
		$args['orderby'] = 'menu_order';
		$args['order'] = 'ASC';
	}

	private function storage_keys( array $field_ids, array $fields ) {
		$keys = [];
		foreach ( $field_ids as $field_id ) {
			if ( isset( $fields[ $field_id ]['storage']['key'] ) ) {
				$keys[] = $fields[ $field_id ]['storage']['key'];
			}
		}
		return $keys;
	}
}
