<?php
/**
 * Swatch style controls for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Swatch;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SwatchStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_swatch_style',
			[
				'label' => esc_html__( 'Swatches', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		self::register_contract_controls( $widget );
		self::register_layout_controls( $widget );
		self::register_visual_controls( $widget );
		self::register_state_controls( $widget );
		self::register_fallback_controls( $widget );

		$widget->end_controls_section();
	}

	private static function register_contract_controls( Widget_Base $widget ) {
		$widget->add_control(
			'swatch_contract_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Swatches support hex colors and image URLs. Invalid or empty visuals render a fallback mark while the label remains available for accessibility.', 'elementor-implementation-toolkit' ),
				'content_classes' => 'elementor-control-field-description',
			]
		);
	}

	private static function register_layout_controls( Widget_Base $widget ) {
		$widget->add_control(
			'swatch_wrap',
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
					'{{WRAPPER}} .eit-options--swatch' => 'flex-wrap: {{VALUE}};',
				],
			]
		);

		$widget->add_responsive_control(
			'swatch_columns',
			[
				'label'     => esc_html__( 'Grid Columns', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 8,
				'step'      => 1,
				'selectors' => [
					'{{WRAPPER}} .eit-options--swatch' => 'display: grid; grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
				],
			]
		);

		$widget->add_responsive_control(
			'swatch_gap',
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
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-option-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'swatch_hide_labels',
			[
				'label'        => esc_html__( 'Visually Hide Labels', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'description'  => esc_html__( 'Keeps labels available to assistive tech while making the swatch visual primary.', 'elementor-implementation-toolkit' ),
				'selectors'    => [
					'{{WRAPPER}} .eit-option--swatch .eit-option__label' => 'position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;',
				],
			]
		);
	}

	private static function register_visual_controls( Widget_Base $widget ) {
		$widget->add_control(
			'swatch_visual_heading',
			[
				'label'     => esc_html__( 'Visual', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_responsive_control(
			'swatch_size',
			[
				'label'      => esc_html__( 'Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 18,
						'max' => 72,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'swatch_shape',
			[
				'label'     => esc_html__( 'Shape', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '999px',
				'options'   => [
					'999px' => esc_html__( 'Circle', 'elementor-implementation-toolkit' ),
					'8px'   => esc_html__( 'Rounded', 'elementor-implementation-toolkit' ),
					'0'     => esc_html__( 'Square', 'elementor-implementation-toolkit' ),
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-radius: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'swatch_image_fit',
			[
				'label'     => esc_html__( 'Image Fit', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'cover',
				'options'   => [
					'cover'   => esc_html__( 'Cover', 'elementor-implementation-toolkit' ),
					'contain' => esc_html__( 'Contain', 'elementor-implementation-toolkit' ),
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-image-fit: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'swatch_border_color',
			[
				'label'     => esc_html__( 'Border Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-border-color: {{VALUE}};',
				],
			]
		);
	}

	private static function register_state_controls( Widget_Base $widget ) {
		$widget->add_control(
			'swatch_state_heading',
			[
				'label'     => esc_html__( 'Selected State', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'swatch_ring_color',
			[
				'label'     => esc_html__( 'Ring Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-ring-color: {{VALUE}};',
				],
			]
		);

		$widget->add_responsive_control(
			'swatch_ring_width',
			[
				'label'      => esc_html__( 'Ring Width', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 1,
						'max' => 8,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-ring-width: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'swatch_ring_offset',
			[
				'label'      => esc_html__( 'Ring Offset', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 8,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-ring-offset: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'swatch_focus_ring_color',
			[
				'label'     => esc_html__( 'Focus Ring', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-focus-ring-color: {{VALUE}};',
				],
			]
		);
	}

	private static function register_fallback_controls( Widget_Base $widget ) {
		$widget->add_control(
			'swatch_fallback_heading',
			[
				'label'     => esc_html__( 'Fallback', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'swatch_fallback_background',
			[
				'label'     => esc_html__( 'Fallback Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-fallback-background: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'swatch_fallback_color',
			[
				'label'     => esc_html__( 'Fallback Mark', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-swatch-fallback-color: {{VALUE}};',
				],
			]
		);
	}
}
