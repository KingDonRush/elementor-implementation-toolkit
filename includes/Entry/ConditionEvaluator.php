<?php
/**
 * Applies server-authoritative visibility and conditional-required rules.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConditionEvaluator {

	public function state( array $conditions, array $values, array $field_ids ) {
		$state = [];
		foreach ( $field_ids as $field_id ) {
			$state[ $field_id ] = [ 'visible' => true, 'required' => false ];
		}
		foreach ( $conditions as $condition ) {
			$target = $condition['target_field_id'] ?? '';
			if ( ! isset( $state[ $target ] ) ) {
				continue;
			}
			$matches = $this->matches( $values[ $condition['source_field_id'] ?? '' ] ?? null, $condition['operator'] ?? '', $condition['value'] ?? null );
			if ( 'show' === ( $condition['effect'] ?? '' ) ) {
				$state[ $target ]['visible'] = $state[ $target ]['visible'] && $matches;
			} elseif ( 'hide' === ( $condition['effect'] ?? '' ) && $matches ) {
				$state[ $target ]['visible'] = false;
			} elseif ( 'require' === ( $condition['effect'] ?? '' ) && $matches ) {
				$state[ $target ]['required'] = true;
			}
		}
		return $state;
	}

	private function matches( $actual, $operator, $expected ) {
		if ( 'empty' === $operator || 'not_empty' === $operator ) {
			$empty = null === $actual || '' === $actual || [] === $actual;
			return 'empty' === $operator ? $empty : ! $empty;
		}
		if ( 'in' === $operator || 'not_in' === $operator ) {
			$expected = is_array( $expected ) ? $expected : [ $expected ];
			$actual = is_array( $actual ) ? $actual : [ $actual ];
			$found = (bool) array_intersect( array_map( 'strval', $actual ), array_map( 'strval', $expected ) );
			return 'in' === $operator ? $found : ! $found;
		}
		if ( in_array( $operator, [ 'gt', 'gte', 'lt', 'lte' ], true ) && is_numeric( $actual ) && is_numeric( $expected ) ) {
			return $this->numeric_compare( (float) $actual, (float) $expected, $operator );
		}
		$equal = (string) $actual === (string) $expected;
		return 'not_equals' === $operator ? ! $equal : $equal;
	}

	private function numeric_compare( $actual, $expected, $operator ) {
		if ( 'gt' === $operator ) {
			return $actual > $expected;
		}
		if ( 'gte' === $operator ) {
			return $actual >= $expected;
		}
		return 'lt' === $operator ? $actual < $expected : $actual <= $expected;
	}
}
