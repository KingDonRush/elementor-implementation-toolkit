<?php
/**
 * Runtime settings resolver for the Elementor Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

use EIT\Support\FilterPresets;
use EIT\Support\SortOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterSettings {

	public static function resolve_preset_settings( array $settings ) {
		if ( 'preset' !== ( $settings['configuration_source'] ?? 'widget' ) ) {
			$settings['preset_resolution_state'] = 'widget';
			return $settings;
		}

		if ( empty( $settings['filter_preset'] ) ) {
			$settings['preset_resolution_state'] = 'unselected';
			return $settings;
		}

		$preset = FilterPresets::get( $settings['filter_preset'] );

		if ( ! $preset ) {
			$settings['preset_resolution_state'] = 'missing';
			$settings['preset_missing'] = true;
			return $settings;
		}

		$resolved = $settings;
		$resolved['preset_resolution_state'] = 'linked';
		$resolved['resolved_filter_preset'] = $settings['filter_preset'];
		$resolved['resolved_filter_preset_name'] = $preset['name'] ?? $settings['filter_preset'];
		$resolved['filters']           = self::map_preset_filters_to_widget_filters( $preset['filters'] ?? [] );
		$resolved['target_selector']   = ! empty( $settings['target_selector'] ) ? $settings['target_selector'] : ( $preset['target_selector'] ?? '' );
		$resolved['item_selector']     = ! empty( $settings['item_selector'] ) ? $settings['item_selector'] : ( $preset['item_selector'] ?? '' );
		$resolved['auto_apply']        = 'auto' === ( $preset['apply_mode'] ?? 'auto' ) ? 'yes' : '';
		$resolved['show_apply']        = 'button' === ( $preset['apply_mode'] ?? 'auto' ) ? 'yes' : '';
		$resolved['sync_url']          = ! empty( $preset['sync_url'] ) ? 'yes' : '';
		$resolved['per_page']          = $preset['per_page'] ?? ( $settings['per_page'] ?? 9 );
		$resolved['show_result_count'] = ! empty( $preset['show_result_count'] ) ? 'yes' : '';
		$resolved['result_count_text'] = $preset['result_count_text'] ?? ( $settings['result_count_text'] ?? '' );
		$resolved['show_active_chips'] = ! empty( $preset['show_active_chips'] ) ? 'yes' : '';
		$resolved['show_sort']         = ! empty( $preset['show_sort'] ) ? 'yes' : '';
		$resolved['sort_label']        = $preset['sort_label'] ?? ( $settings['sort_label'] ?? '' );
		$resolved['sort_options']      = $preset['sort_options'] ?? ( $settings['sort_options'] ?? '' );
		$resolved['sort_options_items'] = SortOptions::lines_to_widget_items( $preset['sort_options'] ?? '' );
		$resolved['apply_text']        = $preset['apply_text'] ?? ( $settings['apply_text'] ?? '' );
		$resolved['reset_text']        = $preset['reset_text'] ?? ( $settings['reset_text'] ?? '' );
		$resolved['empty_text']        = $preset['empty_text'] ?? ( $settings['empty_text'] ?? '' );
		$resolved['pagination_type']   = $preset['pagination_type'] ?? ( $settings['pagination_type'] ?? 'numbers' );
		$resolved['previous_text']     = $preset['previous_text'] ?? ( $settings['previous_text'] ?? '' );
		$resolved['next_text']         = $preset['next_text'] ?? ( $settings['next_text'] ?? '' );

		return $resolved;
	}

	public static function preset_to_widget_settings( array $preset ) {
		return [
			'configuration_source' => 'widget',
			'filter_preset'        => '',
			'filters'              => self::map_preset_filters_to_widget_filters( $preset['filters'] ?? [] ),
			'target_selector'      => $preset['target_selector'] ?? '',
			'item_selector'        => $preset['item_selector'] ?? '',
			'auto_apply'           => 'auto' === ( $preset['apply_mode'] ?? 'auto' ) ? 'yes' : '',
			'show_apply'           => 'button' === ( $preset['apply_mode'] ?? 'auto' ) ? 'yes' : '',
			'sync_url'             => ! empty( $preset['sync_url'] ) ? 'yes' : '',
			'per_page'             => $preset['per_page'] ?? 9,
			'show_result_count'    => ! empty( $preset['show_result_count'] ) ? 'yes' : '',
			'result_count_text'    => $preset['result_count_text'] ?? '',
			'show_active_chips'    => ! empty( $preset['show_active_chips'] ) ? 'yes' : '',
			'show_sort'            => ! empty( $preset['show_sort'] ) ? 'yes' : '',
			'sort_label'           => $preset['sort_label'] ?? '',
			'sort_options'         => $preset['sort_options'] ?? '',
			'sort_options_items'   => SortOptions::lines_to_widget_items( $preset['sort_options'] ?? '' ),
			'apply_text'           => $preset['apply_text'] ?? '',
			'reset_text'           => $preset['reset_text'] ?? '',
			'empty_text'           => $preset['empty_text'] ?? '',
			'pagination_type'      => $preset['pagination_type'] ?? 'numbers',
			'previous_text'        => $preset['previous_text'] ?? '',
			'next_text'            => $preset['next_text'] ?? '',
		];
	}

	public static function normalize_filters( array $filters ) {
		$normalized = [];

		foreach ( $filters as $index => $filter ) {
			$type = sanitize_key( $filter['type'] ?? 'search' );
			$manual_key = sanitize_key( $filter['key'] ?? '' );
			$field_binding = self::normalize_text_binding( $filter['field_binding'] ?? '' );
			$field_binding_dynamic = self::dynamic_binding_from_filter( $filter );
			$key = self::resolve_effective_key( $field_binding, $field_binding_dynamic, $manual_key );
			$id   = sanitize_key( $filter['_id'] ?? 'filter-' . $index );

			if ( ! in_array( $type, FilterTypes::keys(), true ) ) {
				$type = 'search';
			}

			$normalized[] = [
				'id'          => $id,
				'label'       => sanitize_text_field( $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' ) ),
				'type'        => $type,
				'key'         => $key,
				'manualKey'   => $manual_key,
				'fieldBinding' => $field_binding,
				'fieldBindingDynamic' => $field_binding_dynamic,
				'placeholder' => sanitize_text_field( $filter['placeholder'] ?? '' ),
				'options'     => FilterOptions::parse( $filter['options'] ?? '' ),
				'rangeMin'    => is_numeric( $filter['range_min'] ?? null ) ? (float) $filter['range_min'] : 0,
				'rangeMax'    => is_numeric( $filter['range_max'] ?? null ) ? (float) $filter['range_max'] : 100,
				'rangeStep'   => is_numeric( $filter['range_step'] ?? null ) ? (float) $filter['range_step'] : 1,
				'layoutWidth' => self::normalize_layout_width( $filter['layout_width'] ?? 100 ),
				'showLabel'   => ( $filter['show_label'] ?? 'yes' ) === 'yes',
			];
		}

		return $normalized;
	}

	public static function map_preset_filters_to_widget_filters( array $filters ) {
		$mapped = [];

		foreach ( $filters as $index => $filter ) {
			if ( empty( $filter['enabled'] ) ) {
				continue;
			}

			$mapped[] = [
				'_id'         => sanitize_key( $filter['key'] ?? 'preset-filter-' . $index ) ?: 'preset-filter-' . $index,
				'label'       => $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' ),
				'type'        => $filter['type'] ?? 'search',
				'field_binding' => $filter['field_binding'] ?? '',
				'field_binding_dynamic' => $filter['field_binding_dynamic'] ?? '',
				'__dynamic__'  => self::widget_dynamic_settings_for_filter( $filter ),
				'key'         => $filter['key'] ?? '',
				'placeholder' => $filter['placeholder'] ?? '',
				'options'     => $filter['options'] ?? '',
				'range_min'   => $filter['range_min'] ?? 0,
				'range_max'   => $filter['range_max'] ?? 100,
				'range_step'  => $filter['range_step'] ?? 1,
				'layout_width' => self::normalize_layout_width( $filter['layout_width'] ?? 100 ),
				'show_label'  => ! empty( $filter['show_label'] ) ? 'yes' : '',
			];
		}

		return $mapped;
	}

	private static function normalize_layout_width( $value ) {
		return max( 10, min( 100, absint( $value ) ?: 100 ) );
	}

	private static function normalize_text_binding( $value ) {
		return sanitize_text_field( (string) $value );
	}

	private static function dynamic_binding_from_filter( array $filter ) {
		$binding = $filter['field_binding_dynamic'] ?? '';

		if ( '' === trim( (string) $binding ) && ! empty( $filter['__dynamic__']['field_binding'] ) ) {
			$binding = $filter['__dynamic__']['field_binding'];
		}

		return self::sanitize_dynamic_binding( $binding );
	}

	private static function resolve_effective_key( $field_binding, $field_binding_dynamic, $manual_key ) {
		$field_binding_key = self::field_key_from_value( $field_binding );

		if ( '' !== $field_binding_key ) {
			return $field_binding_key;
		}

		$field_binding_key = self::field_key_from_dynamic_binding( $field_binding_dynamic );

		if ( '' !== $field_binding_key ) {
			return $field_binding_key;
		}

		return $manual_key;
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

	private static function widget_dynamic_settings_for_filter( array $filter ) {
		if ( empty( $filter['field_binding_dynamic'] ) ) {
			return [];
		}

		return [
			'field_binding' => self::sanitize_dynamic_binding( $filter['field_binding_dynamic'] ),
		];
	}

	private static function sanitize_dynamic_binding( $value ) {
		$value = wp_check_invalid_utf8( (string) $value );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value );

		return substr( trim( $value ), 0, 2000 );
	}
}
