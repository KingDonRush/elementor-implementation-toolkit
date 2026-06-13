<?php
/**
 * Stack-based current CCT item context for nested Elementor rendering.
 */

namespace EIT\CCT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CurrentItemContext {

	private static $stack = [];

	public static function push( $type, array $item ) {
		self::$stack[] = [
			'type' => DefinitionManager::sanitize_slug( $type ),
			'item' => $item,
		];
	}

	public static function pop() {
		return array_pop( self::$stack );
	}

	public static function current() {
		if ( empty( self::$stack ) ) {
			return null;
		}

		return self::$stack[ count( self::$stack ) - 1 ];
	}

	public static function item() {
		$current = self::current();
		return $current['item'] ?? null;
	}

	public static function type() {
		$current = self::current();
		return $current['type'] ?? '';
	}

	public static function clear() {
		self::$stack = [];
	}
}
