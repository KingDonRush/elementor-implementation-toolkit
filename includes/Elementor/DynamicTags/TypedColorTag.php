<?php
/**
 * Typed Elementor color tag for a stable Toolkit Field ID.
 */

namespace EIT\Elementor\DynamicTags;

use Elementor\Modules\DynamicTags\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TypedColorTag extends AbstractTypedTag {

	public function get_name() {
		return 'eit-field-color';
	}

	public function get_title() {
		return __( 'Toolkit Field · Color', 'elementor-implementation-toolkit' );
	}

	public function get_categories() {
		return [ Module::COLOR_CATEGORY ];
	}

	protected function field_categories() {
		return [ 'color' ];
	}

	protected function value_category() {
		return 'color';
	}
}
