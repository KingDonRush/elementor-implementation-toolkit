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
	const LEGACY_GROUP = 'eit-toolkit-legacy-fields';

	public function init_hooks() {
		add_action( 'elementor/dynamic_tags/register', [ $this, 'register' ] );
	}

	public function register( $dynamic_tags ) {
		if ( ! ( new ElementorProAdapter() )->available() || ! is_object( $dynamic_tags ) || ! method_exists( $dynamic_tags, 'register' ) ) {
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

		$dynamic_tags->register( new TypedTextTag() );
		$dynamic_tags->register( new TypedNumberTag() );
		$dynamic_tags->register( new TypedUrlTag() );
		$dynamic_tags->register( new TypedImageTag() );
		$dynamic_tags->register( new TypedGalleryTag() );
		$dynamic_tags->register( new TypedColorTag() );

		if ( apply_filters( 'eit_register_legacy_dynamic_tags', true ) ) {
			if ( method_exists( $dynamic_tags, 'register_group' ) ) {
				$dynamic_tags->register_group( self::LEGACY_GROUP, [ 'title' => __( 'Implementation Toolkit — Legacy', 'elementor-implementation-toolkit' ) ] );
			}
			$dynamic_tags->register( new ToolkitFieldKeyTag() );
			$dynamic_tags->register( new CctTextTag() );
			$dynamic_tags->register( new CctUrlTag() );
			$dynamic_tags->register( new CctImageTag() );
			$dynamic_tags->register( new CctGalleryTag() );
		}
	}
}
