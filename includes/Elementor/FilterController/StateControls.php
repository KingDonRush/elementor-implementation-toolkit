<?php
/**
 * Result-state and pagination controls for the Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StateControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_state',
			[ 'label' => esc_html__( 'State & Pagination', 'elementor-implementation-toolkit' ) ]
		);
		$widget->add_control(
			'show_result_count',
			[
				'label'        => esc_html__( 'Show Result Count', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);
		$widget->add_control(
			'result_count_text',
			[
				'label'     => esc_html__( 'Result Count Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( '{count} results', 'elementor-implementation-toolkit' ),
				'condition' => [ 'show_result_count' => 'yes' ],
			]
		);
		$widget->add_control(
			'show_active_chips',
			[
				'label'        => esc_html__( 'Show Active Filter Chips', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);
		$widget->add_control(
			'show_apply',
			[
				'label'        => esc_html__( 'Show Apply Button', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			]
		);
		$widget->add_control(
			'apply_text',
			[
				'label'     => esc_html__( 'Apply Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Apply filters', 'elementor-implementation-toolkit' ),
				'condition' => [ 'show_apply' => 'yes' ],
			]
		);
		$widget->add_control(
			'reset_text',
			[
				'label'   => esc_html__( 'Reset Text', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Reset', 'elementor-implementation-toolkit' ),
			]
		);
		$widget->add_control(
			'empty_text',
			[
				'label'   => esc_html__( 'Empty State Text', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'No matching items found.', 'elementor-implementation-toolkit' ),
			]
		);
		$widget->add_control(
			'pagination_heading',
			[
				'label'     => esc_html__( 'Pagination', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		$widget->add_control(
			'pagination_type',
			[
				'label'   => esc_html__( 'Pagination Type', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'numbers',
				'options' => [
					'numbers'        => esc_html__( 'Numbers', 'elementor-implementation-toolkit' ),
					'prev_next'      => esc_html__( 'Previous / Next', 'elementor-implementation-toolkit' ),
					'numbers_arrows' => esc_html__( 'Numbers + Arrows', 'elementor-implementation-toolkit' ),
					'none'           => esc_html__( 'None', 'elementor-implementation-toolkit' ),
				],
			]
		);
		$widget->add_control(
			'previous_text',
			[
				'label'     => esc_html__( 'Previous Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Previous', 'elementor-implementation-toolkit' ),
				'condition' => [ 'pagination_type' => [ 'prev_next', 'numbers_arrows' ] ],
			]
		);
		$widget->add_control(
			'next_text',
			[
				'label'     => esc_html__( 'Next Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Next', 'elementor-implementation-toolkit' ),
				'condition' => [ 'pagination_type' => [ 'prev_next', 'numbers_arrows' ] ],
			]
		);
		$widget->end_controls_section();
	}
}
