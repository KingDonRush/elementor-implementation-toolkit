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

class SortStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_sort_style',
			[
				'label'     => esc_html__( 'Sort', 'elementor-implementation-toolkit' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'show_sort' => 'yes',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'sort_label_typography',
				'selector' => '{{WRAPPER}} .eit-filter-group--sort .eit-filter-group__label',
			]
		);

		$widget->add_control(
			'sort_label_color',
			[
				'label'     => esc_html__( 'Label Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group--sort .eit-filter-group__label' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'sort_select_typography',
				'selector' => '{{WRAPPER}} .eit-filter-group--sort .eit-select',
			]
		);

		$widget->add_control(
			'sort_select_text_color',
			[
				'label'     => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group--sort .eit-select' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'sort_select_background',
			[
				'label'     => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group--sort .eit-select' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'sort_select_border',
				'selector' => '{{WRAPPER}} .eit-filter-group--sort .eit-select',
			]
		);

		$widget->add_responsive_control(
			'sort_select_radius',
			[
				'label'      => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-group--sort .eit-select' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'sort_select_padding',
			[
				'label'      => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-group--sort .eit-select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->end_controls_section();
	}
}
