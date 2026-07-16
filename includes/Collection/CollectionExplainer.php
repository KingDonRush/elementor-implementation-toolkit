<?php
/**
 * Produces bounded, factual Explain Why checks from projected values.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionExplainer {

	public function explain( array $items, array $request, array $fields, $provider_id ) {
		$result = [];
		foreach ( $items as $item ) {
			$checks = [];
			foreach ( $request['filters'] ?? [] as $filter ) {
				$field_id = $filter['field_id'];
				if ( ! isset( $fields[ $field_id ] ) ) {
					continue;
				}
				$actual = $item['values'][ $field_id ] ?? null;
				$checks[] = [
					'field_id' => $field_id,
					'field' => $fields[ $field_id ]['name'],
					'operator' => $filter['operator'],
					'expected' => $filter['value'],
					'actual' => $actual,
					'result' => $this->matches( $actual, $filter['value'], $filter['operator'] ),
				];
			}
			$result[] = [
				'item_id' => $item['id'],
				'origin' => $this->origin( $provider_id ),
				'checks' => $checks,
				'fallback' => 'legacy_dom' === $provider_id,
			];
		}
		return $result;
	}

	private function matches( $actual, $expected, $operator ) {
		$actual_values = $this->values( $actual );
		$expected_values = $this->values( $expected );
		if ( 'between' === $operator ) {
			$number = $this->number( $actual );
			$minimum = $this->number( $expected['min'] ?? null );
			$maximum = $this->number( $expected['max'] ?? null );
			return ( '' === (string) ( $expected['min'] ?? '' ) || $number >= $minimum )
				&& ( '' === (string) ( $expected['max'] ?? '' ) || $number <= $maximum );
		}
		if ( in_array( $operator, [ 'gte', 'lte' ], true ) ) {
			return 'gte' === $operator ? $this->number( $actual ) >= $this->number( $expected ) : $this->number( $actual ) <= $this->number( $expected );
		}
		$intersects = (bool) array_intersect( $actual_values, $expected_values );
		if ( in_array( $operator, [ 'not_equals', 'not_in' ], true ) ) {
			return ! $intersects;
		}
		return $intersects;
	}

	private function values( $value ) {
		if ( is_array( $value ) ) {
			if ( array_key_exists( 'amount', $value ) ) {
				$value = $value['amount'];
			} else {
				$result = [];
				foreach ( $value as $item ) {
					$result = array_merge( $result, $this->values( $item ) );
				}
				return array_values( array_unique( $result ) );
			}
		}
		return [ mb_strtolower( trim( (string) $value ) ) ];
	}

	private function number( $value ) {
		if ( is_array( $value ) ) {
			$value = $value['amount'] ?? reset( $value );
		}
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	private function origin( $provider_id ) {
		return [
			'wp_query' => __( 'Published WordPress content', 'elementor-implementation-toolkit' ),
			'cct_indexed' => __( 'Published Toolkit records', 'elementor-implementation-toolkit' ),
			'woocommerce' => __( 'WooCommerce catalog API', 'elementor-implementation-toolkit' ),
			'legacy_dom' => __( 'Bounded legacy page snapshot', 'elementor-implementation-toolkit' ),
		][ $provider_id ] ?? __( 'Registered Collection provider', 'elementor-implementation-toolkit' );
	}
}
