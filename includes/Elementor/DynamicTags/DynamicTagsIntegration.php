<?php
/**
 * Elementor dynamic tag registration for Toolkit fields.
 */

namespace EIT\Elementor\DynamicTags;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DynamicTagsIntegration {

	const GROUP = 'eit-toolkit-fields';

	public function init_hooks() {
		add_action( 'elementor/dynamic_tags/register', [ $this, 'register' ] );
	}

	public function register( $dynamic_tags ) {
		if ( ! is_object( $dynamic_tags ) || ! method_exists( $dynamic_tags, 'register' ) ) {
			return;
		}

		if ( method_exists( $dynamic_tags, 'register_group' ) ) {
			$dynamic_tags->register_group(
				self::GROUP,
				[
					'title' => __( 'Implementation Toolkit', 'elementor-implementation-toolkit' ),
				]
			);
		}

		$dynamic_tags->register( new ToolkitFieldKeyTag() );
	}
}
