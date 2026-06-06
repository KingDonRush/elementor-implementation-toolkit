<?php
/**
 * Style controls module extracted from Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Shared;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaginationStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_pagination_style',
			[
				'label'     => esc_html__( 'Pagination', 'elementor-implementation-toolkit' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'pagination_type!' => 'none',
				],
			]
		);

		$widget->add_responsive_control(
			'pagination_gap',
			[
				'label' => esc_html__( 'Gap', 'elementor-implementation-toolkit' ),
				'type'  => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 40,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-pagination' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'pagination_color',
			[
				'label'     => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'pagination_background',
			[
				'label'     => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'pagination_active_color',
			[
				'label'     => esc_html__( 'Active Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button.is-active' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'pagination_active_background',
			[
				'label'     => esc_html__( 'Active Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button.is-active' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->end_controls_section();
	}
}
