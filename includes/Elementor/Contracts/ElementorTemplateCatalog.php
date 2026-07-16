<?php
/**
 * Read-only catalog of existing Elementor templates for Presentation contracts.
 */

namespace EIT\Elementor\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorTemplateCatalog {

	const TYPES = [ 'container', 'section', 'page', 'loop-item' ];

	public function all() {
		if ( ! post_type_exists( 'elementor_library' ) ) {
			return [];
		}
		$templates = get_posts(
			[
				'post_type' => 'elementor_library',
				'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page' => 200,
				'orderby' => 'title',
				'order' => 'ASC',
			]
		);
		$result = [];
		foreach ( $templates as $template ) {
			$type = sanitize_key( get_post_meta( $template->ID, '_elementor_template_type', true ) );
			if ( ! in_array( $type, self::TYPES, true ) || 'filter_controller' === get_post_meta( $template->ID, '_eit_template_role', true ) ) {
				continue;
			}
			$result[] = [
				'id' => (int) $template->ID,
				'name' => $template->post_title ?: sprintf( __( 'Elementor template #%d', 'elementor-implementation-toolkit' ), $template->ID ),
				'type' => $type,
				'status' => get_post_status( $template->ID ),
			];
		}
		return $result;
	}

	public function is_public( $template_id ) {
		$template_id = absint( $template_id );
		$type = $template_id ? sanitize_key( get_post_meta( $template_id, '_elementor_template_type', true ) ) : '';
		return $template_id
			&& 'elementor_library' === get_post_type( $template_id )
			&& 'publish' === get_post_status( $template_id )
			&& in_array( $type, self::TYPES, true )
			&& 'filter_controller' !== get_post_meta( $template_id, '_eit_template_role', true );
	}
}
