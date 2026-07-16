<?php
/**
 * Stores CPT multi-choice values as exact, repeated WordPress meta rows.
 */

namespace EIT\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CptMultivalueMeta {

	public static function read( $post_id, $key ) {
		$rows = get_post_meta( absint( $post_id ), (string) $key, false );
		$values = [];
		foreach ( $rows as $row ) {
			foreach ( is_array( $row ) ? $row : [ $row ] as $value ) {
				if ( ! is_scalar( $value ) && null !== $value ) {
					continue;
				}
				$value = sanitize_text_field( (string) $value );
				if ( '' !== $value ) {
					$values[] = $value;
				}
			}
		}
		return array_values( array_unique( $values ) );
	}

	public static function replace( $post_id, $key, array $values ) {
		$post_id = absint( $post_id );
		$key = (string) $key;
		$values = array_values( array_filter( $values, 'is_scalar' ) );
		$values = array_values( array_unique( array_map( fn( $value ) => sanitize_text_field( (string) $value ), $values ) ) );
		delete_post_meta( $post_id, $key );
		foreach ( $values as $value ) {
			if ( '' !== $value && false === add_post_meta( $post_id, $key, $value, false ) ) {
				return new \WP_Error( 'eit_entry_multivalue_meta_failed', __( 'A multiple-choice value could not be stored.', 'elementor-implementation-toolkit' ) );
			}
		}
		return true;
	}
}
