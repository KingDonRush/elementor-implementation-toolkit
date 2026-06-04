<?php
/**
 * Runtime settings resolver for the Elementor Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterSettings {

	public static function resolve_preset_settings( array $settings ) {
		if ( 'preset' !== ( $settings['configuration_source'] ?? 'widget' ) || empty( $settings['filter_preset'] ) ) {
			return $settings;
		}

		$preset = FilterPresets::get( $settings['filter_preset'] );

		if ( ! $preset ) {
			return $settings;
		}

		$resolved = $settings;
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
		$resolved['apply_text']        = $preset['apply_text'] ?? ( $settings['apply_text'] ?? '' );
		$resolved['reset_text']        = $preset['reset_text'] ?? ( $settings['reset_text'] ?? '' );
		$resolved['empty_text']        = $preset['empty_text'] ?? ( $settings['empty_text'] ?? '' );
		$resolved['pagination_type']   = $preset['pagination_type'] ?? ( $settings['pagination_type'] ?? 'numbers' );
		$resolved['previous_text']     = $preset['previous_text'] ?? ( $settings['previous_text'] ?? '' );
		$resolved['next_text']         = $preset['next_text'] ?? ( $settings['next_text'] ?? '' );

		return $resolved;
	}

	public static function normalize_filters( array $filters ) {
		$normalized = [];

		foreach ( $filters as $index => $filter ) {
			$type = sanitize_key( $filter['type'] ?? 'search' );
			$key  = sanitize_key( $filter['key'] ?? '' );
			$id   = sanitize_key( $filter['_id'] ?? 'filter-' . $index );

			if ( ! in_array( $type, FilterTypes::keys(), true ) ) {
				$type = 'search';
			}

			$normalized[] = [
				'id'          => $id,
				'label'       => sanitize_text_field( $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' ) ),
				'type'        => $type,
				'key'         => $key,
				'placeholder' => sanitize_text_field( $filter['placeholder'] ?? '' ),
				'options'     => FilterOptions::parse( $filter['options'] ?? '' ),
				'rangeMin'    => is_numeric( $filter['range_min'] ?? null ) ? (float) $filter['range_min'] : 0,
				'rangeMax'    => is_numeric( $filter['range_max'] ?? null ) ? (float) $filter['range_max'] : 100,
				'rangeStep'   => is_numeric( $filter['range_step'] ?? null ) ? (float) $filter['range_step'] : 1,
				'showLabel'   => ( $filter['show_label'] ?? 'yes' ) === 'yes',
			];
		}

		return $normalized;
	}

	private static function map_preset_filters_to_widget_filters( array $filters ) {
		$mapped = [];

		foreach ( $filters as $index => $filter ) {
			if ( empty( $filter['enabled'] ) ) {
				continue;
			}

			$mapped[] = [
				'_id'         => sanitize_key( $filter['key'] ?? 'preset-filter-' . $index ) ?: 'preset-filter-' . $index,
				'label'       => $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' ),
				'type'        => $filter['type'] ?? 'search',
				'key'         => $filter['key'] ?? '',
				'placeholder' => $filter['placeholder'] ?? '',
				'options'     => $filter['options'] ?? '',
				'range_min'   => $filter['range_min'] ?? 0,
				'range_max'   => $filter['range_max'] ?? 100,
				'range_step'  => $filter['range_step'] ?? 1,
				'show_label'  => ! empty( $filter['show_label'] ) ? 'yes' : '',
			];
		}

		return $mapped;
	}
}
