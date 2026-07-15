<?php
/**
 * UUID validation and deterministic legacy identity helpers.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Uuid {

	const LEGACY_NAMESPACE = '496de1ac-6102-4b79-80be-fb722d57c722';

	public static function is_valid( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $value );
	}

	public static function v4() {
		$bytes = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return self::format( bin2hex( $bytes ) );
	}

	public static function v5( $namespace, $name ) {
		if ( ! self::is_valid( $namespace ) ) {
			throw new \InvalidArgumentException( 'UUID namespace is invalid.' );
		}

		$hex = str_replace( '-', '', strtolower( $namespace ) );
		$hash = sha1( hex2bin( $hex ) . (string) $name );
		$hash[12] = '5';
		$variant = hexdec( $hash[16] ) & 0x3 | 0x8;
		$hash[16] = dechex( $variant );
		return self::format( substr( $hash, 0, 32 ) );
	}

	private static function format( $hex ) {
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
