<?php
/**
 * Sanitizes one semantic Field Contract without accepting storage keys.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FieldValueSanitizer {

	const MAX_LIST_ITEMS = 100;

	public function sanitize( $value, array $field ) {
		$type = $field['type'] ?? 'short_text';
		if ( in_array( $type, [ 'integer', 'decimal', 'percentage' ], true ) ) {
			return $this->number( $value, 'integer' === $type );
		}
		if ( 'money' === $type ) {
			return $this->money( $value, $field );
		}
		if ( 'boolean' === $type ) {
			return in_array( $value, [ true, 1, '1', 'true', 'yes', 'on' ], true );
		}
		if ( in_array( $type, [ 'single_choice', 'multiple_choice' ], true ) ) {
			return $this->choice( $value, $field, 'multiple_choice' === $type );
		}
		if ( in_array( $type, [ 'date', 'time', 'datetime' ], true ) ) {
			return $this->date_value( $value, $type );
		}
		if ( in_array( $type, [ 'image', 'file' ], true ) ) {
			return $this->media( $value );
		}
		if ( 'gallery' === $type ) {
			return $this->media_list( $value );
		}
		if ( 'email' === $type ) {
			$original = trim( (string) $value );
			$value = sanitize_email( $original );
			return '' === $value && '' !== $original ? $this->error( 'email' ) : $value;
		}
		if ( 'url' === $type ) {
			return esc_url_raw( $value );
		}
		if ( 'color' === $type ) {
			$color = sanitize_hex_color( (string) $value );
			return $color ?: $this->error( 'color' );
		}
		if ( 'phone' === $type ) {
			$value = preg_replace( '/[^0-9+() .-]/', '', (string) $value );
			return substr( trim( $value ), 0, 40 );
		}
		if ( 'rich_text' === $type ) {
			return wp_kses_post( (string) $value );
		}
		if ( 'long_text' === $type ) {
			return sanitize_textarea_field( $value );
		}
		if ( 'taxonomy' === $type ) {
			return array_values( array_filter( array_map( 'absint', array_slice( (array) $value, 0, self::MAX_LIST_ITEMS ) ) ) );
		}
		if ( 'relation' === $type ) {
			return $this->identities( $value );
		}
		if ( in_array( $type, [ 'repeatable_group', 'schedule' ], true ) ) {
			return $this->structured_list( $value );
		}
		if ( in_array( $type, [ 'availability', 'address', 'geopoint' ], true ) ) {
			return $this->structured_object( $value );
		}
		return sanitize_text_field( $value );
	}

	private function number( $value, $integer ) {
		if ( '' === trim( (string) $value ) ) {
			return '';
		}
		if ( ! is_numeric( $value ) ) {
			return $this->error( 'number' );
		}
		return $integer ? (int) $value : (float) $value;
	}

	private function money( $value, array $field ) {
		$value = is_array( $value ) ? $value : [ 'amount' => $value ];
		$amount = $this->number( $value['amount'] ?? '', false );
		if ( is_wp_error( $amount ) ) {
			return $amount;
		}
		$currency = strtoupper( preg_replace( '/[^A-Z]/', '', (string) ( $value['currency'] ?? $field['validation']['currency'] ?? 'USD' ) ) );
		return [ 'amount' => $amount, 'currency' => 3 === strlen( $currency ) ? $currency : 'USD' ];
	}

	private function choice( $value, array $field, $multiple ) {
		$allowed = [];
		foreach ( $field['validation']['options'] ?? [] as $option ) {
			$option = is_array( $option ) ? $option : [ 'value' => $option ];
			$allowed[] = (string) ( $option['value'] ?? '' );
		}
		$values = $multiple ? array_slice( (array) $value, 0, self::MAX_LIST_ITEMS ) : [ $value ];
		$values = array_values( array_unique( array_map( 'sanitize_text_field', $values ) ) );
		if ( $allowed && array_diff( $values, $allowed ) ) {
			return $this->error( 'choice' );
		}
		return $multiple ? $values : ( $values[0] ?? '' );
	}

	private function date_value( $value, $type ) {
		$value = trim( (string) $value );
		$patterns = [
			'date' => '/^\d{4}-\d{2}-\d{2}$/',
			'time' => '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
			'datetime' => '/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d$/',
		];
		return '' === $value || preg_match( $patterns[ $type ], $value ) ? $value : $this->error( $type );
	}

	private function media( $value ) {
		$id = absint( is_array( $value ) ? ( $value['id'] ?? 0 ) : $value );
		if ( ! $id ) {
			return null;
		}
		return 'attachment' === get_post_type( $id ) ? [ 'id' => $id ] : $this->error( 'media' );
	}

	private function media_list( $value ) {
		$result = [];
		foreach ( array_slice( (array) $value, 0, 50 ) as $item ) {
			$media = $this->media( $item );
			if ( is_wp_error( $media ) ) {
				return $media;
			}
			if ( $media ) {
				$result[] = $media;
			}
		}
		return $result;
	}

	private function identities( $value ) {
		$result = [];
		foreach ( array_slice( (array) $value, 0, self::MAX_LIST_ITEMS ) as $item ) {
			$id = trim( (string) ( is_array( $item ) ? ( $item['id'] ?? '' ) : $item ) );
			if ( '' !== $id && preg_match( '/^[a-zA-Z0-9:_-]{1,191}$/', $id ) ) {
				$result[] = [ 'id' => $id ];
			}
		}
		return $result;
	}

	private function structured_list( $value ) {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return $this->error( 'list' );
		}
		$result = [];
		foreach ( array_slice( $value, 0, self::MAX_LIST_ITEMS ) as $row ) {
			$result[] = $this->sanitize_deep( $row, 0 );
		}
		return $result;
	}

	private function structured_object( $value ) {
		return is_array( $value ) && ! array_is_list( $value ) ? $this->sanitize_deep( $value, 0 ) : $this->error( 'object' );
	}

	private function sanitize_deep( $value, $depth ) {
		if ( $depth >= 4 || ! is_array( $value ) ) {
			return sanitize_text_field( is_scalar( $value ) ? $value : '' );
		}
		$result = [];
		foreach ( array_slice( $value, 0, 40, true ) as $key => $child ) {
			$result[ sanitize_key( $key ) ] = $this->sanitize_deep( $child, $depth + 1 );
		}
		return $result;
	}

	private function error( $type ) {
		return new \WP_Error( 'eit_entry_value_invalid', sprintf( __( 'The %s value is invalid.', 'elementor-implementation-toolkit' ), sanitize_key( $type ) ) );
	}
}
