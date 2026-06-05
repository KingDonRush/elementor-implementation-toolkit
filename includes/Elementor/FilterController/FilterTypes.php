<?php
/**
 * Canonical filter type definitions for the Elementor controller widget.
 */

namespace EIT\Elementor\FilterController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterTypes {

	public static function keys() {
		return array_keys( self::labels() );
	}

	public static function labels() {
		return [
			'search'   => esc_html__( 'Search', 'elementor-implementation-toolkit' ),
			'checkbox' => esc_html__( 'Checkboxes', 'elementor-implementation-toolkit' ),
			'radio'    => esc_html__( 'Radio', 'elementor-implementation-toolkit' ),
			'select'   => esc_html__( 'Select', 'elementor-implementation-toolkit' ),
			'chips'    => esc_html__( 'Chips', 'elementor-implementation-toolkit' ),
			'toggle'   => esc_html__( 'Toggle', 'elementor-implementation-toolkit' ),
			'range'    => esc_html__( 'Range / Min Max', 'elementor-implementation-toolkit' ),
			'date'     => esc_html__( 'Date Range', 'elementor-implementation-toolkit' ),
			'swatch'   => esc_html__( 'Swatches', 'elementor-implementation-toolkit' ),
			'rating'   => esc_html__( 'Rating', 'elementor-implementation-toolkit' ),
		];
	}

	public static function default_widget_filters() {
		return [
			[
				'label'        => esc_html__( 'Search', 'elementor-implementation-toolkit' ),
				'type'         => 'search',
				'placeholder'  => esc_html__( 'Search items...', 'elementor-implementation-toolkit' ),
				'layout_width' => 100,
			],
			[
				'label'        => esc_html__( 'Category', 'elementor-implementation-toolkit' ),
				'type'         => 'chips',
				'key'          => 'category',
				'options'      => "featured|Featured\nstandard|Standard",
				'layout_width' => 50,
			],
			[
				'label'        => esc_html__( 'Numeric Range', 'elementor-implementation-toolkit' ),
				'type'         => 'range',
				'key'          => 'value',
				'range_min'    => 0,
				'range_max'    => 100,
				'range_step'   => 1,
				'layout_width' => 50,
			],
		];
	}
}
