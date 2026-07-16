<?php
/**
 * Typed Elementor URL tag for a stable Toolkit Field ID.
 */

namespace EIT\Elementor\DynamicTags;

use Elementor\Modules\DynamicTags\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TypedUrlTag extends AbstractTypedTag {

	public function get_name() {
		return 'eit-field-url';
	}

	public function get_title() {
		return __( 'Toolkit Field · URL', 'elementor-implementation-toolkit' );
	}

	public function get_categories() {
		return [ Module::URL_CATEGORY ];
	}

	protected function field_categories() {
		return [ 'url' ];
	}

	protected function value_category() {
		return 'url';
	}
}
