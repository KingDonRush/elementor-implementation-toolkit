<?php
/**
 * Search style controls for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Search;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SearchStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_search_style',
			[
				'label' => esc_html__( 'Search', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$widget->add_control(
			'search_icon_heading',
			[
				'label' => esc_html__( 'Icon', 'elementor-implementation-toolkit' ),
				'type'  => Controls_Manager::HEADING,
			]
		);

		$widget->add_responsive_control(
			'search_icon_size',
			[
				'label'      => esc_html__( 'Icon Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 10,
						'max' => 36,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-search-icon-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$widget->add_control(
			'search_icon_color',
			[
				'label'     => esc_html__( 'Icon Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-search-icon-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'search_clear_heading',
			[
				'label'     => esc_html__( 'Clear Button', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'search_clear_color',
			[
				'label'     => esc_html__( 'Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-search-clear-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'search_clear_hover_color',
			[
				'label'     => esc_html__( 'Hover Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-search-clear-hover-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'search_clear_hover_background',
			[
				'label'     => esc_html__( 'Hover Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-search-clear-hover-background: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'search_focus_ring_color',
			[
				'label'     => esc_html__( 'Focus Ring', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-search-focus-ring-color: {{VALUE}};',
				],
			]
		);

		$widget->end_controls_section();
	}
}
