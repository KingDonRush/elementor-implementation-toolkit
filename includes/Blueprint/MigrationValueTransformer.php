<?php
/**
 * Safe, deterministic value transformations for staged field migrations.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationValueTransformer {

	const NUMERIC_TYPES = [ 'integer', 'decimal', 'money', 'percentage' ];

	public function strategy( array $source, array $target ) {
		$from_type = sanitize_key( $source['type'] ?? '' );
		$to_type = sanitize_key( $target['type'] ?? '' );
		$from_shape = sanitize_key( $source['shape'] ?? '' );
		$to_shape = sanitize_key( $target['shape'] ?? '' );

		if ( '' === $from_type || '' === $to_type || '' === $from_shape || '' === $to_shape ) {
			return new \WP_Error( 'eit_migration_semantics_missing', __( 'Field migration semantics are incomplete.', 'elementor-implementation-toolkit' ) );
		}
		if ( $from_type === $to_type && $from_shape === $to_shape ) {
			return 'identity';
		}
		if ( 'scalar' !== $from_shape || 'scalar' !== $to_shape ) {
			return new \WP_Error( 'eit_migration_transform_unsupported', __( 'This field shape does not provide a safe built-in migration transform.', 'elementor-implementation-toolkit' ) );
		}
		if ( in_array( $from_type, self::NUMERIC_TYPES, true ) && in_array( $to_type, self::NUMERIC_TYPES, true ) ) {
			return 'integer' === $to_type ? 'strict_integer' : 'strict_decimal';
		}
		if ( 'boolean' === $from_type && in_array( $to_type, self::NUMERIC_TYPES, true ) ) {
			return 'integer' === $to_type ? 'boolean_to_integer' : 'boolean_to_decimal';
		}
		if ( in_array( $from_type, self::NUMERIC_TYPES, true ) && 'boolean' === $to_type ) {
			return 'strict_boolean';
		}

		return new \WP_Error( 'eit_migration_transform_unsupported', __( 'This field type change does not provide a safe built-in migration transform.', 'elementor-implementation-toolkit' ) );
	}

	public function transform( $value, $strategy ) {
		if ( null === $value ) {
			return null;
		}
		switch ( sanitize_key( $strategy ) ) {
			case 'identity':
				return $value;
			case 'strict_integer':
				return $this->strict_integer( $value );
			case 'strict_decimal':
				return $this->strict_decimal( $value );
			case 'boolean_to_integer':
				return $this->strict_boolean_value( $value, false );
			case 'boolean_to_decimal':
				$boolean = $this->strict_boolean_value( $value, false );
				return is_wp_error( $boolean ) ? $boolean : (string) $boolean;
			case 'strict_boolean':
				return $this->strict_boolean_value( $value, true );
			default:
				return new \WP_Error( 'eit_migration_transform_invalid', __( 'Field migration transform is not registered.', 'elementor-implementation-toolkit' ) );
		}
	}

	public function canonical( $value, array $semantics ) {
		if ( null === $value ) {
			return null;
		}
		$type = sanitize_key( $semantics['type'] ?? '' );
		if ( 'boolean' === $type ) {
			$boolean = $this->strict_boolean_value( $value, true );
			return is_wp_error( $boolean ) ? $boolean : (string) $boolean;
		}
		if ( 'integer' === $type ) {
			$integer = $this->strict_integer( $value );
			return is_wp_error( $integer ) ? $integer : (string) $integer;
		}
		if ( in_array( $type, [ 'decimal', 'money', 'percentage' ], true ) ) {
			return $this->strict_decimal( $value );
		}
		return is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function strict_integer( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		$raw = trim( (string) $value );
		if ( ! preg_match( '/^[+-]?\d+(?:\.0+)?$/', $raw ) ) {
			return $this->value_error();
		}
		$number = filter_var( preg_replace( '/\.0+$/', '', $raw ), FILTER_VALIDATE_INT );
		return false === $number ? $this->value_error() : $number;
	}

	private function strict_decimal( $value ) {
		$raw = trim( (string) $value );
		if ( ! preg_match( '/^[+-]?(?:\d+|\d*\.\d+)$/', $raw ) ) {
			return $this->value_error();
		}
		$negative = str_starts_with( $raw, '-' );
		$raw = ltrim( $raw, '+-' );
		$parts = explode( '.', $raw, 2 );
		$whole = ltrim( $parts[0], '0' );
		$whole = '' === $whole ? '0' : $whole;
		$fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
		if ( strlen( $whole ) > 14 || strlen( $fraction ) > 6 ) {
			return $this->value_error();
		}
		$normalized = $whole . ( '' !== $fraction ? '.' . $fraction : '' );
		return $negative && '0' !== $normalized ? '-' . $normalized : $normalized;
	}

	private function strict_boolean_value( $value, $allow_numeric ) {
		if ( true === $value || 1 === $value || '1' === $value ) {
			return 1;
		}
		if ( false === $value || 0 === $value || '0' === $value ) {
			return 0;
		}
		if ( $allow_numeric && is_numeric( $value ) ) {
			$numeric = (float) $value;
			if ( 0.0 === $numeric || 1.0 === $numeric ) {
				return (int) $numeric;
			}
		}
		return $this->value_error();
	}

	private function value_error() {
		return new \WP_Error( 'eit_migration_value_invalid', __( 'Stored data cannot be transformed without loss.', 'elementor-implementation-toolkit' ) );
	}
}
