<?php
/**
 * Bounded 1.x fallback for browser-supplied DOM snapshots.
 */

namespace EIT\Collection;

use EIT\Support\LegacyDomMatcher;
use EIT\Support\LegacyDomPayload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyDomCollectionProvider extends BaseCollectionProvider {

	public function get_id() {
		return 'legacy_dom';
	}

	public function get_capabilities() {
		return [ 'field_id_filters', 'exact_token_match', 'pagination', 'facets', 'bounded_snapshot' ];
	}

	public function query( array $contract, array $request, array $context = [] ) {
		$fields = $this->fields( $contract );
		$normalizer = new LegacyDomPayload();
		$payload = $normalizer->normalize( [ 'items' => $request['legacy_snapshot'] ?? [] ] );
		$matcher = new LegacyDomMatcher();
		$filters = $this->legacy_filters( $request['filters'] ?? [], $fields );
		if ( '' !== ( $request['search'] ?? '' ) ) {
			$filters[] = [ 'key' => '', 'type' => 'search', 'value' => $request['search'], 'compare' => '', 'source' => 'visible_text', 'dataType' => 'string' ];
		}
		$matched = $matcher->filter( $payload['items'], $filters );
		$matched = $matcher->sort( $matched, $this->legacy_sort( $request['sort'] ?? [], $fields ) );
		$page = max( 1, absint( $request['page'] ?? 1 ) );
		$per_page = max( 1, min( 48, absint( $request['per_page'] ?? 24 ) ) );
		$total = count( $matched );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page = min( $page, $pages );
		$items = [];
		foreach ( array_slice( $matched, ( $page - 1 ) * $per_page, $per_page ) as $record ) {
			$items[] = $this->item( $record['clientId'], $record['title'], '', $record['data'], $fields );
		}
		return [
			'items' => $items,
			'total' => $total,
			'page' => $page,
			'per_page' => $per_page,
			'pages' => $pages,
			'facets' => $this->facets( $matched, $request['facets'] ?? [], $fields ),
		];
	}

	public function health_check() {
		return [ 'ok' => true, 'version' => $this->get_version(), 'limit' => 200 ];
	}

	private function legacy_filters( $filters, array $fields ) {
		$result = [];
		foreach ( is_array( $filters ) ? $filters : [] as $filter ) {
			$field = $fields[ $filter['field_id'] ?? '' ] ?? null;
			if ( ! $field ) {
				continue;
			}
			$result[] = [
				'key' => $field['storage']['key'],
				'type' => 'filter',
				'value' => $filter['value'] ?? '',
				'compare' => $filter['operator'] ?? 'equals',
				'source' => 'data_attr',
				'dataType' => $this->numeric_type( $field ) ? 'number' : ( in_array( $field['type'] ?? '', [ 'date', 'datetime', 'time' ], true ) ? 'date' : 'string' ),
			];
		}
		return $result;
	}

	private function legacy_sort( $sort, array $fields ) {
		$sort = is_array( $sort ) ? $sort : [];
		$field = $fields[ $sort['field_id'] ?? '' ] ?? null;
		if ( ! $field ) {
			return 'default';
		}
		$type = $this->numeric_type( $field ) ? 'number' : ( in_array( $field['type'] ?? '', [ 'date', 'datetime', 'time' ], true ) ? 'date' : 'text' );
		return 'data_' . sanitize_key( $field['storage']['key'] ) . '_' . $type . '_' . ( 'desc' === ( $sort['direction'] ?? '' ) ? 'desc' : 'asc' );
	}

	private function facets( array $items, $facet_ids, array $fields ) {
		$result = [];
		foreach ( is_array( $facet_ids ) ? $facet_ids : [] as $field_id ) {
			$field = $fields[ $field_id ] ?? null;
			if ( ! $field ) {
				continue;
			}
			$key = $field['storage']['key'];
			$counts = [];
			foreach ( $items as $item ) {
				foreach ( $this->list_value( $item['data'][ $key ] ?? '' ) as $value ) {
					$value = (string) $value;
					$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
				}
			}
			$result[ $field_id ] = $counts;
		}
		return $result;
	}
}
