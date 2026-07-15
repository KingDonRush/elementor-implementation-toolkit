<?php
/**
 * Strict JSON boundary for persisted Blueprint records.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JsonCodec {

	public static function encode( $value ) {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded
			? new \WP_Error( 'eit_json_encode_failed', __( 'Toolkit data could not be encoded safely.', 'elementor-implementation-toolkit' ) )
			: $encoded;
	}

	public static function decode( $value, $default = [] ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $default;
		}
		$decoded = json_decode( $value, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : $default;
	}
}
