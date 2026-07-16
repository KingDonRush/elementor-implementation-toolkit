<?php
/**
 * Typed Elementor image tag for a stable Toolkit Field ID.
 */

namespace EIT\Elementor\DynamicTags;

use Elementor\Modules\DynamicTags\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TypedImageTag extends AbstractTypedTag {

	public function get_name() {
		return 'eit-field-image';
	}

	public function get_title() {
		return __( 'Toolkit Field · Image', 'elementor-implementation-toolkit' );
	}

	public function get_categories() {
		return [ Module::IMAGE_CATEGORY, Module::MEDIA_CATEGORY ];
	}

	protected function field_categories() {
		return [ 'image' ];
	}

	protected function value_category() {
		return 'image';
	}
}
