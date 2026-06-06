<?php
/**
 * Chips style controls for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Chips;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChipsStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_chips_style',
			[
				'label' => esc_html__( 'Chips', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		self::register_layout_controls( $widget );
		self::register_density_controls( $widget );
		self::register_indicator_controls( $widget );
		self::register_state_controls( $widget );

		$widget->end_controls_section();
	}

	private static function register_layout_controls( Widget_Base $widget ) {
		$widget->add_control(
			'chips_layout_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Chips are compact multi-select tokens. Use scroll rows for large option sets and grid columns when choices need consistent widths.', 'elementor-implementation-toolkit' ),
				'content_classes' => 'elementor-control-field-description',
			]
		);

		$widget->add_control(
			'chips_wrap',
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
					'{{WRAPPER}} .eit-options--chips' => 'flex-wrap: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'chips_scroll_row',
			[
				'label'        => esc_html__( 'Scroll Row', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => [
					'{{WRAPPER}} .eit-options--chips' => 'flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px;',
					'{{WRAPPER}} .eit-option--chips'  => 'flex: 0 0 auto;',
				],
			]
		);

		$widget->add_responsive_control(
			'chips_columns',
			[
				'label'     => esc_html__( 'Grid Columns', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 6,
				'step'      => 1,
				'selectors' => [
					'{{WRAPPER}} .eit-options--chips' => 'display: grid; grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
				],
			]
		);

		$widget->add_responsive_control(
			'chips_gap',
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
					'{{WRAPPER}} .eit-filter-controller' => '--eit-chip-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);
	}

	private static function register_density_controls( Widget_Base $widget ) {
		$widget->add_control(
			'chips_density_heading',
			[
				'label'     => esc_html__( 'Density', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_responsive_control(
			'chips_min_height',
			[
				'label'      => esc_html__( 'Min Height', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 26,
						'max' => 64,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-chip-min-height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'chips_padding',
			[
				'label'      => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-option--chips' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'chips_truncate',
			[
				'label'        => esc_html__( 'Truncate Long Labels', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => [
					'{{WRAPPER}} .eit-option--chips .eit-option__label' => 'overflow: hidden; text-overflow: ellipsis; white-space: nowrap;',
				],
			]
		);
	}

	private static function register_indicator_controls( Widget_Base $widget ) {
		$widget->add_control(
			'chips_indicator_heading',
			[
				'label'     => esc_html__( 'Active Icon', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'chips_check_display',
			[
				'label'     => esc_html__( 'Check Icon', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'inline-flex',
				'options'   => [
					'inline-flex' => esc_html__( 'Show', 'elementor-implementation-toolkit' ),
					'none'        => esc_html__( 'Hide', 'elementor-implementation-toolkit' ),
				],
				'selectors' => [
					'{{WRAPPER}} .eit-chip-check' => 'display: {{VALUE}};',
				],
			]
		);

		$widget->add_responsive_control(
			'chips_check_size',
			[
				'label'      => esc_html__( 'Icon Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 10,
						'max' => 28,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-chip-check-size: {{SIZE}}{{UNIT}};',
				],
			]
		);
	}

	private static function register_state_controls( Widget_Base $widget ) {
		$widget->add_control(
			'chips_state_heading',
			[
				'label'     => esc_html__( 'States', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'chips_active_background',
			[
				'label'     => esc_html__( 'Active Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-chip-active-background: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'chips_active_outline_color',
			[
				'label'     => esc_html__( 'Active Outline', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-chip-active-outline-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'chips_focus_ring_color',
			[
				'label'     => esc_html__( 'Focus Ring', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-chip-focus-ring-color: {{VALUE}};',
				],
			]
		);
	}
}
