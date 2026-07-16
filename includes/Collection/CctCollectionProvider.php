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

	public function __construct( Repository $repository = null ) {
		$this->repository = $repository ?: new Repository();
	}

	public function get_id() {
		return 'cct_indexed';
	}

	public function get_capabilities() {
		return [ 'field_id_filters', 'indexed_sort', 'search', 'pagination', 'public_status_only' ];
	}

	public function query( array $contract, array $request, array $context = [] ) {
		$type = DefinitionManager::sanitize_slug( $contract['entity']['definition']['slug'] ?? '' );
		if ( '' === $type || ! DefinitionManager::get( $type ) ) {
			return new \WP_Error( 'eit_collection_cct_missing', __( 'The Collection content table is not available.', 'elementor-implementation-toolkit' ) );
		}
		$fields = $this->fields( $contract );
		$args = [
			'page' => max( 1, absint( $request['page'] ?? 1 ) ),
			'per_page' => max( 1, min( 48, absint( $request['per_page'] ?? 24 ) ) ),
			'status' => [ 'publish' ],
			'filters' => $this->filters( $request['filters'] ?? [], $fields ),
		];
		if ( '' !== ( $request['search'] ?? '' ) ) {
			$args['search'] = (string) $request['search'];
		}
		$this->sort( $args, $request['sort'] ?? [], $fields );
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
			'facets' => [],
		];
	}

	public function health_check() {
		return [ 'ok' => class_exists( Repository::class ), 'version' => $this->get_version() ];
	}

	private function filters( $filters, array $fields ) {
		$result = [];
		foreach ( is_array( $filters ) ? $filters : [] as $filter ) {
			$field = $fields[ $filter['field_id'] ?? '' ] ?? null;
			$key = (string) ( $field['storage']['key'] ?? '' );
			if ( '' === $key ) {
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
}
