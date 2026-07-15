<?php
/**
 * Sort controls for the Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Widget_Base;
use EIT\Support\SortOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SortControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_sort',
			[ 'label' => esc_html__( 'Sort', 'elementor-implementation-toolkit' ) ]
		);
		$widget->add_control(
			'show_sort',
			[
				'label'        => esc_html__( 'Show Sort', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);
		$widget->add_control(
			'sort_label',
			[
				'label'     => esc_html__( 'Sort Label', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Sort by', 'elementor-implementation-toolkit' ),
				'condition' => [ 'show_sort' => 'yes' ],
			]
		);

		$repeater = new Repeater();
		$repeater->add_control(
			'label',
			[
				'label'   => esc_html__( 'Option Label', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Sort option', 'elementor-implementation-toolkit' ),
			]
		);
		$repeater->add_control(
			'source',
			[
				'label'   => esc_html__( 'Sort Source', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'title',
				'options' => SortOptions::source_options(),
			]
		);
		$repeater->add_control(
			'key',
			[
				'label'       => esc_html__( 'Data Key', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'price, rating, stock, sort',
				'description' => esc_html__( 'Matches data-eit-{key} or data-{key} collected from each listing item.', 'elementor-implementation-toolkit' ),
				'condition'   => [ 'source' => [ 'numeric', 'rating', 'data' ] ],
			]
		);
		$repeater->add_control(
			'data_type',
			[
				'label'     => esc_html__( 'Data Type', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'text',
				'options'   => SortOptions::data_type_options(),
				'condition' => [ 'source' => 'data' ],
			]
		);
		$repeater->add_control(
			'direction',
			[
				'label'     => esc_html__( 'Direction', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'asc',
				'options'   => SortOptions::direction_options(),
				'condition' => [ 'source!' => 'default' ],
			]
		);

		$widget->add_control(
			'sort_options_items',
			[
				'label'         => esc_html__( 'Sort Options', 'elementor-implementation-toolkit' ),
				'type'          => Controls_Manager::REPEATER,
				'fields'        => $repeater->get_controls(),
				'title_field'   => '{{{ label }}}',
				'default'       => SortOptions::default_widget_items(),
				'prevent_empty' => false,
				'condition'     => [ 'show_sort' => 'yes' ],
			]
		);
		$widget->add_control(
			'sort_show_legacy_options',
			[
				'label'        => esc_html__( 'Show Legacy Sort Lines', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'separator'    => 'before',
				'condition'    => [ 'show_sort' => 'yes' ],
			]
		);
		$widget->add_control(
			'sort_options',
			[
				'label'       => esc_html__( 'Legacy Sort Lines', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 5,
				'default'     => SortOptions::default_lines(),
				'description' => esc_html__( 'Advanced fallback. One option per line: value|Label.', 'elementor-implementation-toolkit' ),
				'condition'   => [ 'show_sort' => 'yes', 'sort_show_legacy_options' => 'yes' ],
			]
		);
		$widget->end_controls_section();
	}
}
