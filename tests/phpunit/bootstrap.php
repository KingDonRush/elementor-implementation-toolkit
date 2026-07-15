<?php
/**
 * Minimal bootstrap for pure unit contracts. WordPress integration runs separately.
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
