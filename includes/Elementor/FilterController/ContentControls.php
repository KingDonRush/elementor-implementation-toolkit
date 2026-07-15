<?php
/**
 * Content-tab control composition for the Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentControls {

	public static function register( Widget_Base $widget ) {
		TargetControls::register( $widget );
		FilterControls::register( $widget );
		SortControls::register( $widget );
		StateControls::register( $widget );
	}
}
