<?php
/**
 * Captures and verifies the stable runtime identity of code-registered extensions.
 */

namespace EIT\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExtensionContract {

	public static function snapshot( $extension ) {
		try {
			$id = strtolower( trim( (string) $extension->get_id() ) );
			$version = trim( (string) $extension->get_version() );
			$capabilities = self::capabilities( $extension->get_capabilities() );
			$health = $extension->health_check();
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'eit_extension_unavailable', __( 'Registered extension failed its runtime canary.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! is_array( $health ) || empty( $health['ok'] ) ) {
			return new \WP_Error( 'eit_extension_unavailable', __( 'Registered extension is unavailable in this environment.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! preg_match( '/^[a-z][a-z0-9_.-]{1,63}$/', $id ) || ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) || null === $capabilities ) {
			return new \WP_Error( 'eit_extension_metadata_invalid', __( 'Registered extension metadata is invalid.', 'elementor-implementation-toolkit' ) );
		}
		return [ 'id' => $id, 'version' => $version, 'capabilities' => $capabilities ];
	}

	public static function verify( $extension, array $expected ) {
		$current = self::snapshot( $extension );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$expected_capabilities = self::capabilities( $expected['capabilities'] ?? null );
		if (
			null === $expected_capabilities
			|| ! hash_equals( (string) ( $expected['id'] ?? '' ), $current['id'] )
			|| ! hash_equals( (string) ( $expected['version'] ?? '' ), $current['version'] )
			|| $expected_capabilities !== $current['capabilities']
		) {
			return new \WP_Error( 'eit_extension_contract_mismatch', __( 'Registered extension differs from the published contract.', 'elementor-implementation-toolkit' ) );
		}
		return $current;
	}

	public static function capabilities( $capabilities ) {
		if ( ! is_array( $capabilities ) || ! array_is_list( $capabilities ) || count( $capabilities ) > 64 ) {
			return null;
		}
		$result = [];
		foreach ( $capabilities as $capability ) {
			if ( ! is_string( $capability ) || ! preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $capability ) || isset( $result[ $capability ] ) ) {
				return null;
			}
			$result[ $capability ] = true;
		}
		$result = array_keys( $result );
		sort( $result, SORT_STRING );
		return $result;
	}
}
