<?php
/**
 * Style controls module extracted from Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Rating;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RatingIconStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->add_control(
			'rating_icon_heading',
			[
				'label'     => esc_html__( 'Icon', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'rating_icon_enabled',
			[
				'label'        => esc_html__( 'Show Icon', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => [
					'rating_display_mode!' => 'text',
				],
			]
		);

		$widget->add_control(
			'rating_icon',
			[
				'label'       => esc_html__( 'Rating Icon', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::ICONS,
				'label_block' => false,
				'skin'        => 'inline',
				'default'     => [
					'value'   => 'fas fa-star',
					'library' => 'fa-solid',
				],
				'condition'   => [
					'rating_display_mode!' => 'text',
					'rating_icon_enabled'  => 'yes',
				],
			]
		);

		$widget->add_control(
			'rating_icon_position',
			[
				'label'     => esc_html__( 'Icon Position', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'before',
				'options'   => [
					'before' => [
						'title' => esc_html__( 'Before', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-left',
					],
					'after'  => [
						'title' => esc_html__( 'After', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-h-align-right',
					],
				],
				'condition' => [
					'rating_display_mode!' => 'text',
					'rating_icon_enabled'  => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'rating_icon_size',
			[
				'label'      => esc_html__( 'Icon Size', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [
					'px' => [
						'min' => 8,
						'max' => 48,
					],
					'em' => [
						'min'  => 0.5,
						'max'  => 3,
						'step' => 0.1,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-icon-size: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'rating_display_mode!' => 'text',
					'rating_icon_enabled'  => 'yes',
				],
			]
		);

		$widget->add_responsive_control(
			'rating_icon_gap',
			[
				'label'      => esc_html__( 'Icon Gap', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 32,
					],
					'em' => [
						'min'  => 0,
						'max'  => 2,
						'step' => 0.1,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-gap: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'rating_display_mode!' => 'text',
					'rating_icon_enabled'  => 'yes',
				],
			]
		);

		$widget->add_control(
			'rating_icon_color',
			[
				'label'     => esc_html__( 'Icon Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-icon-color: {{VALUE}};',
				],
				'condition' => [
					'rating_display_mode!' => 'text',
					'rating_icon_enabled'  => 'yes',
				],
			]
		);

		$widget->add_control(
			'rating_active_icon_color',
			[
				'label'     => esc_html__( 'Active Icon Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-active-icon-color: {{VALUE}};',
				],
				'condition' => [
					'rating_display_mode!' => 'text',
					'rating_icon_enabled'  => 'yes',
				],
			]
		);
	}

}
