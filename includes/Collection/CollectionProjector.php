<?php
/**
 * Removes non-exposed fields and presents typed values without storage details.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionProjector {

	public function fields( array $contract ) {
		$projection = array_fill_keys( $contract['projection_field_ids'] ?? [], true );
		$fields = [];
		foreach ( $contract['fields'] ?? [] as $field ) {
			if ( isset( $projection[ $field['id'] ] ) && $this->can_expose( $field, $contract ) ) {
				$fields[ $field['id'] ] = $field;
			}
		}
		return $fields;
	}

	public function items( array $items, array $fields ) {
		$this->prime_media( $items, $fields );
		$result = [];
		foreach ( $items as $item ) {
			$values = [];
			foreach ( $fields as $field_id => $field ) {
				if ( array_key_exists( $field_id, $item['values'] ?? [] ) ) {
					$values[ $field_id ] = $this->value( $item['values'][ $field_id ], $field );
				}
			}
			$result[] = [
				'id' => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
				'title' => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'url' => esc_url_raw( (string) ( $item['url'] ?? '' ) ),
				'values' => $values,
			];
		}
		return $result;
	}

	public function field_summaries( array $fields ) {
		$result = [];
		foreach ( $fields as $field ) {
			$result[] = [
				'id' => $field['id'],
				'name' => $field['name'],
				'type' => $field['type'],
				'shape' => $field['shape'],
				'validation' => array_intersect_key( $field['validation'] ?? [], array_flip( [ 'min', 'max', 'step' ] ) ),
				'elementor' => array_values( $field['elementor'] ?? [] ),
			];
		}
		return $result;
	}

	private function can_expose( array $field, array $contract ) {
		$exposure = $field['exposure'] ?? [];
		if ( 'public' === ( $contract['access'] ?? '' ) ) {
			return ! empty( $exposure['public'] );
		}
		$roles = array_values( array_filter( array_map( 'sanitize_key', $exposure['roles'] ?? [] ) ) );
		if ( ! $roles ) {
			return true;
		}
		$user = wp_get_current_user();
		return (bool) array_intersect( $roles, is_array( $user->roles ?? null ) ? $user->roles : [] );
	}

	private function value( $value, array $field ) {
		$type = $field['type'] ?? '';
		if ( in_array( $type, [ 'integer', 'decimal', 'percentage', 'calculated' ], true ) ) {
			return is_numeric( $value ) ? (float) $value : null;
		}
		if ( 'money' === $type ) {
			$amount = is_array( $value ) ? ( $value['amount'] ?? null ) : $value;
			return [ 'amount' => is_numeric( $amount ) ? (float) $amount : null, 'currency' => sanitize_key( $field['validation']['currency'] ?? 'USD' ) ];
		}
		if ( 'boolean' === $type ) {
			return (bool) $value;
		}
		if ( in_array( $type, [ 'image', 'file' ], true ) ) {
			return $this->media( $value );
		}
		if ( 'gallery' === $type ) {
			return array_values( array_filter( array_map( [ $this, 'media' ], is_array( $value ) ? $value : [] ) ) );
		}
		if ( 'url' === $type ) {
			return esc_url_raw( (string) $value );
		}
		if ( 'rich_text' === $type ) {
			return wp_kses_post( (string) $value );
		}
		return $this->safe_value( $value );
	}

	private function media( $value ) {
		$id = absint( is_array( $value ) ? ( $value['id'] ?? 0 ) : $value );
		if ( ! $id ) {
			return null;
		}
		return [
			'id' => $id,
			'url' => esc_url_raw( wp_get_attachment_url( $id ) ?: '' ),
			'alt' => sanitize_text_field( get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
		];
	}

	private function prime_media( array $items, array $fields ) {
		$media_fields = array_filter(
			$fields,
			function ( $field ) {
				return in_array( $field['type'] ?? '', [ 'image', 'gallery', 'file' ], true );
			}
		);
		$ids = [];
		foreach ( $items as $item ) {
			foreach ( $media_fields as $field_id => $field ) {
				$value = $item['values'][ $field_id ] ?? [];
				$values = 'gallery' === ( $field['type'] ?? '' ) ? (array) $value : [ $value ];
				foreach ( $values as $media ) {
					$id = absint( is_array( $media ) ? ( $media['id'] ?? 0 ) : $media );
					if ( $id ) {
						$ids[] = $id;
					}
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( $ids ) {
			get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => $ids, 'posts_per_page' => count( $ids ), 'no_found_rows' => true ] );
		}
	}

	private function safe_value( $value ) {
		if ( is_array( $value ) ) {
			$result = [];
			foreach ( array_slice( $value, 0, 100, true ) as $key => $item ) {
				$result[ is_int( $key ) ? $key : sanitize_key( $key ) ] = $this->safe_value( $item );
			}
			return $result;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		return sanitize_text_field( (string) $value );
	}
}
