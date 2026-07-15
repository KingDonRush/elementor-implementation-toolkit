<?php
/**
 * Version-keyed runtime artifact cache with transient fallback.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RuntimeCache {

	const GROUP = 'eit_blueprint_runtime';
	const TTL = 43200;

	public function get( $blueprint_id, $version_id ) {
		$key = $this->key( $blueprint_id, $version_id );
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );
		if ( $found && is_array( $value ) ) {
			return $value;
		}
		$value = get_transient( $this->transient_key( $key ) );
		if ( is_array( $value ) ) {
			wp_cache_set( $key, $value, self::GROUP, self::TTL );
			return $value;
		}
		return null;
	}

	public function set( $blueprint_id, $version_id, array $artifacts ) {
		$key = $this->key( $blueprint_id, $version_id );
		$object_result = wp_cache_set( $key, $artifacts, self::GROUP, self::TTL );
		$transient_result = set_transient( $this->transient_key( $key ), $artifacts, self::TTL );
		return $object_result || $transient_result;
	}

	public function invalidate( $blueprint_id, $version_id ) {
		$key = $this->key( $blueprint_id, $version_id );
		wp_cache_delete( $key, self::GROUP );
		delete_transient( $this->transient_key( $key ) );
	}

	private function key( $blueprint_id, $version_id ) {
		return hash( 'sha256', (string) $blueprint_id . '|' . absint( $version_id ) );
	}

	private function transient_key( $key ) {
		return 'eit_bp_' . substr( $key, 0, 32 );
	}
}
