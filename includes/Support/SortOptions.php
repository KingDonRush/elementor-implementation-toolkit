<?php
/**
 * Structured sort option helpers for the Filter Controller.
 */

namespace EIT\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SortOptions {

	const MAX_OPTIONS = 24;

	public static function default_lines() {
		return "default|Default\ntitle_asc|Title A-Z\ntitle_desc|Title Z-A\ndate_desc|Newest\nnumeric_asc|Lowest value\nnumeric_desc|Highest value";
	}

	public static function default_widget_items() {
		return [
			[
				'label'     => __( 'Default', 'elementor-implementation-toolkit' ),
				'source'    => 'default',
				'key'       => '',
				'data_type' => 'text',
				'direction' => 'asc',
			],
			[
				'label'     => __( 'Title A-Z', 'elementor-implementation-toolkit' ),
				'source'    => 'title',
				'key'       => '',
				'data_type' => 'text',
				'direction' => 'asc',
			],
			[
				'label'     => __( 'Title Z-A', 'elementor-implementation-toolkit' ),
				'source'    => 'title',
				'key'       => '',
				'data_type' => 'text',
				'direction' => 'desc',
			],
			[
				'label'     => __( 'Newest', 'elementor-implementation-toolkit' ),
				'source'    => 'date',
				'key'       => '',
				'data_type' => 'date',
				'direction' => 'desc',
			],
			[
				'label'     => __( 'Lowest value', 'elementor-implementation-toolkit' ),
				'source'    => 'numeric',
				'key'       => 'sort',
				'data_type' => 'number',
				'direction' => 'asc',
			],
			[
				'label'     => __( 'Highest value', 'elementor-implementation-toolkit' ),
				'source'    => 'numeric',
				'key'       => 'sort',
				'data_type' => 'number',
				'direction' => 'desc',
			],
		];
	}

	public static function source_options() {
		return [
			'default' => __( 'Default listing order', 'elementor-implementation-toolkit' ),
			'title'   => __( 'Post or card title', 'elementor-implementation-toolkit' ),
			'date'    => __( 'Post date', 'elementor-implementation-toolkit' ),
			'numeric' => __( 'Numeric data key', 'elementor-implementation-toolkit' ),
			'rating'  => __( 'Rating data key', 'elementor-implementation-toolkit' ),
			'data'    => __( 'Custom data key', 'elementor-implementation-toolkit' ),
		];
	}

	public static function data_type_options() {
		return [
			'text'   => __( 'Text', 'elementor-implementation-toolkit' ),
			'number' => __( 'Number', 'elementor-implementation-toolkit' ),
			'date'   => __( 'Date', 'elementor-implementation-toolkit' ),
		];
	}

	public static function direction_options() {
		return [
			'asc'  => __( 'Ascending', 'elementor-implementation-toolkit' ),
			'desc' => __( 'Descending', 'elementor-implementation-toolkit' ),
		];
	}

	public static function resolve_lines( $items, $legacy = '' ) {
		if ( is_array( $items ) ) {
			if ( empty( $items ) ) {
				return '';
			}

			$compiled = self::compile_items( $items );

			if ( '' !== $compiled ) {
				$legacy = self::limit_lines( sanitize_textarea_field( (string) $legacy ), self::MAX_OPTIONS );

				if ( self::same_lines( $compiled, self::default_lines() ) && '' !== $legacy && ! self::same_lines( $legacy, self::default_lines() ) ) {
					return $legacy;
				}

				return $compiled;
			}
		}

		$legacy = self::limit_lines( sanitize_textarea_field( (string) $legacy ), self::MAX_OPTIONS );

		return '' !== $legacy ? $legacy : self::default_lines();
	}

	public static function compile_items( $items ) {
		if ( ! is_array( $items ) ) {
			return '';
		}

		$lines = [];

		foreach ( array_slice( $items, 0, self::MAX_OPTIONS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$value = self::value_for_item( $item );
			$label = sanitize_text_field( $item['label'] ?? '' );

			if ( '' === $value || '' === $label ) {
				continue;
			}

			$lines[] = $value . '|' . $label;
		}

		return implode( "\n", $lines );
	}

	public static function lines_to_widget_items( $raw ) {
		$items = [];
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			$value = sanitize_key( $parts[0] ?? '' );
			$label = sanitize_text_field( $parts[1] ?? ( $parts[0] ?? '' ) );
			$item = self::item_from_value( $value, $label );

			if ( $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	public static function item_from_value( $value, $label = '' ) {
		$value = sanitize_key( $value );
		$label = sanitize_text_field( $label );

		if ( 'default' === $value || '' === $value ) {
			return self::widget_item( $label ?: __( 'Default', 'elementor-implementation-toolkit' ), 'default', '', 'text', 'asc' );
		}

		if ( preg_match( '/^(title|date)_(asc|desc)$/', $value, $matches ) ) {
			return self::widget_item( $label ?: $value, $matches[1], '', 'date' === $matches[1] ? 'date' : 'text', $matches[2] );
		}

		if ( preg_match( '/^(numeric|rating)_(asc|desc)$/', $value, $matches ) ) {
			return self::widget_item( $label ?: $value, $matches[1], 'rating' === $matches[1] ? 'rating' : 'sort', 'number', $matches[2] );
		}

		if ( preg_match( '/^data_(.+)_(text|number|date)_(asc|desc)$/', $value, $matches ) ) {
			return self::widget_item( $label ?: $value, 'data', $matches[1], $matches[2], $matches[3] );
		}

		return null;
	}

	public static function value_for_item( array $item ) {
		if ( ! empty( $item['value'] ) && empty( $item['source'] ) ) {
			return sanitize_key( $item['value'] );
		}

		$source = self::allowed_value( $item['source'] ?? 'default', array_keys( self::source_options() ), 'default' );
		$direction = self::allowed_value( $item['direction'] ?? 'asc', [ 'asc', 'desc' ], 'asc' );
		$key = sanitize_key( $item['key'] ?? '' );
		$data_type = self::allowed_value( $item['data_type'] ?? 'text', array_keys( self::data_type_options() ), 'text' );

		if ( 'default' === $source ) {
			return 'default';
		}

		if ( in_array( $source, [ 'title', 'date' ], true ) ) {
			return $source . '_' . $direction;
		}

		if ( 'numeric' === $source ) {
			$key = $key ?: 'sort';
			return 'sort' === $key ? 'numeric_' . $direction : 'data_' . $key . '_number_' . $direction;
		}

		if ( 'rating' === $source ) {
			$key = $key ?: 'rating';
			return 'rating' === $key ? 'rating_' . $direction : 'data_' . $key . '_number_' . $direction;
		}

		if ( 'data' === $source && '' !== $key ) {
			return 'data_' . $key . '_' . $data_type . '_' . $direction;
		}

		return '';
	}

	private static function widget_item( $label, $source, $key, $data_type, $direction ) {
		return [
			'label'     => $label,
			'source'    => $source,
			'key'       => $key,
			'data_type' => $data_type,
			'direction' => $direction,
		];
	}

	private static function same_lines( $left, $right ) {
		return self::normalize_lines( $left ) === self::normalize_lines( $right );
	}

	private static function normalize_lines( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$lines = array_map( 'trim', $lines );
		$lines = array_filter( $lines, 'strlen' );

		return implode( "\n", $lines );
	}

	private static function limit_lines( $raw, $limit ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$lines = array_slice( array_filter( array_map( 'trim', $lines ), 'strlen' ), 0, $limit );

		return implode( "\n", $lines );
	}

	private static function allowed_value( $value, array $allowed, $fallback ) {
		$value = sanitize_key( $value );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
