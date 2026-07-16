<?php
/**
 * Typed Elementor text tag for a stable Toolkit Field ID.
 */

namespace EIT\Elementor\DynamicTags;

use Elementor\Modules\DynamicTags\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TypedTextTag extends AbstractTypedTag {

	public function get_name() {
		return 'eit-field-text';
	}

	public function get_title() {
		return __( 'Toolkit Field · Text', 'elementor-implementation-toolkit' );
	}

	public function get_categories() {
		return [ Module::TEXT_CATEGORY, Module::POST_META_CATEGORY ];
	}

	protected function field_categories() {
		return [ 'text' ];
	}

	protected function value_category() {
		return 'text';
	}
}
