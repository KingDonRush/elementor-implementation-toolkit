<?php
/**
 * Style controls module extracted from Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Shared;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChipStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_chip_style',
			[
				'label'      => esc_html__( 'Active Chips & Count', 'elementor-implementation-toolkit' ),
				'tab'        => Controls_Manager::TAB_STYLE,
				'conditions' => StyleConditions::count_or_chips_conditions(),
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'       => 'meta_typography',
				'selector'   => '{{WRAPPER}} .eit-result-count, {{WRAPPER}} .eit-active-chip',
				'conditions' => StyleConditions::count_or_chips_conditions(),
			]
		);

		$widget->add_control(
			'chip_color',
			[
				'label'     => esc_html__( 'Chip Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-active-chip' => 'color: {{VALUE}};',
				],
				'condition' => [
					'show_active_chips' => 'yes',
				],
			]
		);

		$widget->add_control(
			'chip_background',
			[
				'label'     => esc_html__( 'Chip Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-active-chip' => 'background-color: {{VALUE}};',
				],
				'condition' => [
					'show_active_chips' => 'yes',
				],
			]
		);

		$widget->add_control(
			'count_color',
			[
				'label'     => esc_html__( 'Count Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-result-count' => 'color: {{VALUE}};',
				],
				'condition' => [
					'show_result_count' => 'yes',
				],
			]
		);

		$widget->end_controls_section();
	}
}
