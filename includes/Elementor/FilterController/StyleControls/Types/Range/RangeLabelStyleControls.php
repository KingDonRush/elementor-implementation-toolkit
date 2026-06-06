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

class RangeLabelStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->add_control(
			'range_value_color',
			[
				'label'     => esc_html__( 'Current Value Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range__labels' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'range_tick_color',
			[
				'label'     => esc_html__( 'Scale Tick Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range__ticks' => 'color: {{VALUE}};',
				],
				'condition' => [
					'range_show_ticks'              => 'yes',
				],
			]
		);
	}

}
