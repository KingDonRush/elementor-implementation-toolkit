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

class StateStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_state_style',
			[
				'label' => esc_html__( 'Loading, Empty & Motion', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$widget->add_control(
			'transition_duration',
			[
				'label' => esc_html__( 'Transition Duration', 'elementor-implementation-toolkit' ),
				'type'  => Controls_Manager::SLIDER,
				'range' => [
					'ms' => [
						'min' => 0,
						'max' => 900,
					],
				],
				'default' => [
					'size' => 180,
					'unit' => 'ms',
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller, {{WRAPPER}} .eit-option, {{WRAPPER}} .eit-button, {{WRAPPER}} .eit-active-chip, {{WRAPPER}} .eit-page-button' => 'transition-duration: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'loading_opacity',
			[
				'label' => esc_html__( 'Listing Loading Opacity', 'elementor-implementation-toolkit' ),
				'type'  => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min'  => 0.1,
						'max'  => 1,
						'step' => 0.05,
					],
				],
				'default' => [
					'size' => 0.55,
				],
				'selectors' => [
					'body .eit-target-is-loading' => 'opacity: {{SIZE}};',
				],
			]
		);

		$widget->add_control(
			'empty_color',
			[
				'label'     => esc_html__( 'Empty Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-empty-state' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->end_controls_section();
	}
}
