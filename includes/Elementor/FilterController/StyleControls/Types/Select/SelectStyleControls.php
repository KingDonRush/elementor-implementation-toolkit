<?php
/**
 * Select style controls for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Select;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SelectStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_select_style',
			[
				'label' => esc_html__( 'Select', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$widget->add_control(
			'select_native_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'The closed select field is styled here. The opened dropdown remains browser and OS native for reliability, keyboard behavior, and mobile picker support.', 'elementor-implementation-toolkit' ),
				'content_classes' => 'elementor-control-field-description',
			]
		);

		$widget->add_responsive_control(
			'select_field_height',
			[
				'label'      => esc_html__( 'Field Height', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 36,
						'max' => 88,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-select-field .eit-select' => 'min-height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'select_arrow_heading',
			[
				'label'     => esc_html__( 'Arrow', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_responsive_control(
			'select_arrow_size',
			[
				'label'      => esc_html__( 'Arrow Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 10,
						'max' => 32,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-select-arrow-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'select_arrow_color',
			[
				'label'     => esc_html__( 'Arrow Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-select-arrow-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'select_focus_ring_color',
			[
				'label'     => esc_html__( 'Focus Ring', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-select-focus-ring-color: {{VALUE}};',
				],
			]
		);

		$widget->end_controls_section();
	}
}
