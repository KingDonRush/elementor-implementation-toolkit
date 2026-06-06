<?php
/**
 * Range style controls orchestrator for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Range;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RangeStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_range_style',
			[
				'label'     => esc_html__( 'Range', 'elementor-implementation-toolkit' ),
				'tab'       => Controls_Manager::TAB_STYLE,
			]
		);

		RangeLayoutStyleControls::register( $widget );
		RangeInputStyleControls::register( $widget );
		RangeTrackStyleControls::register( $widget );
		RangeHandleStyleControls::register( $widget );
		RangeLabelStyleControls::register( $widget );

		$widget->end_controls_section();
	}
}
