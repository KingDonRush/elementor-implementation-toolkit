<?php
/**
 * Bounded line-based serialization for legacy filter preset fields.
 */

namespace EIT\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetLines {

	public static function normalize_options( array $filter ) {
		$options = self::compile_options( $filter['options_items'] ?? [], 120, true );

		if ( '' !== $options ) {
			return $options;
		}

		return self::limit( sanitize_textarea_field( $filter['options'] ?? '' ), 120 );
	}

	public static function limit( $text, $limit ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$lines = array_slice( $lines, 0, max( 1, absint( $limit ) ) );

		return implode( "\n", $lines );
	}

	private static function compile_options( $items, $limit, $include_visual ) {
		$items = is_array( $items ) ? array_slice( $items, 0, max( 1, absint( $limit ) ) ) : [];
		$lines = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$value = sanitize_title( $item['value'] ?? '' );
			$label = sanitize_text_field( $item['label'] ?? '' );
			$visual = sanitize_text_field( $item['visual'] ?? '' );

			if ( '' === $value && '' !== $label ) {
				$value = sanitize_title( $label );
			}
			if ( '' === $value ) {
				continue;
			}

			$line = $value . '|' . ( '' !== $label ? $label : $value );
			if ( $include_visual && '' !== $visual ) {
				$line .= '|' . $visual;
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}
}
