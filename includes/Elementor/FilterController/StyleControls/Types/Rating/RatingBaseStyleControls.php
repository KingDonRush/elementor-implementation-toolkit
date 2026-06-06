<?php
/**
 * Style controls module extracted from Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Rating;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RatingBaseStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'rating_typography',
				'selector' => '{{WRAPPER}} .eit-rating-option__label',
			]
		);

		$widget->add_control(
			'rating_color',
			[
				'label'     => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-text-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'rating_active_color',
			[
				'label'     => esc_html__( 'Active Text Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-active-text-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'rating_background',
			[
				'label'     => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-background: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'rating_active_background',
			[
				'label'     => esc_html__( 'Active Background', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-active-background: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'rating_border_color',
			[
				'label'     => esc_html__( 'Border Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-border-color: {{VALUE}};',
				],
			]
		);

		$widget->add_control(
			'rating_active_border_color',
			[
				'label'     => esc_html__( 'Active Border Color', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-active-border-color: {{VALUE}};',
				],
			]
		);

		$widget->add_responsive_control(
			'rating_radius',
			[
				'label'      => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$widget->add_responsive_control(
			'rating_padding',
			[
				'label'      => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .eit-filter-controller' => '--eit-rating-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
	}

}
