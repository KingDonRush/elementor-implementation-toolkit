<?php
/**
 * Bounded public request grammar for immutable Collection contracts.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionRequestValidator {

	const MAX_BODY_BYTES = 32768;
	const MAX_FILTERS = 20;
	const MAX_FACETS = 10;
	const MAX_PER_PAGE = 48;
	const MAX_DOM_ITEMS = 200;
	const MAX_PROVIDER_SCAN = 2000;
	const MAX_COST = 10000;

	private $semantics;

	public function __construct( ?CollectionFieldSemantics $semantics = null ) {
		$this->semantics = $semantics ?: new CollectionFieldSemantics();
	}

	public function validate( array $contract, $payload ) {
		if ( ! is_array( $payload ) ) {
			return $this->error( 'eit_collection_payload_invalid', __( 'Collection request must be a JSON object.', 'elementor-implementation-toolkit' ), 400 );
		}
		$unknown = array_diff( array_keys( $payload ), [ 'page', 'per_page', 'search', 'filters', 'sort', 'facets', 'explain', 'legacy_snapshot' ] );
		if ( $unknown ) {
			return $this->error( 'eit_collection_input_not_allowed', __( 'Collection request contains an input that is not part of its published contract.', 'elementor-implementation-toolkit' ), 400 );
		}
		$fields = array_column( $contract['fields'] ?? [], null, 'id' );
		$filters = $this->filters( $payload['filters'] ?? [], $contract, $fields );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}
		$sort = $this->sort( $payload['sort'] ?? null, $contract, $fields );
		if ( is_wp_error( $sort ) ) {
			return $sort;
		}
		$facets = $this->facets( $payload['facets'] ?? null, $contract );
		if ( is_wp_error( $facets ) ) {
			return $facets;
		}
		$search = mb_substr( sanitize_text_field( $payload['search'] ?? '' ), 0, 200 );
		$public_search = array_values( array_filter( $contract['search_field_ids'] ?? [], fn( $field_id ) => $this->public_field( $fields[ $field_id ] ?? null ) ) );
		if ( '' !== $search && ! $public_search ) {
			return $this->error( 'eit_collection_search_not_allowed', __( 'This Collection does not expose searchable fields.', 'elementor-implementation-toolkit' ), 400 );
		}
		$per_page = isset( $payload['per_page'] ) ? absint( $payload['per_page'] ) : absint( $contract['page_size'] ?? 24 );
		if ( $per_page < 1 || $per_page > self::MAX_PER_PAGE ) {
			return $this->error( 'eit_collection_page_size_invalid', __( 'Collection page size must be between one and 48.', 'elementor-implementation-toolkit' ), 400 );
		}
		$snapshot = $this->snapshot( $payload['legacy_snapshot'] ?? [], $contract );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$request = [
			'page' => max( 1, absint( $payload['page'] ?? 1 ) ),
			'per_page' => $per_page,
			'search' => $search,
			'filters' => $filters,
			'sort' => $sort,
			'facets' => $facets,
			'explain' => ! empty( $contract['explain'] ) && ! empty( $payload['explain'] ),
			'legacy_snapshot' => $snapshot,
		];
		$request['_cost'] = $this->cost( $request, $contract );
		return $request['_cost'] > self::MAX_COST
			? $this->error( 'eit_collection_cost_exceeded', __( 'The Collection request is too expensive. Reduce filters or facets.', 'elementor-implementation-toolkit' ), 422, [ 'cost' => $request['_cost'], 'limit' => self::MAX_COST ] )
			: $request;
	}

	public function cost( array $request, array $contract ) {
		$provider = $contract['provider']['id'] ?? '';
		$subjects = $this->cost_subjects( $provider, $request );
		$leaves = 0;
		foreach ( $request['filters'] ?? [] as $filter ) {
			$leaves += $this->leaf_count( $filter['value'] ?? null );
		}
		$operations = max( 1, count( $request['filters'] ?? [] ) + $leaves );
		$facet_cost = count( $request['facets'] ?? [] ) * $subjects;
		$sort_cost = empty( $request['sort']['field_id'] ) ? 0 : $subjects;
		$search_cost = '' === ( $request['search'] ?? '' ) ? 0 : $subjects;
		return ( $subjects * $operations ) + $sort_cost + $facet_cost + $search_cost + ( absint( $request['per_page'] ?? 24 ) * 4 );
	}

	private function cost_subjects( $provider, array $request ) {
		if ( 'legacy_dom' === $provider ) {
			return max( 1, count( $request['legacy_snapshot'] ?? [] ) );
		}
		return in_array( $provider, [ 'wp_query', 'woocommerce' ], true ) ? 1200 : 100;
	}

	private function filters( $filters, array $contract, array $fields ) {
		if ( ! is_array( $filters ) || ! array_is_list( $filters ) || count( $filters ) > self::MAX_FILTERS ) {
			return $this->error( 'eit_collection_filters_invalid', __( 'Collection filters must be a list of at most 20 decisions.', 'elementor-implementation-toolkit' ), 400 );
		}
		$result = [];
		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) || array_diff( array_keys( $filter ), [ 'field_id', 'operator', 'value' ] ) ) {
				return $this->error( 'eit_collection_filter_invalid', __( 'Each filter must use only Field ID, operator and value.', 'elementor-implementation-toolkit' ), 400 );
			}
			$field_id = (string) ( $filter['field_id'] ?? '' );
			$field = $fields[ $field_id ] ?? null;
			$operator = sanitize_key( $filter['operator'] ?? '' );
			if ( ! $this->public_field( $field ) || ! in_array( $field_id, $contract['filter_field_ids'] ?? [], true ) || ! in_array( $operator, $this->semantics->operators( $field ), true ) ) {
				return $this->error( 'eit_collection_filter_not_allowed', __( 'A filter is not allowed by this Collection Field contract.', 'elementor-implementation-toolkit' ), 400 );
			}
			$value = $this->value( $filter['value'] ?? null, $field, $operator );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$result[] = [ 'field_id' => $field_id, 'operator' => $operator, 'value' => $value ];
		}
		return $result;
	}

	private function sort( $sort, array $contract, array $fields ) {
		$sort = null === $sort ? ( $contract['default_sort'] ?? [] ) : $sort;
		if ( ! is_array( $sort ) || array_diff( array_keys( $sort ), [ 'field_id', 'direction' ] ) ) {
			return $this->error( 'eit_collection_sort_invalid', __( 'Collection sort must use a published Field ID and direction.', 'elementor-implementation-toolkit' ), 400 );
		}
		$field_id = (string) ( $sort['field_id'] ?? '' );
		$direction = strtolower( (string) ( $sort['direction'] ?? 'asc' ) );
		if ( '' === $field_id ) {
			return [ 'field_id' => '', 'direction' => 'asc' ];
		}
		if ( ! $this->public_field( $fields[ $field_id ] ?? null ) || ! in_array( $field_id, $contract['sort_field_ids'] ?? [], true ) || ! in_array( $direction, [ 'asc', 'desc' ], true ) ) {
			return $this->error( 'eit_collection_sort_not_allowed', __( 'The requested sort is not allowed by this Collection.', 'elementor-implementation-toolkit' ), 400 );
		}
		return [ 'field_id' => $field_id, 'direction' => $direction ];
	}

	private function facets( $facets, array $contract ) {
		$fields = array_column( $contract['fields'] ?? [], null, 'id' );
		$allowed = array_values( array_filter( $contract['filter_surface']['facet_field_ids'] ?? [], fn( $field_id ) => $this->public_field( $fields[ $field_id ] ?? null ) ) );
		$facets = null === $facets ? $allowed : $facets;
		if ( ! is_array( $facets ) || ! array_is_list( $facets ) || count( $facets ) > self::MAX_FACETS || array_diff( $facets, $allowed ) ) {
			return $this->error( 'eit_collection_facets_not_allowed', __( 'Requested facets are not part of this Filter Surface.', 'elementor-implementation-toolkit' ), 400 );
		}
		return array_values( array_unique( array_map( 'strval', $facets ) ) );
	}

	private function public_field( $field ) {
		return is_array( $field ) && ! empty( $field['exposure']['public'] );
	}

	private function snapshot( $snapshot, array $contract ) {
		if ( 'legacy_dom' !== ( $contract['provider']['id'] ?? '' ) ) {
			return empty( $snapshot ) ? [] : $this->error( 'eit_collection_snapshot_not_allowed', __( 'DOM snapshots are accepted only by a legacy Collection.', 'elementor-implementation-toolkit' ), 400 );
		}
		return is_array( $snapshot ) && array_is_list( $snapshot ) && count( $snapshot ) <= self::MAX_DOM_ITEMS
			? $snapshot
			: $this->error( 'eit_collection_snapshot_invalid', __( 'Legacy DOM snapshot accepts at most 200 items.', 'elementor-implementation-toolkit' ), 400 );
	}

	private function value( $value, array $field, $operator ) {
		if ( in_array( $operator, [ 'in', 'not_in' ], true ) ) {
			if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 25 ) {
				return $this->error( 'eit_collection_filter_value_invalid', __( 'This filter requires a list of at most 25 values.', 'elementor-implementation-toolkit' ), 400 );
			}
			$values = [];
			foreach ( $value as $item ) {
				$normalized = $this->scalar( $item, $field );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}
				$values[] = $normalized;
			}
			return array_values( array_unique( $values, SORT_REGULAR ) );
		}
		if ( 'between' === $operator ) {
			if ( ! is_array( $value ) ) {
				return $this->error( 'eit_collection_filter_value_invalid', __( 'A range filter requires minimum and maximum values.', 'elementor-implementation-toolkit' ), 400 );
			}
			$minimum = $value['min'] ?? $value['from'] ?? null;
			$maximum = $value['max'] ?? $value['to'] ?? null;
			$minimum = $this->scalar( $minimum, $field, true );
			$maximum = $this->scalar( $maximum, $field, true );
			if ( is_wp_error( $minimum ) ) {
				return $minimum;
			}
			if ( is_wp_error( $maximum ) ) {
				return $maximum;
			}
			if ( '' === (string) $minimum && '' === (string) $maximum ) {
				return $this->error( 'eit_collection_filter_value_required', __( 'A range needs at least one boundary.', 'elementor-implementation-toolkit' ), 400 );
			}
			if ( '' !== (string) $minimum && '' !== (string) $maximum && $minimum > $maximum ) {
				[ $minimum, $maximum ] = [ $maximum, $minimum ];
			}
			return [ 'min' => $minimum, 'max' => $maximum ];
		}
		return $this->scalar( $value, $field );
	}

	private function scalar( $value, array $field, $optional = false ) {
		if ( is_array( $value ) || is_object( $value ) || ( ! $optional && null === $value ) ) {
			return $this->error( 'eit_collection_filter_value_invalid', __( 'Filter value has an invalid shape.', 'elementor-implementation-toolkit' ), 400 );
		}
		if ( $optional && ( null === $value || '' === (string) $value ) ) {
			return '';
		}
		$type = $this->semantics->value_type( $field );
		if ( 'number' === $type ) {
			return is_numeric( $value ) ? (float) $value : $this->error( 'eit_collection_filter_value_invalid', __( 'Filter value must be numeric.', 'elementor-implementation-toolkit' ), 400 );
		}
		if ( 'boolean' === $type ) {
			return in_array( $value, [ true, 1, '1', 'true' ], true );
		}
		$value = mb_substr( sanitize_text_field( (string) $value ), 0, 500 );
		return '' === $value && ! $optional ? $this->error( 'eit_collection_filter_value_required', __( 'Filter value is required.', 'elementor-implementation-toolkit' ), 400 ) : $value;
	}

	private function leaf_count( $value ) {
		if ( ! is_array( $value ) ) {
			return '' === trim( (string) $value ) ? 0 : 1;
		}
		$count = 0;
		foreach ( $value as $item ) {
			$count += min( 25, $this->leaf_count( $item ) );
		}
		return min( 25, $count );
	}

	private function error( $code, $message, $status, array $data = [] ) {
		return new \WP_Error( $code, $message, array_merge( [ 'status' => $status ], $data ) );
	}
}
