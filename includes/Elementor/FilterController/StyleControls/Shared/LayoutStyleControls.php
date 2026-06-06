<?php
/**
 * Style controls module extracted from Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Shared;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LayoutStyleControls {

	public static function register( Widget_Base $widget ) {
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
}
