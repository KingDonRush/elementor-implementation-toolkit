<?php
/**
 * Date style controls for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Date;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DateStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_date_style',
			[
				'label' => esc_html__( 'Date Range', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		self::register_contract_controls( $widget );
		self::register_layout_controls( $widget );
		self::register_field_controls( $widget );
		self::register_state_controls( $widget );

		$widget->end_controls_section();
	}

	private static function register_contract_controls( Widget_Base $widget ) {
		$widget->add_control(
			'date_native_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Date uses native browser date inputs with YYYY-MM-DD values. Inverted ranges are corrected before filtering; locale display and picker UI remain browser-controlled.', 'elementor-implementation-toolkit' ),
				'content_classes' => 'elementor-control-field-description',
			]
		);
	}

	private static function register_layout_controls( Widget_Base $widget ) {
		$widget->add_control(
			'date_stack_fields',
			[
				'label'        => esc_html__( 'Stack Fields', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => [
					'{{WRAPPER}} .eit-date-range'            => 'grid-template-columns: 1fr;',
					'{{WRAPPER}} .eit-date-range__separator' => 'justify-content: flex-start; min-height: auto;',
				],
			]
		);

		$widget->add_responsive_control(
			'date_gap',
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
					'{{WRAPPER}} .eit-filter-controller' => '--eit-date-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'date_hide_labels',
			[
				'label'        => esc_html__( 'Visually Hide Labels', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => [
					'{{WRAPPER}} .eit-date-range__label' => 'position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;',
				],
			]
		);

		$widget->add_control(
			'date_separator_display',
			[
				'label'     => esc_html__( 'Separator', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'inline-flex',
				'options'   => [
					'inline-flex' => esc_html__( 'Show', 'elementor-implementation-toolkit' ),
					'none'        => esc_html__( 'Hide', 'elementor-implementation-toolkit' ),
				],
				'selectors' => [
					'{{WRAPPER}} .eit-date-range__separator' => 'display: {{VALUE}};',
				],
			]
		);
	}

	private static function register_field_controls( Widget_Base $widget ) {
		$widget->add_control(
			'date_field_heading',
			[
				'label'     => esc_html__( 'Fields', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_responsive_control(
			'date_input_height',
			[
				'label'      => esc_html__( 'Input Height', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 36,
						'max' => 88,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-date-input-height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'date_label_color',
			[
				'label'     => esc_html__( 'Label Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-date-label-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'date_separator_color',
			[
				'label'     => esc_html__( 'Separator Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-date-separator-color: {{VALUE}};',
				],
			]
		);
	}

	private static function register_state_controls( Widget_Base $widget ) {
		$widget->add_control(
			'date_state_heading',
			[
				'label'     => esc_html__( 'States', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'date_focus_ring_color',
			[
				'label'     => esc_html__( 'Focus Ring', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-date-focus-ring-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'date_invalid_color',
			[
				'label'     => esc_html__( 'Invalid Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-date-invalid-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'date_invalid_background',
			[
				'label'     => esc_html__( 'Invalid Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-date-invalid-background: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'date_clear_color',
			[
				'label'     => esc_html__( 'Clear Button', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-date-clear-color: {{VALUE}};',
				],
			]
		);
	}
}
