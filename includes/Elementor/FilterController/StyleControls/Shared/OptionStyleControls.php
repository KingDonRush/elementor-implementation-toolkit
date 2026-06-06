<?php
/**
 * Style controls module extracted from Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Shared;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OptionStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_option_style',
			[
				'label'     => esc_html__( 'Options, Chips & Swatches', 'elementor-implementation-toolkit' ),
				'tab'       => Controls_Manager::TAB_STYLE,
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'option_typography',
				'selector' => '{{WRAPPER}} .eit-option',
			]
		);

		$widget->add_control(
			'option_color',
			[
				'label'     => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'option_background',
			[
				'label'     => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'option_active_color',
			[
				'label'     => esc_html__( 'Active Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option:has(input:checked), {{WRAPPER}} .eit-option.is-active' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'option_active_background',
			[
				'label'     => esc_html__( 'Active Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option:has(input:checked), {{WRAPPER}} .eit-option.is-active' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'option_border',
				'selector' => '{{WRAPPER}} .eit-option',
			]
		);

		$widget->add_responsive_control(
			'option_radius',
			[
				'label'      => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-option' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'option_padding',
			[
				'label'      => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-option' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->end_controls_section();
	}
}
