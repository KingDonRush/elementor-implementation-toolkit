<?php
/**
 * Public filter endpoint request limits and cost accounting.
 */

namespace EIT\Rest;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterRequestPolicy {

	const MAX_BODY_BYTES = 32768;
	const MAX_FILTERS = 20;
	const MAX_DOM_ITEMS = 200;
	const DEFAULT_PER_PAGE = 24;
	const MAX_PER_PAGE = 48;
	const MAX_COST = 10000;

	public function validate( WP_REST_Request $request ) {
		$body = (string) $request->get_body();

		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new \WP_Error(
				'eit_request_too_large',
				__( 'The filter request exceeds the 32 KB limit.', 'elementor-implementation-toolkit' ),
				[ 'status' => 413 ]
			);
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'eit_invalid_filter_payload',
				__( 'The filter request must contain a JSON object.', 'elementor-implementation-toolkit' ),
				[ 'status' => 400 ]
			);
		}

		$filters = is_array( $payload['filters'] ?? null ) ? $payload['filters'] : [];
		$items = is_array( $payload['items'] ?? null ) ? $payload['items'] : [];

		if ( count( $filters ) > self::MAX_FILTERS ) {
			return $this->limit_error( 'eit_too_many_filters', __( 'A collection request accepts at most 20 filters.', 'elementor-implementation-toolkit' ) );
		}

		if ( 'cct' !== ( $payload['provider'] ?? 'dom' ) && count( $items ) > self::MAX_DOM_ITEMS ) {
			return $this->limit_error( 'eit_too_many_dom_items', __( 'The legacy DOM provider accepts at most 200 items.', 'elementor-implementation-toolkit' ) );
		}

		$per_page = isset( $payload['perPage'] ) ? absint( $payload['perPage'] ) : self::DEFAULT_PER_PAGE;
		if ( $per_page < 1 || $per_page > self::MAX_PER_PAGE ) {
			return $this->limit_error( 'eit_invalid_page_size', __( 'Page size must be between 1 and 48 items.', 'elementor-implementation-toolkit' ) );
		}

		$payload['perPage'] = $per_page;
		$cost = $this->cost( $payload );
		if ( $cost > self::MAX_COST ) {
			return new \WP_Error(
				'eit_request_cost_exceeded',
				__( 'The filter combination is too expensive. Reduce filters or selected values.', 'elementor-implementation-toolkit' ),
				[
					'status' => 422,
					'cost'   => $cost,
					'limit'  => self::MAX_COST,
				]
			);
		}

		$payload['_eitRequestCost'] = $cost;
		return $payload;
	}

	public function cost( array $payload ) {
		$provider = 'cct' === ( $payload['provider'] ?? 'dom' ) ? 'cct' : 'dom';
		$subject_count = 'cct' === $provider ? 100 : max( 1, count( (array) ( $payload['items'] ?? [] ) ) );
		$filters = (array) ( $payload['filters'] ?? [] );
		$value_units = 0;

		foreach ( $filters as $filter ) {
			if ( is_array( $filter ) ) {
				$value_units += $this->leaf_count( $filter['value'] ?? null, 25 );
			}
		}

		$operations = max( 1, count( $filters ) + $value_units );
		$sort_cost = empty( $payload['sort'] ) || 'default' === $payload['sort'] ? 0 : $subject_count;
		$page_cost = min( self::MAX_PER_PAGE, max( 1, absint( $payload['perPage'] ?? self::DEFAULT_PER_PAGE ) ) ) * 4;

		return ( $subject_count * $operations ) + $sort_cost + $page_cost;
	}

	private function leaf_count( $value, $remaining ) {
		if ( $remaining < 1 ) {
			return 1;
		}

		if ( ! is_array( $value ) ) {
			return '' === trim( (string) $value ) ? 0 : 1;
		}

		$count = 0;
		foreach ( $value as $item ) {
			$count += $this->leaf_count( $item, $remaining - 1 );
			if ( $count >= 25 ) {
				return 25;
			}
		}

		return $count;
	}

	private function limit_error( $code, $message ) {
		return new \WP_Error( $code, $message, [ 'status' => 400 ] );
	}
}
