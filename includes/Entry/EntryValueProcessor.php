<?php
/**
 * Validates Field-ID input, conditions, required values and calculations.
 */

namespace EIT\Entry;

use EIT\Blueprint\BlueprintModule;
use EIT\Blueprint\BuiltinFieldPrimitive;
use EIT\Blueprint\FieldPrimitiveRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryValueProcessor {

	private $sanitizer;
	private $conditions;
	private $expressions;
	private $primitives;

	public function __construct( FieldValueSanitizer $sanitizer = null, ConditionEvaluator $conditions = null, SafeExpression $expressions = null, FieldPrimitiveRegistry $primitives = null ) {
		$this->sanitizer = $sanitizer ?: new FieldValueSanitizer();
		$this->conditions = $conditions ?: new ConditionEvaluator();
		$this->expressions = $expressions ?: new SafeExpression();
		$this->primitives = $primitives ?: BlueprintModule::registries()->field_primitives();
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
		$primitives = [];

		foreach ( $input as $field_id => $raw_value ) {
			if ( ! isset( $fields[ $field_id ] ) ) {
				$errors[ $field_id ] = __( 'This field does not belong to the Entry Surface.', 'elementor-implementation-toolkit' );
				continue;
			}
			if ( 'calculated' === ( $fields[ $field_id ]['type'] ?? '' ) ) {
				continue;
			}
			$primitive = $this->primitive( $fields[ $field_id ] );
			$primitives[ $field_id ] = $primitive;
			$value = is_wp_error( $primitive ) ? $primitive : $this->normalize( $raw_value, $fields[ $field_id ], $primitive );
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
			if ( isset( $errors[ $field_id ] ) || ( $partial && ! array_key_exists( $field_id, $values ) ) ) {
				continue;
			}
			$primitive = $primitives[ $field_id ] ?? $this->primitive( $field );
			$message = is_wp_error( $primitive ) ? $primitive->get_error_message() : $this->validate_primitive( $values[ $field_id ] ?? null, $field, $primitive );
			if ( $message ) {
				$errors[ $field_id ] = $message;
			}
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

	private function primitive( array $field ) {
		$primitive = $this->primitives->get( $field['type'] ?? '' );
		if ( ! $primitive ) {
			return new \WP_Error( 'eit_entry_primitive_missing', __( 'The field type is unavailable.', 'elementor-implementation-toolkit' ) );
		}
		try {
			$health = $primitive->health_check();
			$version = (string) $primitive->get_version();
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'eit_entry_primitive_unhealthy', __( 'The field type failed its health check.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! is_array( $health ) || empty( $health['ok'] ) ) {
			return new \WP_Error( 'eit_entry_primitive_unhealthy', __( 'The field type failed its health check.', 'elementor-implementation-toolkit' ) );
		}
		$compiled = is_array( $field['primitive'] ?? null ) ? $field['primitive'] : [];
		if ( $compiled && ( ( $field['type'] ?? '' ) !== ( $compiled['id'] ?? '' ) || ! hash_equals( (string) ( $compiled['version'] ?? '' ), $version ) ) ) {
			return new \WP_Error( 'eit_entry_primitive_version_mismatch', __( 'The field type version differs from the published contract.', 'elementor-implementation-toolkit' ) );
		}
		return $primitive;
	}

	private function normalize( $raw_value, array $field, $primitive ) {
		try {
			if ( $primitive instanceof BuiltinFieldPrimitive ) {
				$value = $this->sanitizer->sanitize( $raw_value, $field );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$normalized = $primitive->normalize( $value, $field );
				return null === $value && [] === $normalized ? null : $normalized;
			}
			$input = $this->sanitize_extension_value( $raw_value );
			if ( is_wp_error( $input ) ) {
				return $input;
			}
			$value = $primitive->normalize( $input, $field );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$value = $this->sanitize_extension_value( $value );
			return is_wp_error( $value ) || $this->shape_is_valid( $value, $field['shape'] ?? 'scalar' )
				? $value
				: new \WP_Error( 'eit_entry_primitive_shape_invalid', __( 'The field type returned an incompatible value.', 'elementor-implementation-toolkit' ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'eit_entry_primitive_normalize_failed', __( 'The field value could not be normalized.', 'elementor-implementation-toolkit' ) );
		}
	}

	private function validate_primitive( $value, array $field, $primitive ) {
		try {
			$result = $primitive->validate( $value, $field );
		} catch ( \Throwable $error ) {
			return __( 'The field value could not be validated.', 'elementor-implementation-toolkit' );
		}
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		if ( true === $result || null === $result || [] === $result ) {
			return '';
		}
		if ( is_array( $result ) ) {
			$result = reset( $result );
			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			}
		}
		return is_string( $result ) && '' !== trim( $result )
			? sanitize_text_field( $result )
			: sprintf( __( '%s is invalid.', 'elementor-implementation-toolkit' ), $field['name'] ?? __( 'This field', 'elementor-implementation-toolkit' ) );
	}

	private function sanitize_extension_value( $value, $depth = 0 ) {
		if ( $depth > 4 ) {
			return new \WP_Error( 'eit_entry_primitive_value_too_deep', __( 'The field value is too deeply nested.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! is_array( $value ) ) {
			if ( is_string( $value ) ) {
				return sanitize_text_field( $value );
			}
			return is_scalar( $value ) || null === $value
				? $value
				: new \WP_Error( 'eit_entry_primitive_value_invalid', __( 'The field value contains an unsupported value.', 'elementor-implementation-toolkit' ) );
		}
		if ( count( $value ) > FieldValueSanitizer::MAX_LIST_ITEMS ) {
			return new \WP_Error( 'eit_entry_primitive_value_too_large', __( 'The field value contains too many items.', 'elementor-implementation-toolkit' ) );
		}
		$result = [];
		foreach ( $value as $key => $child ) {
			$child = $this->sanitize_extension_value( $child, $depth + 1 );
			if ( is_wp_error( $child ) ) {
				return $child;
			}
			$result[ $key ] = $child;
		}
		return $result;
	}

	private function shape_is_valid( $value, $shape ) {
		if ( 'list' === $shape ) {
			return is_array( $value ) && array_is_list( $value );
		}
		if ( 'object' === $shape ) {
			return is_array( $value ) && ( [] === $value || ! array_is_list( $value ) );
		}
		return null === $value || is_scalar( $value );
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
			return [] === $value || ! array_filter( $value, fn( $item ) => ! $this->missing( $item ) );
		}
		return null === $value || '' === trim( (string) $value );
	}

	private function error( array $fields ) {
		return new \WP_Error( 'eit_entry_validation_failed', __( 'Review the highlighted fields and try again.', 'elementor-implementation-toolkit' ), [ 'fields' => $fields ] );
	}
}
