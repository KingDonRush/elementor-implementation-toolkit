<?php
/**
 * Style-tab controls for the Elementor Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

use EIT\Elementor\FilterController\StyleControls\Shared\ButtonStyleControls;
use EIT\Elementor\FilterController\StyleControls\Shared\ChipStyleControls;
use EIT\Elementor\FilterController\StyleControls\Shared\FieldStyleControls;
use EIT\Elementor\FilterController\StyleControls\Shared\LayoutStyleControls;
use EIT\Elementor\FilterController\StyleControls\Shared\OptionStyleControls;
use EIT\Elementor\FilterController\StyleControls\Shared\PaginationStyleControls;
use EIT\Elementor\FilterController\StyleControls\Shared\SortStyleControls;
use EIT\Elementor\FilterController\StyleControls\Shared\StateStyleControls;
use EIT\Elementor\FilterController\StyleControls\Types\Checkbox\CheckboxStyleControls;
use EIT\Elementor\FilterController\StyleControls\Types\Chips\ChipsStyleControls;
use EIT\Elementor\FilterController\StyleControls\Types\Range\RangeStyleControls;
use EIT\Elementor\FilterController\StyleControls\Types\Radio\RadioStyleControls;
use EIT\Elementor\FilterController\StyleControls\Types\Rating\RatingStyleControls;
use EIT\Elementor\FilterController\StyleControls\Types\Select\SelectStyleControls;
use EIT\Elementor\FilterController\StyleControls\Types\Search\SearchStyleControls;
use EIT\Elementor\FilterController\StyleControls\Types\Toggle\ToggleStyleControls;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StyleControls {

	public static function register( Widget_Base $widget ) {
		LayoutStyleControls::register( $widget );
		FieldStyleControls::register( $widget );
		OptionStyleControls::register( $widget );
		CheckboxStyleControls::register( $widget );
		ChipsStyleControls::register( $widget );
		RadioStyleControls::register( $widget );
		ToggleStyleControls::register( $widget );
		SearchStyleControls::register( $widget );
		SelectStyleControls::register( $widget );
		RangeStyleControls::register( $widget );
		RatingStyleControls::register( $widget );
		SortStyleControls::register( $widget );
		ButtonStyleControls::register( $widget );
		ChipStyleControls::register( $widget );
		PaginationStyleControls::register( $widget );
		StateStyleControls::register( $widget );
	}
}
