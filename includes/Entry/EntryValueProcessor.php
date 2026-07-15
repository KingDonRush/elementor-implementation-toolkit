<?php
/**
 * Validates Field-ID input, conditions, required values and calculations.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryValueProcessor {

	private $sanitizer;
	private $conditions;
	private $expressions;

	public function __construct( FieldValueSanitizer $sanitizer = null, ConditionEvaluator $conditions = null, SafeExpression $expressions = null ) {
		$this->sanitizer = $sanitizer ?: new FieldValueSanitizer();
		$this->conditions = $conditions ?: new ConditionEvaluator();
		$this->expressions = $expressions ?: new SafeExpression();
	}

	public function process( array $contract, $input, array $options = [] ) {
		if ( ! is_array( $input ) || array_is_list( $input ) || count( $input ) > 200 ) {
			return $this->error( [ '_form' => __( 'Entry values must be an object keyed by Field ID.', 'elementor-implementation-toolkit' ) ] );
		}
		$partial = ! empty( $options['partial'] );
		$existing = is_array( $options['existing'] ?? null ) ? $options['existing'] : [];
		$fields = array_column( $contract['fields'] ?? [], null, 'id' );
		$errors = [];
		$values = $partial ? array_intersect_key( $existing, $fields ) : [];

		foreach ( $input as $field_id => $raw_value ) {
			if ( ! isset( $fields[ $field_id ] ) ) {
				$errors[ $field_id ] = __( 'This field does not belong to the Entry Surface.', 'elementor-implementation-toolkit' );
				continue;
			}
			if ( 'calculated' === ( $fields[ $field_id ]['type'] ?? '' ) ) {
				continue;
			}
			$value = $this->sanitizer->sanitize( $raw_value, $fields[ $field_id ] );
			if ( is_wp_error( $value ) ) {
				$errors[ $field_id ] = $value->get_error_message();
			} else {
				$values[ $field_id ] = $value;
			}
		}
		if ( $errors ) {
			return $this->error( $errors );
		}

		$state = $this->conditions->state( $contract['conditions'] ?? [], $values, array_keys( $fields ) );
		foreach ( $fields as $field_id => $field ) {
			if ( 'calculated' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			if ( empty( $state[ $field_id ]['visible'] ) ) {
				unset( $values[ $field_id ] );
				continue;
			}
			$required = ! empty( $field['validation']['required'] ) || ! empty( $state[ $field_id ]['required'] );
			if ( ! $partial && $required && $this->missing( $values[ $field_id ] ?? null ) ) {
				$errors[ $field_id ] = sprintf( __( '%s is required.', 'elementor-implementation-toolkit' ), $field['name'] );
				continue;
			}
			$this->validate_bounds( $field_id, $values[ $field_id ] ?? null, $field, $errors );
		}
		foreach ( $contract['calculations'] ?? [] as $calculation ) {
			$result = $this->expressions->evaluate( $calculation['expression'] ?? '', $values );
			if ( is_wp_error( $result ) ) {
				$errors[ $calculation['field_id'] ] = $result->get_error_message();
			} else {
				$values[ $calculation['field_id'] ] = $result;
			}
		}
		return $errors ? $this->error( $errors ) : [ 'values' => $values, 'state' => $state ];
	}

	private function validate_bounds( $field_id, $value, array $field, array &$errors ) {
		if ( $this->missing( $value ) ) {
			return;
		}
		$rules = $field['validation'] ?? [];
		if ( is_numeric( $value ) && isset( $rules['min'] ) && (float) $value < (float) $rules['min'] ) {
			$errors[ $field_id ] = sprintf( __( '%s is below the allowed minimum.', 'elementor-implementation-toolkit' ), $field['name'] );
		} elseif ( is_numeric( $value ) && isset( $rules['max'] ) && (float) $value > (float) $rules['max'] ) {
			$errors[ $field_id ] = sprintf( __( '%s exceeds the allowed maximum.', 'elementor-implementation-toolkit' ), $field['name'] );
		} elseif ( is_string( $value ) && isset( $rules['min_length'] ) && mb_strlen( $value ) < absint( $rules['min_length'] ) ) {
			$errors[ $field_id ] = sprintf( __( '%s is too short.', 'elementor-implementation-toolkit' ), $field['name'] );
		} elseif ( is_string( $value ) && isset( $rules['max_length'] ) && mb_strlen( $value ) > absint( $rules['max_length'] ) ) {
			$errors[ $field_id ] = sprintf( __( '%s is too long.', 'elementor-implementation-toolkit' ), $field['name'] );
		}
	}

	private function missing( $value ) {
		if ( is_array( $value ) ) {
			return [] === $value;
		}
		return null === $value || '' === trim( (string) $value );
	}

	private function error( array $fields ) {
		return new \WP_Error( 'eit_entry_validation_failed', __( 'Review the highlighted fields and try again.', 'elementor-implementation-toolkit' ), [ 'fields' => $fields ] );
	}
}
