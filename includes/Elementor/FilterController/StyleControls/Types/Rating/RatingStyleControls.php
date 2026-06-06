<?php
/**
 * Rating style controls orchestrator for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Rating;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RatingStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_rating_style',
			[
				'label'     => esc_html__( 'Rating', 'elementor-implementation-toolkit' ),
				'tab'       => Controls_Manager::TAB_STYLE,
			]
		);

		RatingDisplayStyleControls::register( $widget );
		RatingBaseStyleControls::register( $widget );
		RatingIconStyleControls::register( $widget );

		$widget->end_controls_section();
	}
}
