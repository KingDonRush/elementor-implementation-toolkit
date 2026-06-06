<?php
/**
 * Rating display controls for Filter Controller.
 */

namespace EIT\Elementor\FilterController\StyleControls\Types\Rating;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RatingDisplayStyleControls {

	public static function register( Widget_Base $widget ) {
		$widget->add_control(
			'rating_display_mode',
			[
				'label'   => esc_html__( 'Display Mode', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'icon_text',
				'options' => [
					'icon_text' => esc_html__( 'Icon + Text', 'elementor-implementation-toolkit' ),
					'icon'      => esc_html__( 'Icon Only', 'elementor-implementation-toolkit' ),
					'text'      => esc_html__( 'Text Only', 'elementor-implementation-toolkit' ),
				],
			]
		);

		$widget->add_control(
			'rating_threshold_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Rating filters are thresholds: selecting a value keeps items at that value and above. Use labels like 4+ stars when the source field is numeric.', 'elementor-implementation-toolkit' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			]
		);
	}
}
