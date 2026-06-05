<?php
/**
 * Field binding and effective key resolver for Filter Controller filters.
 */

namespace EIT\Elementor\FilterController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FieldBindingResolver {

	const SOURCE_FIELD_BINDING = 'field_binding';
	const SOURCE_DYNAMIC_BINDING = 'dynamic_binding';
	const SOURCE_RESOLVED_SNAPSHOT = 'resolved_snapshot';
	const SOURCE_MANUAL_KEY = 'manual_key';
	const SOURCE_EMPTY = 'empty';

	public static function resolve_filter( array $filter ) {
		$field_binding = self::normalize_text_binding( $filter['field_binding'] ?? '' );
		$field_binding_dynamic = self::dynamic_binding_from_filter( $filter );
		$manual_key = self::field_key_from_value( $filter['key'] ?? '' );
		$snapshot_key = '' !== $field_binding || '' !== $field_binding_dynamic ? self::field_key_from_value( $filter['resolved_key'] ?? '' ) : '';
		$source = self::normalize_source( $filter['source'] ?? 'visible_text' );
		$key = self::field_key_from_value( $field_binding );
		$key_source = self::SOURCE_FIELD_BINDING;

		if ( '' === $key ) {
			$key = self::field_key_from_dynamic_binding( $field_binding_dynamic );
			$key_source = self::SOURCE_DYNAMIC_BINDING;
		}

		if ( '' === $key ) {
			$key = $snapshot_key;
			$key_source = self::SOURCE_RESOLVED_SNAPSHOT;
		}

		if ( '' === $key ) {
			$key = $manual_key;
			$key_source = self::SOURCE_MANUAL_KEY;
		}

		if ( '' === $key ) {
			$key_source = self::SOURCE_EMPTY;
		}

		return [
			'key'                   => $key,
			'resolved_key'          => $key,
			'key_source'            => $key_source,
			'manual_key'            => $manual_key,
			'field_binding'         => $field_binding,
			'field_binding_dynamic' => $field_binding_dynamic,
			'source'                => $source,
		];
	}

	public static function normalize_text_binding( $value ) {
		return sanitize_text_field( (string) $value );
	}

	public static function dynamic_binding_from_filter( array $filter ) {
		$binding = $filter['field_binding_dynamic'] ?? '';

		if ( '' === trim( (string) $binding ) && ! empty( $filter['__dynamic__']['field_binding'] ) ) {
			$binding = $filter['__dynamic__']['field_binding'];
		}

		return self::sanitize_dynamic_binding( $binding );
	}

	public static function sanitize_dynamic_binding( $value ) {
		$value = wp_check_invalid_utf8( (string) $value );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value );

		return substr( trim( $value ), 0, 2000 );
	}

	public static function normalize_source( $value ) {
		$value = sanitize_key( $value );
		$allowed = [ 'visible_text', 'data_attr', 'taxonomy', 'meta', 'post_field' ];

		return in_array( $value, $allowed, true ) ? $value : 'visible_text';
	}

	public static function normalize_key_source( $value ) {
		$value = sanitize_key( $value );
		$allowed = [
			self::SOURCE_FIELD_BINDING,
			self::SOURCE_DYNAMIC_BINDING,
			self::SOURCE_RESOLVED_SNAPSHOT,
			self::SOURCE_MANUAL_KEY,
			self::SOURCE_EMPTY,
		];

		return in_array( $value, $allowed, true ) ? $value : self::SOURCE_EMPTY;
	}

	private static function field_key_from_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$value = trim( wp_strip_all_tags( (string) $value ) );

		if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $value ) ) {
			return sanitize_key( $value );
		}

		return '';
	}

	private static function field_key_from_dynamic_binding( $binding ) {
		$binding = trim( (string) $binding );

		if ( '' === $binding || ! class_exists( '\Elementor\Plugin' ) ) {
			return '';
		}

		$elementor = \Elementor\Plugin::$instance ?? null;
		$dynamic_tags = $elementor ? $elementor->dynamic_tags : null;

		if ( ! $dynamic_tags || ! method_exists( $dynamic_tags, 'tag_text_to_tag_data' ) ) {
			return '';
		}

		$tag_data = $dynamic_tags->tag_text_to_tag_data( $binding );

		if ( empty( $tag_data['settings'] ) || ! is_array( $tag_data['settings'] ) ) {
			return '';
		}

		return self::field_key_from_dynamic_settings( $tag_data['settings'] );
	}

	private static function field_key_from_dynamic_settings( array $settings ) {
		$preferred_keys = [ 'key', 'meta_key', 'field_key', 'field_name', 'field', 'custom_field', 'taxonomy' ];

		foreach ( $preferred_keys as $preferred_key ) {
			if ( isset( $settings[ $preferred_key ] ) ) {
				$field_key = self::field_key_from_value( self::unwrap_dynamic_setting_value( $settings[ $preferred_key ] ) );

				if ( '' !== $field_key ) {
					return $field_key;
				}
			}
		}

		foreach ( $settings as $value ) {
			if ( is_array( $value ) ) {
				$field_key = self::field_key_from_dynamic_settings( $value );

				if ( '' !== $field_key ) {
					return $field_key;
				}
			}
		}

		return '';
	}

	private static function unwrap_dynamic_setting_value( $value ) {
		if ( is_array( $value ) && isset( $value['id'] ) ) {
			return $value['id'];
		}

		if ( is_array( $value ) && isset( $value['value'] ) ) {
			return $value['value'];
		}

		return $value;
	}
}
