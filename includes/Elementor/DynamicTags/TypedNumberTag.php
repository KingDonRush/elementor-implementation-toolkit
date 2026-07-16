<?php
/**
 * Typed Elementor number tag for a stable Toolkit Field ID.
 */

namespace EIT\Elementor\DynamicTags;

use Elementor\Modules\DynamicTags\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TypedNumberTag extends AbstractTypedTag {

	public function get_name() {
		return 'eit-field-number';
	}

	public function get_title() {
		return __( 'Toolkit Field · Number', 'elementor-implementation-toolkit' );
	}

	public function get_categories() {
		return [ Module::NUMBER_CATEGORY ];
	}

	protected function field_categories() {
		return [ 'number' ];
	}

	protected function value_category() {
		return 'number';
	}
}
