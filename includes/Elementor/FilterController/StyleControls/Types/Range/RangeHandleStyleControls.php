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

class RangeHandleStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->add_responsive_control(
			'range_handle_size',
			[
				'label'      => esc_html__( 'Handle Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 12,
						'max' => 36,
					],
				],
				'default'    => [
					'size' => 18,
					'unit' => 'px',
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'range_handle_shape',
			[
				'label'     => esc_html__( 'Handle Shape', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '999px',
				'options'   => [
					'999px' => esc_html__( 'Circle', 'elementor-implementation-toolkit' ),
					'16px'  => esc_html__( 'Soft Circle', 'elementor-implementation-toolkit' ),
					'12px'  => esc_html__( 'Squircle', 'elementor-implementation-toolkit' ),
					'8px'   => esc_html__( 'Rounded', 'elementor-implementation-toolkit' ),
					'4px'   => esc_html__( 'Soft Square', 'elementor-implementation-toolkit' ),
					'2px'   => esc_html__( 'Square', 'elementor-implementation-toolkit' ),
					'0px'   => esc_html__( 'Sharp', 'elementor-implementation-toolkit' ),
				],
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-radius: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'range_handle_color',
			[
				'label'     => esc_html__( 'Handle Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'range_handle_border_color',
			[
				'label'     => esc_html__( 'Handle Border', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-border-color: {{VALUE}};',
				],
			]
		);

		$widget->add_responsive_control(
			'range_handle_border_width',
			[
				'label'      => esc_html__( 'Handle Border Width', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 8,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-border-width: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'range_handle_icon_heading',
			[
				'label'     => esc_html__( 'Handle Icon', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'range_handle_icon_enabled',
			[
				'label'        => esc_html__( 'Use Icon or SVG Handle', 'elementor-implementation-toolkit' ),
				'description'  => esc_html__( 'Uses Elementor Icon Library or SVG upload as a visual overlay while keeping the native range input interactive.', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			]
		);

		$widget->add_control(
			'range_handle_icon',
			[
				'label'       => esc_html__( 'Handle Icon', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::ICONS,
				'label_block' => false,
				'skin'        => 'inline',
				'condition'   => [
					'range_handle_icon_enabled'     => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_handle_icon_size',
			[
				'label'      => esc_html__( 'Icon Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 8,
						'max' => 32,
					],
				],
				'default'    => [
					'size' => 12,
					'unit' => 'px',
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-icon-size: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'range_handle_icon_enabled'     => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_handle_icon_color',
			[
				'label'     => esc_html__( 'Icon Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-icon-color: {{VALUE}};',
				],
				'condition' => [
					'range_handle_icon_enabled'     => 'yes',
				],
			]
		);
	}

}
