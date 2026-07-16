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
		$scalar_types = [ 'short_text', 'long_text', 'rich_text', 'integer', 'decimal', 'percentage', 'boolean', 'date', 'time', 'datetime', 'email', 'url', 'color', 'phone' ];
		if ( in_array( $type, $scalar_types, true ) && ! is_scalar( $value ) && null !== $value ) {
			return $this->error( $type );
		}
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
			$value = (array) $value;
			return array_is_list( $value ) && ! array_filter( $value, fn( $item ) => ! is_scalar( $item ) )
				? array_values( array_filter( array_map( 'absint', array_slice( $value, 0, self::MAX_LIST_ITEMS ) ) ) )
				: $this->error( 'taxonomy' );
		}
		if ( 'relation' === $type ) {
			return $this->identities( $value );
		}
		if ( 'repeatable_group' === $type ) {
			return $this->repeatable_group( $value, $field );
		}
		if ( 'schedule' === $type ) {
			return $this->schedule( $value );
		}
		if ( in_array( $type, [ 'availability', 'address', 'geopoint' ], true ) ) {
			return $this->structured_object( $value, $type, $field );
		}
		return sanitize_text_field( $value );
	}

	private function number( $value, $integer ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return $this->error( 'number' );
		}
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
		if ( '' === $amount ) {
			return null;
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
		if ( ( $multiple && ( ! is_array( $value ) || ! array_is_list( $value ) ) ) || ( ! $multiple && ! is_scalar( $value ) && null !== $value ) ) {
			return $this->error( 'choice' );
		}
		$values = $multiple ? array_slice( $value, 0, self::MAX_LIST_ITEMS ) : [ $value ];
		if ( array_filter( $values, fn( $item ) => ! is_scalar( $item ) && null !== $item ) ) {
			return $this->error( 'choice' );
		}
		$values = array_values( array_unique( array_map( 'sanitize_text_field', $values ) ) );
		if ( $allowed && array_diff( $values, $allowed ) ) {
			return $this->error( 'choice' );
		}
		return $multiple ? $values : ( $values[0] ?? '' );
	}

	private function date_value( $value, $type ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return $this->error( $type );
		}
		$value = trim( (string) $value );
		$patterns = [
			'date' => '/^\d{4}-\d{2}-\d{2}$/',
			'time' => '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
			'datetime' => '/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d$/',
		];
		return '' === $value || preg_match( $patterns[ $type ], $value ) ? $value : $this->error( $type );
	}

	private function media( $value ) {
		$pending_token = is_array( $value ) ? strtolower( trim( (string) ( $value['pending_token'] ?? '' ) ) ) : '';
		if ( '' !== $pending_token ) {
			return preg_match( '/^[a-f0-9]{64}$/', $pending_token ) ? [ 'pending_token' => $pending_token ] : $this->error( 'media' );
		}
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
		$seen = [];
		foreach ( array_slice( (array) $value, 0, self::MAX_LIST_ITEMS ) as $item ) {
			$id = trim( (string) ( is_array( $item ) ? ( $item['id'] ?? '' ) : $item ) );
			if ( '' !== $id && ! isset( $seen[ $id ] ) && preg_match( '/^[a-zA-Z0-9:_-]{1,191}$/', $id ) ) {
				$result[] = [ 'id' => $id ];
				$seen[ $id ] = true;
			}
		}
		return $result;
	}

	private function repeatable_group( $value, array $field ) {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return $this->error( 'list' );
		}
		$children = array_column( $field['validation']['children'] ?? [], null, 'id' );
		$limit = min( self::MAX_LIST_ITEMS, max( 1, absint( $field['validation']['max_items'] ?? self::MAX_LIST_ITEMS ) ) );
		$minimum = min( $limit, absint( $field['validation']['min_items'] ?? 0 ) );
		if ( ! $children || count( $value ) > $limit ) {
			return $this->error( 'repeatable_group' );
		}
		$result = [];
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) || array_diff( array_keys( $row ), array_keys( $children ) ) ) {
				return $this->error( 'repeatable_group' );
			}
			$clean = [];
			foreach ( $children as $child_id => $child ) {
				if ( ! array_key_exists( $child_id, $row ) ) {
					if ( ! empty( $child['validation']['required'] ) ) {
						return $this->error( 'repeatable_group' );
					}
					continue;
				}
				$child_value = $this->sanitize( $row[ $child_id ], $child );
				if ( is_wp_error( $child_value ) || ( ! empty( $child['validation']['required'] ) && $this->missing( $child_value ) ) ) {
					return $this->error( 'repeatable_group' );
				}
				$clean[ $child_id ] = $child_value;
			}
			if ( ! $this->missing( $clean ) ) {
				$result[] = $clean;
			}
		}
		return count( $result ) < $minimum ? $this->error( 'repeatable_group' ) : $result;
	}

	private function schedule( $value ) {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 50 ) {
			return $this->error( 'schedule' );
		}
		$days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
		$result = [];
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) || array_diff( array_keys( $row ), [ 'day', 'start', 'end' ] ) ) {
				return $this->error( 'schedule' );
			}
			$start = $this->date_value( $row['start'] ?? '', 'time' );
			$end = $this->date_value( $row['end'] ?? '', 'time' );
			$day = sanitize_key( $row['day'] ?? '' );
			if ( is_wp_error( $start ) || is_wp_error( $end ) || '' === $start || '' === $end || '' === $day || ! in_array( $day, $days, true ) ) {
				return $this->error( 'schedule' );
			}
			$result[] = [ 'day' => $day, 'start' => $start, 'end' => $end ];
		}
		return $result;
	}

	private function structured_object( $value, $type, array $field ) {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return $this->error( $type );
		}
		if ( 'geopoint' === $type ) {
			return $this->geopoint( $value );
		}
		if ( 'availability' === $type ) {
			return $this->availability( $value, $field );
		}
		$keys = [ 'street', 'city', 'region', 'postal_code', 'country' ];
		if ( array_diff( array_keys( $value ), $keys ) ) {
			return $this->error( 'address' );
		}
		$clean = array_map( fn( $item ) => mb_substr( sanitize_text_field( $item ), 0, 200 ), $value );
		$clean = array_filter( $clean, fn( $item ) => '' !== $item );
		return $clean ?: null;
	}

	private function geopoint( array $value ) {
		if ( array_diff( array_keys( $value ), [ 'latitude', 'longitude' ] ) ) {
			return $this->error( 'geopoint' );
		}
		$latitude = $this->number( $value['latitude'] ?? '', false );
		$longitude = $this->number( $value['longitude'] ?? '', false );
		if ( '' === $latitude && '' === $longitude ) {
			return null;
		}
		if ( is_wp_error( $latitude ) || is_wp_error( $longitude ) || '' === $latitude || '' === $longitude || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ) {
			return $this->error( 'geopoint' );
		}
		return [ 'latitude' => $latitude, 'longitude' => $longitude ];
	}

	private function availability( array $value, array $field ) {
		if ( array_diff( array_keys( $value ), [ 'status', 'starts', 'ends' ] ) ) {
			return $this->error( 'availability' );
		}
		$status = sanitize_key( $value['status'] ?? '' );
		$allowed = array_values(
			array_filter(
				array_map(
					fn( $item ) => sanitize_key( is_array( $item ) ? ( $item['value'] ?? '' ) : $item ),
					$field['validation']['statuses'] ?? []
				)
			)
		);
		$starts = $this->date_value( $value['starts'] ?? '', 'datetime' );
		$ends = $this->date_value( $value['ends'] ?? '', 'datetime' );
		if ( '' === $status && '' === $starts && '' === $ends ) {
			return null;
		}
		if ( is_wp_error( $starts ) || is_wp_error( $ends ) || ( $allowed && ! in_array( $status, $allowed, true ) ) || ( $starts && $ends && $starts > $ends ) ) {
			return $this->error( 'availability' );
		}
		return array_filter( [ 'status' => $status, 'starts' => $starts, 'ends' => $ends ], fn( $item ) => '' !== $item );
	}

	private function missing( $value ) {
		if ( is_array( $value ) ) {
			return [] === $value || ! array_filter( $value, fn( $item ) => ! $this->missing( $item ) );
		}
		return null === $value || '' === trim( (string) $value );
	}

	private function error( $type ) {
		return new \WP_Error( 'eit_entry_value_invalid', sprintf( __( 'The %s value is invalid.', 'elementor-implementation-toolkit' ), sanitize_key( $type ) ) );
	}
}
