<?php
/**
 * Published Elementor Loop Item templates available to CCT providers.
 */

namespace EIT\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctLoopTemplateCatalog {

	public static function options() {
		$options = [ '' => __( 'Select a Loop Item template', 'elementor-implementation-toolkit' ) ];

		if ( ! post_type_exists( 'elementor_library' ) ) {
			return $options;
		}

		$templates = get_posts(
			[
				'post_type'      => 'elementor_library',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => '_elementor_template_type',
				'meta_value'     => 'loop-item',
			]
		);

		foreach ( $templates as $template ) {
			$options[ $template->ID ] = $template->post_title ?: sprintf( __( 'Loop Item #%d', 'elementor-implementation-toolkit' ), $template->ID );
		}

		return $options;
	}

	public static function is_public_loop_item( $template_id ) {
		$template_id = absint( $template_id );

		return $template_id
			&& 'elementor_library' === get_post_type( $template_id )
			&& 'publish' === get_post_status( $template_id )
			&& 'loop-item' === get_post_meta( $template_id, '_elementor_template_type', true );
	}
}
