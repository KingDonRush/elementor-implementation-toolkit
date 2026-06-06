<?php
/**
 * Style controls module extracted from Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Range;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RangeLayoutStyleControls {

	public static function register( Widget_Base $widget ) {
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
			]
		);
	}

}
