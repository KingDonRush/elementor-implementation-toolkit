<?php
/**
 * Optional capability check for Elementor installations that expose active tags.
 */

namespace EIT\Elementor\DynamicTags;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorProAdapter {

	public function available() {
		return defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\\ElementorPro\\Plugin' );
	}

	public function health_check() {
		return [
			'ok' => $this->available(),
			'version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'capabilities' => $this->available() ? [ 'typed_dynamic_tags' ] : [],
		];
	}
}
