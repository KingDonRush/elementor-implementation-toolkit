<?php
/**
 * Style-tab controls for the Elementor Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StyleControls {

	public static function register( Widget_Base $widget ) {
		self::register_layout_controls( $widget );
		self::register_field_controls( $widget );
		self::register_option_controls( $widget );
		self::register_range_controls( $widget );
		self::register_rating_controls( $widget );
		self::register_sort_controls( $widget );
		self::register_button_controls( $widget );
		self::register_chip_controls( $widget );
		self::register_pagination_controls( $widget );
		self::register_state_controls( $widget );
	}

	private static function count_or_chips_conditions() {
		return [
			'relation' => 'or',
			'terms'    => [
				[
					'name'     => 'show_result_count',
					'operator' => '==',
					'value'    => 'yes',
				],
				[
					'name'     => 'show_active_chips',
					'operator' => '==',
					'value'    => 'yes',
				],
			],
		];
	}

	private static function register_layout_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_layout_style',
			[
				'label' => esc_html__( 'Layout', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$widget->add_responsive_control(
			'layout_direction',
			[
				'label'   => esc_html__( 'Direction', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::CHOOSE,
				'default' => 'column',
				'options' => [
					'row'    => [
						'title' => esc_html__( 'Horizontal', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-ellipsis-h',
					],
					'column' => [
						'title' => esc_html__( 'Vertical', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-editor-list-ul',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller__form' => 'flex-direction: {{VALUE}};',
				],
			]
		);

		$widget->add_responsive_control(
			'layout_gap',
			[
				'label' => esc_html__( 'Gap', 'elementor-implementation-toolkit' ),
				'type'  => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 80,
					],
				],
				'default' => [
					'size' => 16,
					'unit' => 'px',
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller__form' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'group_gap',
			[
				'label' => esc_html__( 'Group Gap', 'elementor-implementation-toolkit' ),
				'type'  => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 48,
					],
				],
				'default' => [
					'size' => 10,
					'unit' => 'px',
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Background::get_type(),
			[
				'name'     => 'controller_background',
				'selector' => '{{WRAPPER}} .eit-filter-controller',
			]
		);

		$widget->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'controller_border',
				'selector' => '{{WRAPPER}} .eit-filter-controller',
			]
		);

		$widget->add_responsive_control(
			'controller_radius',
			[
				'label'      => esc_html__( 'Border Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'controller_padding',
			[
				'label'      => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name'     => 'controller_shadow',
				'selector' => '{{WRAPPER}} .eit-filter-controller',
			]
		);

		$widget->end_controls_section();
	}

	private static function register_field_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_field_style',
			[
				'label' => esc_html__( 'Fields', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .eit-filter-group__label',
			]
		);

		$widget->add_control(
			'label_color',
			[
				'label'     => esc_html__( 'Label Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group__label' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'field_typography',
				'selector' => '{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select',
				'condition' => [
					'eit_filter_has_field_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'field_text_color',
			[
				'label'     => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'color: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_field_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'field_background',
			[
				'label'     => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'background-color: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_field_controls' => 'yes',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'field_border',
				'selector' => '{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select',
				'condition' => [
					'eit_filter_has_field_controls' => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'field_radius',
			[
				'label'      => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'condition'  => [
					'eit_filter_has_field_controls' => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'field_padding',
			[
				'label'      => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'condition'  => [
					'eit_filter_has_field_controls' => 'yes',
				],
			]
		);

		$widget->end_controls_section();
	}

	private static function register_option_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_option_style',
			[
				'label'     => esc_html__( 'Options, Chips & Swatches', 'elementor-implementation-toolkit' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'eit_filter_has_option_controls' => 'yes',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'option_typography',
				'selector' => '{{WRAPPER}} .eit-option',
			]
		);

		$widget->add_control(
			'option_color',
			[
				'label'     => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'option_background',
			[
				'label'     => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'option_active_color',
			[
				'label'     => esc_html__( 'Active Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option:has(input:checked), {{WRAPPER}} .eit-option.is-active' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'option_active_background',
			[
				'label'     => esc_html__( 'Active Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option:has(input:checked), {{WRAPPER}} .eit-option.is-active' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'option_border',
				'selector' => '{{WRAPPER}} .eit-option',
			]
		);

		$widget->add_responsive_control(
			'option_radius',
			[
				'label'      => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-option' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'option_padding',
			[
				'label'      => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-option' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->end_controls_section();
	}

	private static function register_range_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_range_style',
			[
				'label'     => esc_html__( 'Range', 'elementor-implementation-toolkit' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_orientation',
			[
				'label'     => esc_html__( 'Range Orientation', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'horizontal',
				'options'   => [
					'horizontal' => [
						'title' => esc_html__( 'Horizontal', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-ellipsis-h',
					],
					'vertical'   => [
						'title' => esc_html__( 'Vertical', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-editor-list-ul',
					],
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_show_values',
			[
				'label'        => esc_html__( 'Show Current Value Labels', 'elementor-implementation-toolkit' ),
				'description'  => esc_html__( 'Dynamic min and max labels that update as the handles move.', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'condition'    => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_show_ticks',
			[
				'label'        => esc_html__( 'Show Scale Ticks', 'elementor-implementation-toolkit' ),
				'description'  => esc_html__( 'Static min, midpoint, and max scale labels.', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'condition'    => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_vertical_alignment',
			[
				'label'     => esc_html__( 'Vertical Group Alignment', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'center',
				'options'   => [
					'start'  => [
						'title' => esc_html__( 'Left', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-left',
					],
					'center' => [
						'title' => esc_html__( 'Center', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-center',
					],
					'end'    => [
						'title' => esc_html__( 'Right', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-right',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-vertical-alignment: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
					'range_orientation'             => 'vertical',
				],
			]
		);

		$widget->add_responsive_control(
			'range_vertical_item_alignment',
			[
				'label'     => esc_html__( 'Vertical Item Alignment', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'center',
				'options'   => [
					'start'  => [
						'title' => esc_html__( 'Top', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-v-align-top',
					],
					'center' => [
						'title' => esc_html__( 'Middle', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-v-align-middle',
					],
					'end'    => [
						'title' => esc_html__( 'Bottom', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-v-align-bottom',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-vertical-item-alignment: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
					'range_orientation'             => 'vertical',
				],
			]
		);

		$widget->add_control(
			'range_input_heading',
			[
				'label'     => esc_html__( 'Number Inputs', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_show_inputs',
			[
				'label'        => esc_html__( 'Show Number Inputs', 'elementor-implementation-toolkit' ),
				'description'  => esc_html__( 'Visible min and max fields. Sliders keep working when these fields are hidden.', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_input_flow',
			[
				'label'     => esc_html__( 'Horizontal Input Placement', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'before',
				'options'   => [
					'before' => [
						'title' => esc_html__( 'Above', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-v-align-top',
					],
					'after'  => [
						'title' => esc_html__( 'Below', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-v-align-bottom',
					],
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
					'range_orientation'             => 'horizontal',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_input_position',
			[
				'label'     => esc_html__( 'Number Input Side', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'left',
				'options'   => [
					'left'  => [
						'title' => esc_html__( 'Left', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-left',
					],
					'right' => [
						'title' => esc_html__( 'Right', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-right',
					],
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
					'range_orientation'             => 'vertical',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_input_width',
			[
				'label'      => esc_html__( 'Number Input Width', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 72,
						'max' => 280,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-input-width: {{SIZE}}{{UNIT}}; --eit-range-horizontal-input-width: minmax(0, {{SIZE}}{{UNIT}});',
				],
				'condition'  => [
					'eit_filter_has_range_controls' => 'yes',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_input_gap',
			[
				'label'      => esc_html__( 'Number Input Gap', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 32,
					],
				],
				'default'    => [
					'size' => 10,
					'unit' => 'px',
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-input-gap: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'eit_filter_has_range_controls' => 'yes',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_input_height',
			[
				'label'      => esc_html__( 'Number Input Height', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 28,
						'max' => 72,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range-number' => 'min-height: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'eit_filter_has_range_controls' => 'yes',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_input_text_color',
			[
				'label'     => esc_html__( 'Number Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range-number' => 'color: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_input_background',
			[
				'label'     => esc_html__( 'Number Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range-number' => 'background-color: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_input_border_color',
			[
				'label'     => esc_html__( 'Number Border', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range-number' => 'border-color: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_input_radius',
			[
				'label'      => esc_html__( 'Number Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-range-number' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'condition'  => [
					'eit_filter_has_range_controls' => 'yes',
					'range_show_inputs'             => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_input_padding',
			[
				'label'      => esc_html__( 'Number Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-range-number' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'condition'  => [
					'eit_filter_has_range_controls' => 'yes',
					'range_show_inputs'             => 'yes',
				],
			]
		);

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
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
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
				'condition'   => [
					'eit_filter_has_range_controls' => 'yes',
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
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
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
				'condition'  => [
					'eit_filter_has_range_controls' => 'yes',
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
					'eit_filter_has_range_controls' => 'yes',
					'range_orientation'             => 'vertical',
				],
			]
		);

		$widget->add_responsive_control(
			'range_handle_size',
			[
				'label'      => esc_html__( 'Handle Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 12,
						'max' => 36,
					],
				],
				'default'    => [
					'size' => 18,
					'unit' => 'px',
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-size: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_handle_shape',
			[
				'label'     => esc_html__( 'Handle Shape', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '999px',
				'options'   => [
					'999px' => esc_html__( 'Circle', 'elementor-implementation-toolkit' ),
					'16px'  => esc_html__( 'Soft Circle', 'elementor-implementation-toolkit' ),
					'12px'  => esc_html__( 'Squircle', 'elementor-implementation-toolkit' ),
					'8px'   => esc_html__( 'Rounded', 'elementor-implementation-toolkit' ),
					'4px'   => esc_html__( 'Soft Square', 'elementor-implementation-toolkit' ),
					'2px'   => esc_html__( 'Square', 'elementor-implementation-toolkit' ),
					'0px'   => esc_html__( 'Sharp', 'elementor-implementation-toolkit' ),
				],
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-radius: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_handle_color',
			[
				'label'     => esc_html__( 'Handle Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-color: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_handle_border_color',
			[
				'label'     => esc_html__( 'Handle Border', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-border-color: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_handle_border_width',
			[
				'label'      => esc_html__( 'Handle Border Width', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 8,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-border-width: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_handle_icon_heading',
			[
				'label'     => esc_html__( 'Handle Icon', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_handle_icon_enabled',
			[
				'label'        => esc_html__( 'Use Icon or SVG Handle', 'elementor-implementation-toolkit' ),
				'description'  => esc_html__( 'Uses Elementor Icon Library or SVG upload as a visual overlay while keeping the native range input interactive.', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'condition'    => [
					'eit_filter_has_range_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_handle_icon',
			[
				'label'       => esc_html__( 'Handle Icon', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::ICONS,
				'label_block' => false,
				'skin'        => 'inline',
				'condition'   => [
					'eit_filter_has_range_controls' => 'yes',
					'range_handle_icon_enabled'     => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'range_handle_icon_size',
			[
				'label'      => esc_html__( 'Icon Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 8,
						'max' => 32,
					],
				],
				'default'    => [
					'size' => 12,
					'unit' => 'px',
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-icon-size: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'eit_filter_has_range_controls' => 'yes',
					'range_handle_icon_enabled'     => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_handle_icon_color',
			[
				'label'     => esc_html__( 'Icon Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range' => '--eit-range-thumb-icon-color: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
					'range_handle_icon_enabled'     => 'yes',
				],
			]
		);

		$widget->add_control(
			'range_value_color',
			[
				'label'     => esc_html__( 'Current Value Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range__labels' => 'color: {{VALUE}};',
				],
				'condition' => [
					'eit_filter_has_range_controls' => 'yes',
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
					'eit_filter_has_range_controls' => 'yes',
					'range_show_ticks'              => 'yes',
				],
			]
		);

		$widget->end_controls_section();
	}

	private static function register_rating_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_rating_style',
			[
				'label'     => esc_html__( 'Rating', 'elementor-implementation-toolkit' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'eit_filter_has_rating_controls' => 'yes',
				],
			]
		);

		$widget->add_control(
			'rating_color',
			[
				'label'     => esc_html__( 'Rating Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-rating-option span' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->end_controls_section();
	}

	private static function register_sort_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_sort_style',
			[
				'label'     => esc_html__( 'Sort', 'elementor-implementation-toolkit' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'show_sort' => 'yes',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'sort_label_typography',
				'selector' => '{{WRAPPER}} .eit-filter-group--sort .eit-filter-group__label',
			]
		);

		$widget->add_control(
			'sort_label_color',
			[
				'label'     => esc_html__( 'Label Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group--sort .eit-filter-group__label' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'sort_select_typography',
				'selector' => '{{WRAPPER}} .eit-filter-group--sort .eit-select',
			]
		);

		$widget->add_control(
			'sort_select_text_color',
			[
				'label'     => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group--sort .eit-select' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'sort_select_background',
			[
				'label'     => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group--sort .eit-select' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'sort_select_border',
				'selector' => '{{WRAPPER}} .eit-filter-group--sort .eit-select',
			]
		);

		$widget->add_responsive_control(
			'sort_select_radius',
			[
				'label'      => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-group--sort .eit-select' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'sort_select_padding',
			[
				'label'      => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-group--sort .eit-select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->end_controls_section();
	}

	private static function register_button_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_button_style',
			[
				'label' => esc_html__( 'Buttons', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .eit-button',
			]
		);

		$widget->add_control(
			'button_color',
			[
				'label'     => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-button' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'button_background',
			[
				'label'     => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-button' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'button_border',
				'selector' => '{{WRAPPER}} .eit-button',
			]
		);

		$widget->add_responsive_control(
			'button_radius',
			[
				'label'      => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'button_padding',
			[
				'label'      => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->end_controls_section();
	}

	private static function register_chip_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_chip_style',
			[
				'label'      => esc_html__( 'Active Chips & Count', 'elementor-implementation-toolkit' ),
				'tab'        => Controls_Manager::TAB_STYLE,
				'conditions' => self::count_or_chips_conditions(),
			]
		);

		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'       => 'meta_typography',
				'selector'   => '{{WRAPPER}} .eit-result-count, {{WRAPPER}} .eit-active-chip',
				'conditions' => self::count_or_chips_conditions(),
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

	private static function register_pagination_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_pagination_style',
			[
				'label'     => esc_html__( 'Pagination', 'elementor-implementation-toolkit' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'pagination_type!' => 'none',
				],
			]
		);

		$widget->add_responsive_control(
			'pagination_gap',
			[
				'label' => esc_html__( 'Gap', 'elementor-implementation-toolkit' ),
				'type'  => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 40,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-pagination' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'pagination_color',
			[
				'label'     => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'pagination_background',
			[
				'label'     => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'pagination_active_color',
			[
				'label'     => esc_html__( 'Active Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button.is-active' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'pagination_active_background',
			[
				'label'     => esc_html__( 'Active Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button.is-active' => 'background-color: {{VALUE}};',
				],
			]
		);

		$widget->end_controls_section();
	}

	private static function register_state_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_state_style',
			[
				'label' => esc_html__( 'Loading, Empty & Motion', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$widget->add_control(
			'transition_duration',
			[
				'label' => esc_html__( 'Transition Duration', 'elementor-implementation-toolkit' ),
				'type'  => Controls_Manager::SLIDER,
				'range' => [
					'ms' => [
						'min' => 0,
						'max' => 900,
					],
				],
				'default' => [
					'size' => 180,
					'unit' => 'ms',
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller, {{WRAPPER}} .eit-option, {{WRAPPER}} .eit-button, {{WRAPPER}} .eit-active-chip, {{WRAPPER}} .eit-page-button' => 'transition-duration: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'loading_opacity',
			[
				'label' => esc_html__( 'Listing Loading Opacity', 'elementor-implementation-toolkit' ),
				'type'  => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min'  => 0.1,
						'max'  => 1,
						'step' => 0.05,
					],
				],
				'default' => [
					'size' => 0.55,
				],
				'selectors' => [
					'body .eit-target-is-loading' => 'opacity: {{SIZE}};',
				],
			]
		);

		$widget->add_control(
			'empty_color',
			[
				'label'     => esc_html__( 'Empty Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-empty-state' => 'color: {{VALUE}};',
				],
			]
		);

		$widget->end_controls_section();
	}
}
