<?php
/**
 * Typed Elementor gallery tag for a stable Toolkit Field ID.
 */

namespace EIT\Elementor\DynamicTags;

use Elementor\Modules\DynamicTags\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TypedGalleryTag extends AbstractTypedTag {

	public function get_name() {
		return 'eit-field-gallery';
	}

	public function get_title() {
		return __( 'Toolkit Field · Gallery', 'elementor-implementation-toolkit' );
	}

	public function get_categories() {
		return [ Module::GALLERY_CATEGORY ];
	}

	protected function field_categories() {
		return [ 'gallery' ];
	}

	protected function value_category() {
		return 'gallery';
	}
}
