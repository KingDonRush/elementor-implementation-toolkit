<?php
/**
 * Radio style controls for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Radio;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RadioStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_radio_style',
			[
				'label' => esc_html__( 'Radio', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		self::register_layout_controls( $widget );
		self::register_indicator_controls( $widget );
		self::register_state_controls( $widget );

		$widget->end_controls_section();
	}

	private static function register_layout_controls( Widget_Base $widget ) {
		$widget->add_control(
			'radio_layout_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Radio is a single-choice group. Use segmented mode when every option should share one row; use stack or wrap when labels need more breathing room.', 'elementor-implementation-toolkit' ),
				'content_classes' => 'elementor-control-field-description',
			]
		);

		$widget->add_control(
			'radio_direction',
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
					'{{WRAPPER}} .eit-options--radio' => 'flex-direction: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'radio_wrap',
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
					'{{WRAPPER}} .eit-options--radio' => 'flex-wrap: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'radio_equal_width',
			[
				'label'        => esc_html__( 'Equal Width', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => [
					'{{WRAPPER}} .eit-option--radio' => 'flex: 1 1 0; justify-content: center;',
				],
			]
		);

		$widget->add_control(
			'radio_segmented',
			[
				'label'        => esc_html__( 'Segmented Mode', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => [
					'{{WRAPPER}} .eit-options--radio' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(0, 1fr)); gap: 0;',
					'{{WRAPPER}} .eit-option--radio'  => 'justify-content: center; border-radius: 0; margin-left: -1px;',
					'{{WRAPPER}} .eit-option--radio:first-child' => 'margin-left: 0;',
				],
			]
		);

		$widget->add_responsive_control(
			'radio_gap',
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
					'{{WRAPPER}} .eit-filter-controller' => '--eit-radio-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);
	}

	private static function register_indicator_controls( Widget_Base $widget ) {
		$widget->add_control(
			'radio_indicator_heading',
			[
				'label'     => esc_html__( 'Indicator', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'radio_indicator_display',
			[
				'label'     => esc_html__( 'Indicator', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'inline-flex',
				'options'   => [
					'inline-flex' => esc_html__( 'Show', 'elementor-implementation-toolkit' ),
					'none'        => esc_html__( 'Hide', 'elementor-implementation-toolkit' ),
				],
				'selectors' => [
					'{{WRAPPER}} .eit-option--radio .eit-radio-indicator' => 'display: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'radio_indicator_position',
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
					'{{WRAPPER}} .eit-filter-controller' => '--eit-radio-indicator-order: {{VALUE}};',
				],
			]
		);

		$widget->add_responsive_control(
			'radio_indicator_size',
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
					'{{WRAPPER}} .eit-filter-controller' => '--eit-radio-indicator-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'radio_dot_size',
			[
				'label'      => esc_html__( 'Dot Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 4,
						'max' => 18,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-radio-dot-size: {{SIZE}}{{UNIT}};',
				],
			]
		);
	}

	private static function register_state_controls( Widget_Base $widget ) {
		$widget->add_control(
			'radio_state_heading',
			[
				'label'     => esc_html__( 'States', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'radio_active_background',
			[
				'label'     => esc_html__( 'Selected Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-radio-active-background: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'radio_active_text_color',
			[
				'label'     => esc_html__( 'Selected Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-radio-active-text-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'radio_focus_ring_color',
			[
				'label'     => esc_html__( 'Focus Ring', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-radio-focus-ring-color: {{VALUE}};',
				],
			]
		);
	}
}
