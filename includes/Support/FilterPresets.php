<?php
/**
 * Stored filter preset definitions.
 */

namespace EIT\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresets {

	const OPTION = 'eit_filter_presets';
	const MAX_FILTERS = 40;
	const MAX_SORT_OPTIONS = 24;

	public static function all() {
		$presets = get_option( self::OPTION, [] );

		return is_array( $presets ) ? $presets : [];
	}

	public static function get( $id ) {
		$id = sanitize_key( $id );
		$presets = self::all();

		return $presets[ $id ] ?? null;
	}

	public static function options() {
		$options = [
			'' => __( 'Use widget controls', 'elementor-implementation-toolkit' ),
		];

		foreach ( self::all() as $id => $preset ) {
			$options[ $id ] = $preset['name'] ?? $id;
		}

		return $options;
	}

	public static function blank() {
		return [
			'id'                  => '',
			'name'                => '',
			'slug'                => '',
			'description'         => '',
			'provider_mode'       => 'dom',
			'target_selector'     => '',
			'item_selector'       => '',
			'apply_mode'          => 'auto',
			'sync_url'            => true,
			'per_page'            => 9,
			'show_result_count'   => true,
			'result_count_text'   => __( '{count} results', 'elementor-implementation-toolkit' ),
			'show_active_chips'   => true,
			'show_sort'           => true,
			'sort_label'          => __( 'Sort by', 'elementor-implementation-toolkit' ),
			'sort_options'        => "default|Default\ntitle_asc|Title A-Z\ntitle_desc|Title Z-A\ndate_desc|Newest",
			'apply_text'          => __( 'Apply filters', 'elementor-implementation-toolkit' ),
			'reset_text'          => __( 'Reset', 'elementor-implementation-toolkit' ),
			'empty_text'          => __( 'No matching items found.', 'elementor-implementation-toolkit' ),
			'pagination_type'     => 'numbers',
			'previous_text'       => __( 'Previous', 'elementor-implementation-toolkit' ),
			'next_text'           => __( 'Next', 'elementor-implementation-toolkit' ),
			'filters'             => [
				self::blank_filter(
					[
						'label'       => __( 'Search', 'elementor-implementation-toolkit' ),
						'type'        => 'search',
						'key'         => 'title',
						'query_var'   => 'search',
						'placeholder' => __( 'Search...', 'elementor-implementation-toolkit' ),
					]
				),
			],
		];
	}

	public static function blank_filter( array $overrides = [] ) {
		return array_merge(
			[
				'enabled'        => true,
				'label'          => __( 'Filter', 'elementor-implementation-toolkit' ),
				'type'           => 'search',
				'key'            => '',
				'source'         => 'visible_text',
				'query_var'      => '',
				'compare'        => 'contains',
				'data_type'      => 'string',
				'placeholder'    => '',
				'options'        => '',
				'range_min'      => 0,
				'range_max'      => 100,
				'range_step'     => 1,
				'default_value'  => '',
				'empty_behavior' => 'ignore',
				'show_count'     => false,
				'show_label'     => true,
			],
			$overrides
		);
	}

	public static function save( array $raw ) {
		$presets = self::all();
		$had_id  = ! empty( $raw['id'] );
		$id      = $had_id ? sanitize_key( $raw['id'] ) : '';
		$name    = sanitize_text_field( $raw['name'] ?? '' );
		$slug    = sanitize_title( $raw['slug'] ?? '' );

		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		if ( '' === $id ) {
			$id = sanitize_key( $slug ?: 'filter-preset' );
			$id = self::unique_id( $id, $presets );
		}

		$existing = isset( $presets[ $id ] ) && is_array( $presets[ $id ] ) ? $presets[ $id ] : [];

		if ( empty( $raw['created_from'] ) && ! empty( $existing['created_from'] ) ) {
			$raw['created_from'] = $existing['created_from'];
		}

		if ( empty( $raw['created_at'] ) && ! empty( $existing['created_at'] ) ) {
			$raw['created_at'] = $existing['created_at'];
		}

		if ( empty( $raw['created_from'] ) ) {
			$raw['created_from'] = [
				'source'    => 'admin',
				'saved_via' => 'admin_form',
			];
		}

		if ( empty( $raw['created_at'] ) ) {
			$raw['created_at'] = current_time( 'mysql' );
		}

		$preset = self::sanitize_preset( $raw, $id );
		$presets[ $id ] = $preset;

		update_option( self::OPTION, $presets, false );

		return $id;
	}

	public static function delete( $id ) {
		$id = sanitize_key( $id );
		$presets = self::all();

		if ( isset( $presets[ $id ] ) ) {
			unset( $presets[ $id ] );
			update_option( self::OPTION, $presets, false );
		}
	}

	public static function filter_types() {
		return [
			'search'   => __( 'Search', 'elementor-implementation-toolkit' ),
			'checkbox' => __( 'Checkboxes', 'elementor-implementation-toolkit' ),
			'radio'    => __( 'Radio', 'elementor-implementation-toolkit' ),
			'select'   => __( 'Select', 'elementor-implementation-toolkit' ),
			'chips'    => __( 'Chips', 'elementor-implementation-toolkit' ),
			'toggle'   => __( 'Toggle', 'elementor-implementation-toolkit' ),
			'range'    => __( 'Range', 'elementor-implementation-toolkit' ),
			'date'     => __( 'Date Range', 'elementor-implementation-toolkit' ),
			'swatch'   => __( 'Swatches', 'elementor-implementation-toolkit' ),
			'rating'   => __( 'Rating', 'elementor-implementation-toolkit' ),
		];
	}

	public static function source_types() {
		return [
			'visible_text' => __( 'Visible text / DOM fallback', 'elementor-implementation-toolkit' ),
			'data_attr'    => __( 'data-* attribute', 'elementor-implementation-toolkit' ),
			'taxonomy'     => __( 'Taxonomy terms', 'elementor-implementation-toolkit' ),
			'meta'         => __( 'Post meta', 'elementor-implementation-toolkit' ),
			'post_field'   => __( 'Post field', 'elementor-implementation-toolkit' ),
		];
	}

	public static function compare_types() {
		return [
			'contains' => __( 'Contains', 'elementor-implementation-toolkit' ),
			'equals'   => __( 'Equals', 'elementor-implementation-toolkit' ),
			'in'       => __( 'In list', 'elementor-implementation-toolkit' ),
			'between'  => __( 'Between', 'elementor-implementation-toolkit' ),
			'gte'      => __( 'Greater or equal', 'elementor-implementation-toolkit' ),
			'lte'      => __( 'Lower or equal', 'elementor-implementation-toolkit' ),
			'exists'   => __( 'Exists', 'elementor-implementation-toolkit' ),
		];
	}

	public static function data_types() {
		return [
			'string'  => __( 'String', 'elementor-implementation-toolkit' ),
			'number'  => __( 'Number', 'elementor-implementation-toolkit' ),
			'date'    => __( 'Date', 'elementor-implementation-toolkit' ),
			'boolean' => __( 'Boolean', 'elementor-implementation-toolkit' ),
		];
	}

	public static function provider_modes() {
		return [
			'dom'          => __( 'Read visible listing HTML', 'elementor-implementation-toolkit' ),
			'wp_post_link' => __( 'Read listing HTML and enrich from WordPress posts', 'elementor-implementation-toolkit' ),
			'adapter'      => __( 'Custom adapter', 'elementor-implementation-toolkit' ),
		];
	}

	public static function apply_modes() {
		return [
			'auto'   => __( 'Auto apply on change', 'elementor-implementation-toolkit' ),
			'button' => __( 'Apply button', 'elementor-implementation-toolkit' ),
		];
	}

	public static function pagination_types() {
		return [
			'numbers'        => __( 'Numbers', 'elementor-implementation-toolkit' ),
			'prev_next'      => __( 'Previous / Next', 'elementor-implementation-toolkit' ),
			'numbers_arrows' => __( 'Numbers + Arrows', 'elementor-implementation-toolkit' ),
			'none'           => __( 'None', 'elementor-implementation-toolkit' ),
		];
	}

	private static function sanitize_preset( array $raw, $id ) {
		$provider_modes   = array_keys( self::provider_modes() );
		$apply_modes      = array_keys( self::apply_modes() );
		$pagination_types = array_keys( self::pagination_types() );
		$sort_options     = self::compile_options_lines( $raw['sort_options_items'] ?? [], self::MAX_SORT_OPTIONS, false );

		if ( '' === $sort_options ) {
			$sort_options = self::limit_lines( sanitize_textarea_field( $raw['sort_options'] ?? '' ), self::MAX_SORT_OPTIONS );
		}

		return [
			'id'                => sanitize_key( $id ),
			'name'              => sanitize_text_field( $raw['name'] ?? __( 'Untitled preset', 'elementor-implementation-toolkit' ) ),
			'slug'              => sanitize_title( $raw['slug'] ?? '' ) ?: sanitize_title( $id ),
			'description'       => sanitize_textarea_field( $raw['description'] ?? '' ),
			'provider_mode'     => self::allowed_value( $raw['provider_mode'] ?? 'dom', $provider_modes, 'dom' ),
			'target_selector'   => sanitize_text_field( $raw['target_selector'] ?? '' ),
			'item_selector'     => sanitize_text_field( $raw['item_selector'] ?? '' ),
			'apply_mode'        => self::allowed_value( $raw['apply_mode'] ?? 'auto', $apply_modes, 'auto' ),
			'sync_url'          => self::truthy( $raw['sync_url'] ?? false ),
			'per_page'          => max( 1, min( 96, absint( $raw['per_page'] ?? 9 ) ) ),
			'show_result_count' => self::truthy( $raw['show_result_count'] ?? false ),
			'result_count_text' => sanitize_text_field( $raw['result_count_text'] ?? __( '{count} results', 'elementor-implementation-toolkit' ) ),
			'show_active_chips' => self::truthy( $raw['show_active_chips'] ?? false ),
			'show_sort'         => self::truthy( $raw['show_sort'] ?? false ),
			'sort_label'        => sanitize_text_field( $raw['sort_label'] ?? __( 'Sort by', 'elementor-implementation-toolkit' ) ),
			'sort_options'      => $sort_options,
			'apply_text'        => sanitize_text_field( $raw['apply_text'] ?? __( 'Apply filters', 'elementor-implementation-toolkit' ) ),
			'reset_text'        => sanitize_text_field( $raw['reset_text'] ?? __( 'Reset', 'elementor-implementation-toolkit' ) ),
			'empty_text'        => sanitize_text_field( $raw['empty_text'] ?? __( 'No matching items found.', 'elementor-implementation-toolkit' ) ),
			'pagination_type'   => self::allowed_value( $raw['pagination_type'] ?? 'numbers', $pagination_types, 'numbers' ),
			'previous_text'     => sanitize_text_field( $raw['previous_text'] ?? __( 'Previous', 'elementor-implementation-toolkit' ) ),
			'next_text'         => sanitize_text_field( $raw['next_text'] ?? __( 'Next', 'elementor-implementation-toolkit' ) ),
			'filters'           => self::sanitize_filters( $raw['filters'] ?? [] ),
			'created_from'      => self::sanitize_created_from( $raw['created_from'] ?? [] ),
			'created_at'        => sanitize_text_field( $raw['created_at'] ?? current_time( 'mysql' ) ),
			'updated_at'        => current_time( 'mysql' ),
		];
	}

	private static function sanitize_filters( $filters ) {
		$filters = is_array( $filters ) ? array_slice( $filters, 0, self::MAX_FILTERS ) : [];
		$normalized = [];
		$types = array_keys( self::filter_types() );
		$sources = array_keys( self::source_types() );
		$compares = array_keys( self::compare_types() );
		$data_types = array_keys( self::data_types() );

		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$type = self::allowed_value( $filter['type'] ?? 'search', $types, 'search' );

			$normalized[] = self::blank_filter(
				[
					'enabled'        => self::truthy( $filter['enabled'] ?? false ),
					'label'          => sanitize_text_field( $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' ) ),
					'type'           => $type,
					'key'            => sanitize_key( $filter['key'] ?? '' ),
					'source'         => self::allowed_value( $filter['source'] ?? 'visible_text', $sources, 'visible_text' ),
					'query_var'      => sanitize_key( $filter['query_var'] ?? '' ),
					'compare'        => self::allowed_value( $filter['compare'] ?? 'contains', $compares, 'contains' ),
					'data_type'      => self::allowed_value( $filter['data_type'] ?? 'string', $data_types, 'string' ),
					'placeholder'    => sanitize_text_field( $filter['placeholder'] ?? '' ),
					'options'        => self::normalize_options_payload( $filter ),
					'range_min'      => is_numeric( $filter['range_min'] ?? null ) ? (float) $filter['range_min'] : 0,
					'range_max'      => is_numeric( $filter['range_max'] ?? null ) ? (float) $filter['range_max'] : 100,
					'range_step'     => is_numeric( $filter['range_step'] ?? null ) ? (float) $filter['range_step'] : 1,
					'default_value'  => sanitize_text_field( $filter['default_value'] ?? '' ),
					'empty_behavior' => self::allowed_value( $filter['empty_behavior'] ?? 'ignore', [ 'ignore', 'hide_all' ], 'ignore' ),
					'show_count'     => self::truthy( $filter['show_count'] ?? false ),
					'show_label'     => self::truthy( $filter['show_label'] ?? false ),
				]
			);
		}

		return $normalized;
	}

	private static function truthy( $value ) {
		return in_array( $value, [ true, 1, '1', 'yes', 'on', 'true' ], true );
	}

	private static function allowed_value( $value, array $allowed, $fallback ) {
		$value = sanitize_key( $value );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private static function sanitize_created_from( $raw ) {
		$raw = is_array( $raw ) ? $raw : [];

		return [
			'source'      => self::allowed_value( $raw['source'] ?? 'admin', [ 'admin', 'elementor_widget', 'legacy' ], 'admin' ),
			'saved_via'   => self::allowed_value( $raw['saved_via'] ?? 'admin_form', [ 'admin_form', 'elementor_editor', 'migration' ], 'admin_form' ),
			'document_id' => absint( $raw['document_id'] ?? 0 ),
			'element_id'  => sanitize_text_field( $raw['element_id'] ?? '' ),
		];
	}

	private static function normalize_options_payload( array $filter ) {
		$options = self::compile_options_lines( $filter['options_items'] ?? [], 120, true );

		if ( '' !== $options ) {
			return $options;
		}

		return self::limit_lines( sanitize_textarea_field( $filter['options'] ?? '' ), 120 );
	}

	private static function compile_options_lines( $items, $limit, $include_visual ) {
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

	private static function unique_id( $base, array $existing ) {
		$base = sanitize_key( $base );
		$base = '' !== $base ? $base : 'filter-preset';
		$id = $base;
		$index = 2;

		while ( isset( $existing[ $id ] ) ) {
			$id = $base . '-' . $index;
			$index++;
		}

		return $id;
	}

	private static function limit_lines( $text, $limit ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$lines = array_slice( $lines, 0, max( 1, absint( $limit ) ) );

		return implode( "\n", $lines );
	}
}
