<?php
/**
 * CCT field type definitions and value normalization.
 */

namespace EIT\CCT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FieldTypes {

	public static function labels() {
		return [
			'text'        => __( 'Text', 'elementor-implementation-toolkit' ),
			'textarea'    => __( 'Textarea', 'elementor-implementation-toolkit' ),
			'number'      => __( 'Number', 'elementor-implementation-toolkit' ),
			'url'         => __( 'URL', 'elementor-implementation-toolkit' ),
			'email'       => __( 'Email', 'elementor-implementation-toolkit' ),
			'date'        => __( 'Date', 'elementor-implementation-toolkit' ),
			'boolean'     => __( 'Boolean', 'elementor-implementation-toolkit' ),
			'select'      => __( 'Select', 'elementor-implementation-toolkit' ),
			'multiselect' => __( 'Multi-select', 'elementor-implementation-toolkit' ),
			'color'       => __( 'Color', 'elementor-implementation-toolkit' ),
			'image'       => __( 'Image', 'elementor-implementation-toolkit' ),
			'gallery'     => __( 'Gallery', 'elementor-implementation-toolkit' ),
		];
	}

	public static function has( $type ) {
		return isset( self::labels()[ sanitize_key( $type ) ] );
	}

	public static function sql_type( $type ) {
		$types = [
			'text'        => 'varchar(255) NULL',
			'textarea'    => 'longtext NULL',
			'number'      => 'decimal(20,6) NULL',
			'url'         => 'text NULL',
			'email'       => 'varchar(320) NULL',
			'date'        => 'date NULL',
			'boolean'     => 'tinyint(1) NOT NULL DEFAULT 0',
			'select'      => 'varchar(191) NULL',
			'multiselect' => 'longtext NULL',
			'color'       => 'varchar(32) NULL',
			'image'       => 'bigint(20) unsigned NULL',
			'gallery'     => 'longtext NULL',
		];

		return $types[ sanitize_key( $type ) ] ?? $types['text'];
	}

	public static function sanitize( $value, array $field ) {
		$type = sanitize_key( $field['type'] ?? 'text' );

		if ( 'boolean' === $type ) {
			return empty( $value ) ? 0 : 1;
		}

		if ( in_array( $type, [ 'multiselect', 'gallery' ], true ) ) {
			$values = is_array( $value ) ? $value : preg_split( '/[\r\n,]+/', (string) $value );
			$values = array_values(
				array_filter(
					array_map(
						function ( $item ) use ( $type ) {
							return 'gallery' === $type ? absint( $item ) : sanitize_text_field( $item );
						},
						$values
					)
				)
			);

			return wp_json_encode( array_values( array_unique( $values ) ) );
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'number':
				return is_numeric( $value ) ? (float) $value : null;
			case 'url':
				return esc_url_raw( $value );
			case 'email':
				return sanitize_email( $value );
			case 'date':
				$value = sanitize_text_field( $value );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
			case 'color':
				return sanitize_hex_color( $value );
			case 'image':
				return absint( $value ) ?: null;
			case 'select':
				$value = sanitize_text_field( $value );
				$options = self::options( $field );
				return empty( $options ) || isset( $options[ $value ] ) ? $value : '';
			default:
				return sanitize_text_field( $value );
		}
	}

	public static function decode( $value, array $field ) {
		$type = sanitize_key( $field['type'] ?? 'text' );

		if ( in_array( $type, [ 'multiselect', 'gallery' ], true ) ) {
			$decoded = json_decode( (string) $value, true );
			return is_array( $decoded ) ? $decoded : [];
		}

		if ( 'boolean' === $type ) {
			return (bool) $value;
		}

		if ( 'image' === $type ) {
			return absint( $value );
		}

		if ( 'number' === $type && null !== $value && '' !== $value ) {
			return (float) $value;
		}

		return $value;
	}

	public static function options( array $field ) {
		$raw = $field['options'] ?? '';
		$lines = is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw );
		$options = [];

		foreach ( $lines as $line ) {
			$parts = array_map( 'trim', explode( '|', (string) $line, 2 ) );
			$value = sanitize_key( $parts[0] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			$options[ $value ] = sanitize_text_field( $parts[1] ?? $parts[0] );
		}

		return $options;
	}
}
