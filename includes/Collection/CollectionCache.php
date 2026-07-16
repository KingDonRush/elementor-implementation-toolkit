<?php
/**
 * Version, entity-generation and audience-scoped Collection response cache.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionCache {

	const GROUP = 'eit_collections';

	public function get( array $contract, array $request ) {
		if ( ! $this->enabled( $contract ) ) {
			return null;
		}
		$key = $this->key( $contract, $request );
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );
		if ( $found && is_array( $value ) ) {
			return $value;
		}
		$value = get_transient( $this->transient_key( $key ) );
		if ( is_array( $value ) ) {
			wp_cache_set( $key, $value, self::GROUP, $this->ttl( $contract ) );
			return $value;
		}
		return null;
	}

	public function set( array $contract, array $request, array $response ) {
		if ( ! $this->enabled( $contract ) ) {
			return false;
		}
		$key = $this->key( $contract, $request );
		$ttl = $this->ttl( $contract );
		$object = wp_cache_set( $key, $response, self::GROUP, $ttl );
		$transient = set_transient( $this->transient_key( $key ), $response, $ttl );
		return $object || $transient;
	}

	public function bump( $entity_id ) {
		$option = $this->generation_option( $entity_id );
		$generation = max( 1, absint( get_option( $option, 1 ) ) + 1 );
		update_option( $option, $generation, false );
		return $generation;
	}

	public function generation( $entity_id ) {
		return max( 1, absint( get_option( $this->generation_option( $entity_id ), 1 ) ) );
	}

	private function enabled( array $contract ) {
		return ! empty( $contract['cache']['enabled'] ) && 'legacy_dom' !== ( $contract['provider']['id'] ?? '' );
	}

	private function key( array $contract, array $request ) {
		$scope = 'public' === ( $contract['access'] ?? '' ) ? 'public' : 'user:' . get_current_user_id();
		$identity = [
			'collection' => $contract['collection_id'],
			'version' => $contract['version_id'],
			'checksum' => $contract['artifact_checksum'],
			'generation' => $this->generation( $contract['entity_id'] ),
			'scope' => $scope,
			'request' => $request,
		];
		return hash( 'sha256', wp_json_encode( $identity ) );
	}

	private function ttl( array $contract ) {
		return min( 3600, max( 30, absint( $contract['cache']['ttl_seconds'] ?? 300 ) ) );
	}

	private function generation_option( $entity_id ) {
		return 'eit_col_gen_' . substr( hash( 'sha256', (string) $entity_id ), 0, 24 );
	}

	private function transient_key( $key ) {
		return 'eit_col_' . substr( $key, 0, 32 );
	}
}
