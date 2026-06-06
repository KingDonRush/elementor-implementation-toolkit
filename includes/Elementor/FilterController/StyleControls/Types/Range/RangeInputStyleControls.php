<?php
/**
 * Style controls module extracted from Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Range;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RangeInputStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->add_control(
			'range_show_inputs',
			[
				'label'        => esc_html__( 'Show Number Inputs', 'elementor-implementation-toolkit' ),
				'description'  => esc_html__( 'Visible min and max fields. Sliders keep working when these fields are hidden.', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$widget->add_control(
			'range_input_flow',
			[
				'label'     => esc_html__( 'Horizontal Input Placement', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'before',
				'options'   => [
					'before' => [
						'title' => esc_html__( 'Above', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-v-align-top',
					],
					'after'  => [
						'title' => esc_html__( 'Below', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-v-align-bottom',
					],
				],
				'condition' => [
					'range_orientation'             => 'horizontal',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_input_position',
			[
				'label'     => esc_html__( 'Number Input Side', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'left',
				'options'   => [
					'left'  => [
						'title' => esc_html__( 'Left', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-left',
					],
					'right' => [
						'title' => esc_html__( 'Right', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-right',
					],
				],
				'condition' => [
					'range_orientation'             => 'vertical',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_input_width',
			[
				'label'      => esc_html__( 'Number Input Width', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 72,
						'max' => 280,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-input-width: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_input_gap',
			[
				'label'      => esc_html__( 'Number Input Gap', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 32,
					],
				],
				'default'    => [
					'size' => 10,
					'unit' => 'px',
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-input-gap: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_input_height',
			[
				'label'      => esc_html__( 'Number Input Height', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 28,
						'max' => 72,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range-number' => 'min-height: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_input_text_color',
			[
				'label'     => esc_html__( 'Number Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range-number' => 'color: {{VALUE}};',
				],
				'condition' => [
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_input_background',
			[
				'label'     => esc_html__( 'Number Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range-number' => 'background-color: {{VALUE}};',
				],
				'condition' => [
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_input_border_color',
			[
				'label'     => esc_html__( 'Number Border', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range-number' => 'border-color: {{VALUE}};',
				],
				'condition' => [
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_input_radius',
			[
				'label'      => esc_html__( 'Number Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-range-number' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'condition'  => [
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_input_padding',
			[
				'label'      => esc_html__( 'Number Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-range-number' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'condition'  => [
					'range_show_inputs'             => 'yes',
				],
			]
		);
	}

}
