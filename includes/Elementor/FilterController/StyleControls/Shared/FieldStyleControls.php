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

class FieldStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_field_style',
			[
				'label' => esc_html__( 'Fields', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .eit-filter-group__label',
			]
		);

		$widget->add_control(
			'label_color',
			[
				'label'     => esc_html__( 'Label Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group__label' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'field_typography',
				'selector' => '{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select',
			]
		);

		$widget->add_control(
			'field_text_color',
			[
				'label'     => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'field_background',
			[
				'label'     => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'field_border',
				'selector' => '{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select',
			]
		);

		$widget->add_responsive_control(
			'field_radius',
			[
				'label'      => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'field_padding',
			[
				'label'      => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->end_controls_section();
	}
}
