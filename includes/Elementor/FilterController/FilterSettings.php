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
		$resolved['search_debounce_ms'] = $preset['search_debounce_ms'] ?? ( $settings['search_debounce_ms'] ?? 250 );
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
			'search_debounce_ms'   => $preset['search_debounce_ms'] ?? 250,
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
			$type = FilterTypeRegistry::normalize_type( $filter['type'] ?? 'search' );
			$field_contract = FieldBindingResolver::resolve_filter( $filter );
			$id   = sanitize_key( $filter['_id'] ?? 'filter-' . $index );

			$normalized[] = [
				'id'          => $id,
				'label'       => sanitize_text_field( $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' ) ),
				'type'        => $type,
				'key'         => $field_contract['key'],
				'manualKey'   => $field_contract['manual_key'],
				'fieldBinding' => $field_contract['field_binding'],
				'fieldBindingDynamic' => $field_contract['field_binding_dynamic'],
				'resolvedKey' => $field_contract['resolved_key'],
				'keySource'   => $field_contract['key_source'],
				'source'      => $field_contract['source'],
				'compare'     => self::normalize_compare( $filter['compare'] ?? '' ),
				'dataType'    => self::normalize_data_type( $filter['data_type'] ?? $filter['dataType'] ?? '' ),
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
				'resolved_key' => $filter['resolved_key'] ?? '',
				'key_source'  => $filter['key_source'] ?? '',
				'source'      => $filter['source'] ?? 'visible_text',
				'compare'     => self::preset_compare_for_widget( $filter ),
				'data_type'   => self::preset_data_type_for_widget( $filter ),
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

	private static function normalize_compare( $value ) {
		$value = sanitize_key( $value );
		$allowed = [ 'contains', 'equals', 'in', 'between', 'gte', 'lte', 'exists' ];

		return in_array( $value, $allowed, true ) ? $value : '';
	}

	private static function normalize_data_type( $value ) {
		$value = sanitize_key( $value );
		$allowed = [ 'string', 'number', 'date', 'boolean' ];

		return in_array( $value, $allowed, true ) ? $value : '';
	}

	private static function preset_compare_for_widget( array $filter ) {
		$compare = self::normalize_compare( $filter['compare'] ?? '' );
		$type = FilterTypeRegistry::normalize_type( $filter['type'] ?? 'search' );

		if ( 'contains' === $compare && in_array( $type, [ 'range', 'date', 'rating' ], true ) ) {
			return '';
		}

		return $compare;
	}

	private static function preset_data_type_for_widget( array $filter ) {
		$data_type = self::normalize_data_type( $filter['data_type'] ?? '' );
		$type = FilterTypeRegistry::normalize_type( $filter['type'] ?? 'search' );

		if ( '' === $data_type && in_array( $type, [ 'range', 'rating' ], true ) ) {
			return 'number';
		}

		if ( '' === $data_type && 'date' === $type ) {
			return 'date';
		}

		return $data_type;
	}

	private static function widget_dynamic_settings_for_filter( array $filter ) {
		if ( empty( $filter['field_binding_dynamic'] ) ) {
			return [];
		}

		return [
			'field_binding' => FieldBindingResolver::sanitize_dynamic_binding( $filter['field_binding_dynamic'] ),
		];
	}
}
