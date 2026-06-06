<?php
/**
 * Checkbox style controls for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Checkbox;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckboxStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_checkbox_style',
			[
				'label' => esc_html__( 'Checkbox', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$widget->add_control(
			'checkbox_direction',
			[
				'label'     => esc_html__( 'Direction', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'row',
				'options'   => [
					'row'    => [
						'title' => esc_html__( 'Row', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-ellipsis-h',
					],
					'column' => [
						'title' => esc_html__( 'Stack', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-editor-list-ul',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-options--checkbox' => 'flex-direction: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'checkbox_wrap',
			[
				'label'     => esc_html__( 'Wrap', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'wrap',
				'options'   => [
					'wrap'   => [
						'title' => esc_html__( 'Wrap', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-ellipsis-h',
					],
					'nowrap' => [
						'title' => esc_html__( 'No Wrap', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-editor-list-ul',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-options--checkbox' => 'flex-wrap: {{VALUE}};',
				],
			]
		);

		$widget->add_responsive_control(
			'checkbox_columns',
			[
				'label'     => esc_html__( 'Grid Columns', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 6,
				'step'      => 1,
				'selectors' => [
					'{{WRAPPER}} .eit-options--checkbox' => 'display: grid; grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
				],
			]
		);

		$widget->add_responsive_control(
			'checkbox_gap',
			[
				'label'      => esc_html__( 'Gap', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 32,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-checkbox-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		self::register_indicator_controls( $widget );
		self::register_state_controls( $widget );

		$widget->end_controls_section();
	}

	private static function register_indicator_controls( Widget_Base $widget ) {
		$widget->add_control(
			'checkbox_indicator_heading',
			[
				'label'     => esc_html__( 'Indicator', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'checkbox_indicator_display',
			[
				'label'     => esc_html__( 'Indicator', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'inline-flex',
				'options'   => [
					'inline-flex' => esc_html__( 'Show', 'elementor-implementation-toolkit' ),
					'none'        => esc_html__( 'Hide', 'elementor-implementation-toolkit' ),
				],
				'selectors' => [
					'{{WRAPPER}} .eit-option--checkbox .eit-checkbox-indicator' => 'display: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'checkbox_indicator_position',
			[
				'label'     => esc_html__( 'Position', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => '0',
				'options'   => [
					'0' => [
						'title' => esc_html__( 'Left', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-left',
					],
					'2' => [
						'title' => esc_html__( 'Right', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-right',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-checkbox-indicator-order: {{VALUE}};',
				],
			]
		);

		$widget->add_responsive_control(
			'checkbox_indicator_size',
			[
				'label'      => esc_html__( 'Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 12,
						'max' => 36,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-checkbox-indicator-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'checkbox_indicator_active_background',
			[
				'label'     => esc_html__( 'Checked Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-checkbox-indicator-active-background: {{VALUE}};',
				],
			]
		);
	}

	private static function register_state_controls( Widget_Base $widget ) {
		$widget->add_control(
			'checkbox_state_heading',
			[
				'label'     => esc_html__( 'States', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'checkbox_active_background',
			[
				'label'     => esc_html__( 'Active Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-checkbox-active-background: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'checkbox_focus_ring_color',
			[
				'label'     => esc_html__( 'Focus Ring', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-checkbox-focus-ring-color: {{VALUE}};',
				],
			]
		);
	}
}
