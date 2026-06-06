<?php
/**
 * Toggle style controls for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Toggle;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToggleStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_toggle_style',
			[
				'label' => esc_html__( 'Toggle', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		self::register_contract_controls( $widget );
		self::register_layout_controls( $widget );
		self::register_switch_controls( $widget );
		self::register_thumb_controls( $widget );
		self::register_state_controls( $widget );

		$widget->end_controls_section();
	}

	private static function register_contract_controls( Widget_Base $widget ) {
		$widget->add_control(
			'toggle_contract_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Toggle is one boolean condition: checked sends the first option value; unchecked and Reset clear this filter.', 'elementor-implementation-toolkit' ),
				'content_classes' => 'elementor-control-field-description',
			]
		);
	}

	private static function register_layout_controls( Widget_Base $widget ) {
		$widget->add_control(
			'toggle_label_position',
			[
				'label'     => esc_html__( 'Label Position', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'row',
				'options'   => [
					'row'         => [
						'title' => esc_html__( 'Right', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-right',
					],
					'row-reverse' => [
						'title' => esc_html__( 'Left', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-left',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-toggle' => 'flex-direction: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'toggle_full_width',
			[
				'label'        => esc_html__( 'Full Row', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => [
					'{{WRAPPER}} .eit-toggle'       => 'width: 100%; justify-content: space-between;',
					'{{WRAPPER}} .eit-toggle__text' => 'flex: 1 1 auto;',
				],
			]
		);

		$widget->add_responsive_control(
			'toggle_gap',
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
					'{{WRAPPER}} .eit-filter-controller' => '--eit-toggle-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);
	}

	private static function register_switch_controls( Widget_Base $widget ) {
		$widget->add_control(
			'toggle_switch_heading',
			[
				'label'     => esc_html__( 'Switch Track', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_responsive_control(
			'toggle_track_width',
			[
				'label'      => esc_html__( 'Width', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 34,
						'max' => 96,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-toggle-track-width: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'toggle_track_height',
			[
				'label'      => esc_html__( 'Height', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 20,
						'max' => 54,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-toggle-track-height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'toggle_track_off_color',
			[
				'label'     => esc_html__( 'Off Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-toggle-track-off-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'toggle_track_on_color',
			[
				'label'     => esc_html__( 'On Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-toggle-track-on-color: {{VALUE}};',
				],
			]
		);
	}

	private static function register_thumb_controls( Widget_Base $widget ) {
		$widget->add_control(
			'toggle_thumb_heading',
			[
				'label'     => esc_html__( 'Thumb', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_responsive_control(
			'toggle_thumb_size',
			[
				'label'      => esc_html__( 'Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 12,
						'max' => 48,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-toggle-thumb-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'toggle_thumb_color',
			[
				'label'     => esc_html__( 'Thumb Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-toggle-thumb-color: {{VALUE}};',
				],
			]
		);
	}

	private static function register_state_controls( Widget_Base $widget ) {
		$widget->add_control(
			'toggle_state_heading',
			[
				'label'     => esc_html__( 'States', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'toggle_state_text_display',
			[
				'label'     => esc_html__( 'On / Off Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'none',
				'options'   => [
					'none'        => esc_html__( 'Hide', 'elementor-implementation-toolkit' ),
					'inline-flex' => esc_html__( 'Show', 'elementor-implementation-toolkit' ),
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-toggle-state-display: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'toggle_active_background',
			[
				'label'     => esc_html__( 'Active Row Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-toggle-active-background: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'toggle_focus_ring_color',
			[
				'label'     => esc_html__( 'Focus Ring', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-toggle-focus-ring-color: {{VALUE}};',
				],
			]
		);
	}
}
