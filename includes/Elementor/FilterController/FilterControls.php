<?php
/**
 * Filter repeater controls for the Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_filters',
			[
				'label' => esc_html__( 'Filters', 'elementor-implementation-toolkit' ),
				'condition' => [ 'data_provider!' => 'collection' ],
			]
		);

		$repeater = new Repeater();
		$repeater->add_control(
			'label',
			[
				'label'   => esc_html__( 'Label', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Filter', 'elementor-implementation-toolkit' ),
			]
		);
		$repeater->add_control(
			'type',
			[
				'label'   => esc_html__( 'Type', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'search',
				'options' => FilterTypes::labels(),
			]
		);
		$repeater->add_control(
			'field_binding',
			[
				'label'       => esc_html__( 'Field Binding', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'price, category, rating',
				'description' => esc_html__( 'Use an Elementor Dynamic Tag or enter the field key that represents this filter. Manual Data Key remains the fallback.', 'elementor-implementation-toolkit' ),
				'dynamic'     => [ 'active' => true ],
				'condition'   => [ 'type!' => 'search' ],
			]
		);

		foreach ( [ 'field_binding_dynamic', 'resolved_key', 'key_source' ] as $control_id ) {
			$repeater->add_control( $control_id, [ 'type' => Controls_Manager::HIDDEN ] );
		}
		$repeater->add_control( 'source', [ 'type' => Controls_Manager::HIDDEN, 'default' => 'visible_text' ] );
		$repeater->add_control( 'compare', [ 'type' => Controls_Manager::HIDDEN, 'default' => '' ] );
		$repeater->add_control( 'data_type', [ 'type' => Controls_Manager::HIDDEN, 'default' => '' ] );

		$repeater->add_control(
			'key',
			[
				'label'       => esc_html__( 'Manual Data Key', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'category, price, material, rating',
				'description' => esc_html__( 'Fallback key matched against data-eit-{key}, data-{key}, taxonomy slugs, classes, or visible text.', 'elementor-implementation-toolkit' ),
				'condition'   => [ 'type!' => 'search' ],
			]
		);
		$repeater->add_control(
			'placeholder',
			[
				'label'       => esc_html__( 'Placeholder', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Search...', 'elementor-implementation-toolkit' ),
				'description' => esc_html__( 'For Select, this becomes the empty option label, such as All items.', 'elementor-implementation-toolkit' ),
				'condition'   => [ 'type' => [ 'search', 'select' ] ],
			]
		);
		$repeater->add_control(
			'options',
			[
				'label'       => esc_html__( 'Options', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 6,
				'placeholder' => "value|Label|#14b8a6\npremium|Premium\nfast|Fast delivery",
				'description' => esc_html__( 'One option per line. Format: value|Label|optional visual|optional count.', 'elementor-implementation-toolkit' ),
				'condition'   => [ 'type' => [ 'checkbox', 'radio', 'select', 'chips', 'toggle', 'swatch', 'rating' ] ],
			]
		);
		$repeater->add_control(
			'toggle_value_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Toggle uses the first option row as its checked value. When unchecked or reset, it sends no filter.', 'elementor-implementation-toolkit' ),
				'content_classes' => 'elementor-control-field-description',
				'condition'       => [ 'type' => 'toggle' ],
			]
		);
		$repeater->add_control(
			'radio_show_all',
			[
				'label'        => esc_html__( 'Add All Option', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Adds a first radio option that clears only this radio group.', 'elementor-implementation-toolkit' ),
				'condition'    => [ 'type' => 'radio' ],
			]
		);
		$repeater->add_control(
			'radio_all_label',
			[
				'label'     => esc_html__( 'All Option Label', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'All', 'elementor-implementation-toolkit' ),
				'condition' => [ 'type' => 'radio', 'radio_show_all' => 'yes' ],
			]
		);

		self::register_range_controls( $repeater );

		$repeater->add_control(
			'show_label',
			[
				'label'        => esc_html__( 'Show Label', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);
		$repeater->add_control(
			'layout_width',
			[
				'label'       => esc_html__( 'Block Width (%)', 'elementor-implementation-toolkit' ),
				'description' => esc_html__( 'Controls how much of the filter row this block occupies. Blocks wrap automatically when the row is full.', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 10,
				'max'         => 100,
				'step'        => 1,
				'default'     => 100,
			]
		);

		$widget->add_control(
			'filters',
			[
				'label'         => esc_html__( 'Filter Controls', 'elementor-implementation-toolkit' ),
				'type'          => Controls_Manager::REPEATER,
				'fields'        => $repeater->get_controls(),
				'title_field'   => '{{{ label }}} - {{{ type }}}',
				'default'       => FilterTypes::default_widget_filters(),
				'prevent_empty' => false,
			]
		);

		self::register_type_state_controls( $widget );
		$widget->end_controls_section();
	}

	private static function register_range_controls( Repeater $repeater ) {
		$repeater->add_control(
			'range_min',
			[
				'label'     => esc_html__( 'Range Min', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 0,
				'condition' => [ 'type' => 'range' ],
			]
		);
		$repeater->add_control(
			'range_max',
			[
				'label'     => esc_html__( 'Range Max', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 100,
				'condition' => [ 'type' => 'range' ],
			]
		);
		$repeater->add_control(
			'range_step',
			[
				'label'     => esc_html__( 'Range Step', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 1,
				'condition' => [ 'type' => 'range' ],
			]
		);
	}

	private static function register_type_state_controls( Widget_Base $widget ) {
		$defaults = FilterTypeRegistry::state_flags_for_types(
			array_map(
				function ( $filter ) {
					return $filter['type'] ?? 'search';
				},
				FilterTypes::default_widget_filters()
			)
		);
		foreach ( $defaults as $control_id => $default ) {
			$widget->add_control( $control_id, [ 'type' => Controls_Manager::HIDDEN, 'default' => $default ] );
		}
	}
}
