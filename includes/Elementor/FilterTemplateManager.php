<?php
/**
 * Elementor template bridge for filter preset layouts.
 */

namespace EIT\Elementor;

use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterTemplateManager {

	const ROLE_META = '_eit_template_role';
	const ROLE_FILTER_CONTROLLER = 'filter_controller';
	const PRESET_META = '_eit_filter_preset';
	const EDITOR_PAGE_TEMPLATE = 'elementor_canvas';

	public static function is_elementor_available() {
		return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' );
	}

	public static function get_templates( $preset_id = '' ) {
		if ( ! post_type_exists( 'elementor_library' ) ) {
			return [];
		}

		$meta_query = [
			[
				'key'   => self::ROLE_META,
				'value' => self::ROLE_FILTER_CONTROLLER,
			],
		];

		$preset_id = sanitize_key( $preset_id );

		if ( '' !== $preset_id ) {
			$meta_query[] = [
				'key'   => self::PRESET_META,
				'value' => $preset_id,
			];
		}

		if ( count( $meta_query ) > 1 ) {
			$meta_query['relation'] = 'AND';
		}

		return get_posts(
			[
				'post_type'      => 'elementor_library',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => $meta_query,
			]
		);
	}

	public static function create_filter_template( $preset_id, $title = '' ) {
		$preset_id = sanitize_key( $preset_id );
		$preset    = FilterPresets::get( $preset_id );

		if ( ! $preset ) {
			return new \WP_Error(
				'eit_filter_preset_not_found',
				__( 'Filter preset not found.', 'elementor-implementation-toolkit' )
			);
		}

		if ( ! self::is_elementor_available() ) {
			return new \WP_Error(
				'eit_elementor_unavailable',
				__( 'Elementor must be active to create filter templates.', 'elementor-implementation-toolkit' )
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'eit_template_permission',
				__( 'You do not have permission to create Elementor templates.', 'elementor-implementation-toolkit' )
			);
		}

		$title = sanitize_text_field( $title );

		if ( '' === $title ) {
			$title = sprintf(
				/* translators: %s: filter preset name. */
				__( '%s Filter Controls', 'elementor-implementation-toolkit' ),
				$preset['name'] ?: $preset_id
			);
		}

		$document = \Elementor\Plugin::$instance->documents->create(
			self::get_supported_document_type(),
			[
				'post_title'  => $title,
				'post_status' => 'publish',
			],
			[
				self::ROLE_META  => self::ROLE_FILTER_CONTROLLER,
				self::PRESET_META => $preset_id,
			]
		);

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		if ( ! $document || ! method_exists( $document, 'save' ) ) {
			return new \WP_Error(
				'eit_template_create_failed',
				__( 'Could not create the Elementor filter template.', 'elementor-implementation-toolkit' )
			);
		}

		$document->save(
			[
				'elements' => self::get_starter_elements( $preset_id, $preset ),
				'settings' => [
					'template' => self::EDITOR_PAGE_TEMPLATE,
				],
			]
		);

		$template_id = absint( $document->get_main_id() );
		update_post_meta( $template_id, self::ROLE_META, self::ROLE_FILTER_CONTROLLER );
		update_post_meta( $template_id, self::PRESET_META, $preset_id );
		self::ensure_editor_surface( $template_id );

		return $template_id;
	}

	public static function get_edit_url( $template_id ) {
		$template_id = absint( $template_id );

		if ( self::is_filter_template( $template_id ) ) {
			self::ensure_editor_surface( $template_id );
		}

		if ( self::is_elementor_available() ) {
			$document = \Elementor\Plugin::$instance->documents->get( $template_id );

			if ( $document && method_exists( $document, 'get_edit_url' ) ) {
				return $document->get_edit_url();
			}
		}

		return add_query_arg(
			[
				'post'   => $template_id,
				'action' => 'elementor',
			],
			admin_url( 'post.php' )
		);
	}

	public static function delete_filter_template( $template_id ) {
		$template_id = absint( $template_id );

		if ( ! self::is_filter_template( $template_id ) ) {
			return new \WP_Error(
				'eit_template_not_found',
				__( 'Filter template not found.', 'elementor-implementation-toolkit' )
			);
		}

		if ( ! current_user_can( 'delete_post', $template_id ) ) {
			return new \WP_Error(
				'eit_template_delete_permission',
				__( 'You do not have permission to delete this template.', 'elementor-implementation-toolkit' )
			);
		}

		if ( defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS > 0 ) {
			$deleted = wp_trash_post( $template_id );
		} else {
			$deleted = wp_delete_post( $template_id, true );
		}

		if ( ! $deleted ) {
			return new \WP_Error(
				'eit_template_delete_failed',
				__( 'Could not delete the filter template.', 'elementor-implementation-toolkit' )
			);
		}

		return true;
	}

	public static function is_filter_template( $template_id ) {
		$template_id = absint( $template_id );

		return $template_id
			&& 'elementor_library' === get_post_type( $template_id )
			&& self::ROLE_FILTER_CONTROLLER === get_post_meta( $template_id, self::ROLE_META, true );
	}

	public static function ensure_editor_surface( $template_id ) {
		$template_id = absint( $template_id );

		if ( ! $template_id || 'elementor_library' !== get_post_type( $template_id ) ) {
			return;
		}

		update_post_meta( $template_id, '_elementor_template_type', 'page' );
		update_post_meta( $template_id, '_wp_page_template', self::EDITOR_PAGE_TEMPLATE );

		$page_settings = get_post_meta( $template_id, '_elementor_page_settings', true );
		$page_settings = is_array( $page_settings ) ? $page_settings : [];
		$page_settings['template'] = self::EDITOR_PAGE_TEMPLATE;

		update_post_meta( $template_id, '_elementor_page_settings', $page_settings );
	}

	private static function get_supported_document_type() {
		return 'page';
	}

	private static function get_starter_elements( $preset_id, array $preset ) {
		return [
			[
				'id'       => self::element_id( 'filter-shell' ),
				'elType'   => 'container',
				'isInner'  => false,
				'settings' => [
					'container_type' => 'flex',
					'content_width'  => 'boxed',
					'flex_direction' => 'column',
					'gap'            => [
						'size' => 16,
						'unit' => 'px',
					],
					'padding'        => [
						'top'      => 20,
						'right'    => 20,
						'bottom'   => 20,
						'left'     => 20,
						'unit'     => 'px',
						'isLinked' => true,
					],
					'html_tag'       => 'section',
				],
				'elements' => [
					[
						'id'         => self::element_id( 'title' ),
						'elType'     => 'widget',
						'isInner'    => false,
						'widgetType' => 'heading',
						'settings'   => [
							'title'       => $preset['name'] ?: __( 'Filter controls', 'elementor-implementation-toolkit' ),
							'header_size' => 'h3',
						],
						'elements'   => [],
					],
					[
						'id'         => self::element_id( 'controller' ),
						'elType'     => 'widget',
						'isInner'    => false,
						'widgetType' => 'eit-filter-controller',
						'settings'   => [
							'configuration_source' => 'preset',
							'filter_preset'        => $preset_id,
							'auto_apply'           => 'yes',
							'search_debounce_ms'   => $preset['search_debounce_ms'] ?? 250,
							'sync_url'             => ! empty( $preset['sync_url'] ) ? 'yes' : '',
						],
						'elements'   => [],
					],
				],
			],
		];
	}

	private static function element_id( $seed ) {
		return substr( md5( uniqid( $seed, true ) ), 0, 7 );
	}
}
