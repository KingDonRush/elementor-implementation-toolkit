<?php
/**
 * Single operator and control vocabulary derived from semantic Field contracts.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionFieldSemantics {

	public function control( array $field ) {
		$type = $field['type'] ?? '';
		if ( in_array( $type, [ 'integer', 'decimal', 'money', 'percentage', 'calculated' ], true ) ) {
			return 'range';
		}
		if ( in_array( $type, [ 'date', 'datetime', 'time', 'schedule', 'availability' ], true ) ) {
			return 'date';
		}
		if ( 'boolean' === $type ) {
			return 'toggle';
		}
		if ( in_array( $type, [ 'single_choice', 'multiple_choice', 'taxonomy', 'relation' ], true ) ) {
			return 'options';
		}
		return 'search';
	}

	public function operators( array $field ) {
		$declared = array_values( array_intersect( [ 'equals', 'not_equals', 'gte', 'lte', 'between', 'in', 'not_in' ], $field['capabilities']['filter_operators'] ?? [] ) );
		if ( $declared ) {
			return $declared;
		}
		$type = $field['type'] ?? '';
		if ( in_array( $type, [ 'integer', 'decimal', 'money', 'percentage', 'calculated', 'date', 'datetime', 'time' ], true ) ) {
			return [ 'equals', 'gte', 'lte', 'between' ];
		}
		if ( in_array( $type, [ 'multiple_choice', 'taxonomy', 'relation' ], true ) ) {
			return [ 'in', 'not_in' ];
		}
		return [ 'equals', 'not_equals' ];
	}

	public function value_type( array $field ) {
		$type = $field['type'] ?? '';
		if ( in_array( $type, [ 'integer', 'decimal', 'money', 'percentage', 'calculated' ], true ) ) {
			return 'number';
		}
		if ( in_array( $type, [ 'date', 'datetime', 'time' ], true ) ) {
			return 'date';
		}
		if ( 'boolean' === $type ) {
			return 'boolean';
		}
		return 'string';
	}
}
