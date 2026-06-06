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

class RangeTrackStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->add_control(
			'range_track_style',
			[
				'label'     => esc_html__( 'Track Style', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'solid',
				'options'   => [
					'solid'     => esc_html__( 'Solid', 'elementor-implementation-toolkit' ),
					'dashed'    => esc_html__( 'Dashed', 'elementor-implementation-toolkit' ),
					'segmented' => esc_html__( 'Segmented', 'elementor-implementation-toolkit' ),
				],
			]
		);

		$widget->add_control(
			'range_track_color',
			[
				'label'       => esc_html__( 'Range Accent', 'elementor-implementation-toolkit' ),
				'description' => esc_html__( 'Colors the browser-native range accent where supported. It does not create a custom min-max interval fill.', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => [
					'{{WRAPPER}} .eit-range-input' => 'accent-color: {{VALUE}};',
					'{{WRAPPER}} .eit-range'       => '--eit-range-accent-color: {{VALUE}}; --eit-range-fill-color: {{VALUE}}; --eit-range-thumb-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'range_track_base_color',
			[
				'label'     => esc_html__( 'Base Track', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-track-color: {{VALUE}};',
				],
			]
		);

		$widget->add_responsive_control(
			'range_track_height',
			[
				'label'      => esc_html__( 'Track Height', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 2,
						'max' => 18,
					],
				],
				'default'    => [
					'size' => 6,
					'unit' => 'px',
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-track-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'range_vertical_height',
			[
				'label'      => esc_html__( 'Vertical Height', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 96,
						'max' => 360,
					],
				],
				'default'    => [
					'size' => 180,
					'unit' => 'px',
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-vertical-height: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'range_orientation'             => 'vertical',
				],
			]
		);
	}

}
