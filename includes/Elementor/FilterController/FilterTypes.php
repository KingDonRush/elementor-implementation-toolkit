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
		return FilterTypeRegistry::keys();
	}

	public static function labels() {
		return FilterTypeRegistry::labels();
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
