<?php
/**
 * Controller for Toolkit custom post type administration.
 */

namespace EIT\Admin;

use EIT\CPT\CptManager;
use EIT\CPT\DefinitionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CptManagerAdmin {

	const SAVE_ACTION = 'eit_save_cpt_definition';
	const DELETE_ACTION = 'eit_delete_cpt_definition';
	const DUPLICATE_ACTION = 'eit_duplicate_cpt_definition';

	private $renderer;
	private $list_view;
	private $form_view;

	public function __construct( AdminRenderer $renderer ) {
		$this->renderer = $renderer;
		$this->list_view = new CptDefinitionListView( $renderer );
		$this->form_view = new CptDefinitionFormView( $renderer );
	}

	public function render( $active_slug, array $tabs ) {
		$definitions = CptManager::all();
		$slug = $this->current_slug();
		$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );
		$is_form = 'new' === $view || '' !== $slug;
		$definition = '' !== $slug ? CptManager::get( $slug ) : null;
		$form_state = $is_form ? AdminFormState::pull( 'cpt-definition' ) : null;

		if ( $is_form && ! $definition ) {
			$definition = CptManager::blank();
			$definition['slug'] = $slug;
		}
		if ( $form_state ) {
			$definition = $this->form_definition( $form_state['values'] ?? [], $definition ?: CptManager::blank() );
		}

		$this->renderer->render_shell(
			$active_slug,
			$tabs,
			[
				'title'   => $is_form ? __( 'Edit Post Type', 'elementor-implementation-toolkit' ) : __( 'CPT / Post Types', 'elementor-implementation-toolkit' ),
				'actions' => [
					[
						'label' => __( 'Add New', 'elementor-implementation-toolkit' ),
						'url'   => admin_url( 'admin.php?page=' . AdminPages::CPT_SLUG . '&view=new' ),
					],
				],
			],
			function () use ( $is_form, $definition, $definitions, $form_state ) {
				$this->renderer->render_notice( sanitize_key( wp_unslash( $_GET['eit_notice'] ?? '' ) ) );
				$this->renderer->render_form_error( $form_state['error'] ?? '' );
				if ( $is_form ) {
					$this->form_view->render( $definition );
					return;
				}
				$this->list_view->render( $definitions );
			}
		);
	}

	public function handle_save() {
		$this->assert_can_manage();
		check_admin_referer( self::SAVE_ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- DefinitionManager sanitizes the nested contract field by field.
		$raw = isset( $_POST['definition'] ) && is_array( $_POST['definition'] ) ? wp_unslash( $_POST['definition'] ) : [];
		$raw = $this->normalize_definition_post( $raw );
		$slug = CptManager::save_definition( $raw );
		if ( is_wp_error( $slug ) ) {
			AdminFormState::store( 'cpt-definition', $raw, $slug->get_error_message() );
			$original_slug = DefinitionManager::sanitize_post_type_slug( $raw['original_slug'] ?? '' );
			$this->redirect( $original_slug ? [ 'page' => AdminPages::CPT_SLUG, 'cpt' => $original_slug, 'eit_notice' => 'error' ] : [ 'page' => AdminPages::CPT_SLUG, 'view' => 'new', 'eit_notice' => 'error' ] );
		}

		$this->redirect( [ 'page' => AdminPages::CPT_SLUG, 'cpt' => $slug, 'eit_notice' => 'saved' ] );
	}

	public function handle_delete() {
		$this->assert_can_manage();
		$slug = $this->posted_or_requested_id( 'cpt' );
		check_admin_referer( self::DELETE_ACTION . '_' . $slug );
		CptManager::delete_definition( $slug );
		$this->redirect( [ 'page' => AdminPages::CPT_SLUG, 'eit_notice' => 'deleted' ] );
	}

	public function handle_duplicate() {
		$this->assert_can_manage();
		$slug = $this->posted_or_requested_id( 'cpt' );
		check_admin_referer( self::DUPLICATE_ACTION . '_' . $slug );
		$definition = CptManager::get( $slug );
		if ( ! $definition ) {
			$this->redirect( [ 'page' => AdminPages::CPT_SLUG, 'eit_notice' => 'error' ] );
		}

		$definition['slug'] = 'eit_' . substr( $definition['slug'] ?? $slug, 0, 14 );
		$definition['singular'] = sprintf( __( '%s Copy', 'elementor-implementation-toolkit' ), $definition['singular'] ?? $slug );
		$definition['plural'] = sprintf( __( '%s Copies', 'elementor-implementation-toolkit' ), $definition['plural'] ?? $slug );
		$new_slug = CptManager::save_definition( $definition );
		if ( is_wp_error( $new_slug ) ) {
			$this->redirect( [ 'page' => AdminPages::CPT_SLUG, 'eit_notice' => 'error' ] );
		}
		$this->redirect( [ 'page' => AdminPages::CPT_SLUG, 'cpt' => $new_slug, 'eit_notice' => 'saved' ] );
	}

	private function normalize_definition_post( $raw ) {
		$raw = is_array( $raw ) ? $raw : [];
		$raw['taxonomies'] = $this->filter_meaningful_rows( $raw['taxonomies'] ?? [], [ 'slug', 'singular', 'plural' ] );
		$raw['meta_fields'] = $this->filter_meaningful_rows( $raw['meta_fields'] ?? [], [ 'key', 'label', 'default', 'options' ] );
		return $raw;
	}

	private function form_definition( array $values, array $fallback ) {
		$definition = array_merge( CptManager::blank(), $fallback, $values );
		$definition['original_slug'] = DefinitionManager::sanitize_post_type_slug( $values['original_slug'] ?? $fallback['slug'] ?? '' );
		$definition['taxonomies'] = array_map(
			function ( $taxonomy ) {
				return array_merge( CptManager::blank_taxonomy(), is_array( $taxonomy ) ? $taxonomy : [] );
			},
			(array) ( $values['taxonomies'] ?? $fallback['taxonomies'] ?? [] )
		);
		$definition['meta_fields'] = array_map(
			function ( $field ) {
				return array_merge( CptManager::blank_meta_field(), is_array( $field ) ? $field : [] );
			},
			(array) ( $values['meta_fields'] ?? $fallback['meta_fields'] ?? [] )
		);
		return $definition;
	}

	private function filter_meaningful_rows( $rows, array $keys ) {
		$filtered = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				if ( '' !== trim( (string) ( $row[ $key ] ?? '' ) ) ) {
					$filtered[] = $row;
					continue 2;
				}
			}
		}
		return $filtered;
	}

	private function current_slug() {
		return isset( $_GET['cpt'] ) ? sanitize_key( wp_unslash( $_GET['cpt'] ) ) : '';
	}

	private function posted_or_requested_id( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The sanitized ID is needed to derive the action-specific nonce checked by each caller.
		if ( isset( $_POST[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the action-specific nonce immediately after this lookup.
			return sanitize_key( wp_unslash( $_POST[ $key ] ) );
		}
		return isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	private function assert_can_manage() {
		if ( ! current_user_can( AdminPages::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Toolkit settings.', 'elementor-implementation-toolkit' ) );
		}
	}

	private function redirect( array $args ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
