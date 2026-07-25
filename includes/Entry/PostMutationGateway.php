<?php
/**
 * Verifies WordPress post mutations used by governed Entry persistence.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostMutationGateway {

	private $touched_post_ids = [];

	public function begin() {
		$this->touched_post_ids = [];
	}

	public function track( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id ) {
			$this->touched_post_ids[ $post_id ] = true;
		}
	}

	public function finish( $committed ) {
		if ( ! $committed ) {
			foreach ( array_keys( $this->touched_post_ids ) as $post_id ) {
				clean_post_cache( $post_id );
				wp_cache_delete( $post_id, 'post_meta' );
			}
		}
		$this->touched_post_ids = [];
	}

	public function update_post( array $post ) {
		$this->track( $post['ID'] ?? 0 );
		$result = wp_update_post( $post, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'eit_entry_post_update_failed', __( 'A related WordPress item could not be updated.', 'elementor-implementation-toolkit' ) );
		}
		$this->track( $result );
		return (int) $result;
	}

	public function update_meta( $post_id, $key, $value ) {
		$post_id = absint( $post_id );
		$key = (string) $key;
		$this->track( $post_id );
		$result = update_post_meta( $post_id, $key, $value );
		if ( false !== $result ) {
			return true;
		}
		if ( metadata_exists( 'post', $post_id, $key ) && $this->meta_values_equal( get_post_meta( $post_id, $key, true ), $value, $post_id, $key ) ) {
			return true;
		}
		return new \WP_Error( 'eit_entry_meta_update_failed', __( 'An Entry field could not be stored.', 'elementor-implementation-toolkit' ) );
	}

	public function delete_meta( $post_id, $key ) {
		$post_id = absint( $post_id );
		$key = (string) $key;
		$this->track( $post_id );
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			return true;
		}
		$result = delete_post_meta( $post_id, $key );
		if ( false === $result && metadata_exists( 'post', $post_id, $key ) ) {
			return new \WP_Error( 'eit_entry_meta_delete_failed', __( 'Pending media state could not be cleared.', 'elementor-implementation-toolkit' ) );
		}
		return true;
	}

	private function meta_values_equal( $stored, $expected, $post_id, $key ) {
		$post_type = get_post_type( $post_id );
		$expected = wp_unslash( $expected );
		$expected = sanitize_meta( $key, $expected, 'post', is_string( $post_type ) ? $post_type : '' );
		return $this->normalize_meta_value( $stored ) === $this->normalize_meta_value( $expected );
	}

	private function normalize_meta_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return maybe_serialize( $value );
		}
		if ( null === $value || false === $value ) {
			return '';
		}
		if ( true === $value ) {
			return '1';
		}
		return (string) $value;
	}
}
