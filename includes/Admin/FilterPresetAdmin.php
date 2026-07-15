<?php
/**
 * Controller for reusable filter preset administration.
 */

namespace EIT\Admin;

use EIT\Elementor\FilterTemplateManager;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetAdmin {

	const SAVE_ACTION = 'eit_save_filter_preset';
	const DELETE_ACTION = 'eit_delete_filter_preset';
	const DUPLICATE_ACTION = 'eit_duplicate_filter_preset';
	const CREATE_TEMPLATE_ACTION = 'eit_create_filter_template';
	const DELETE_TEMPLATE_ACTION = 'eit_delete_filter_template';

	private $renderer;
	private $list_view;
	private $form_view;

	public function __construct( AdminRenderer $renderer ) {
		$this->renderer = $renderer;
		$inspector = new FilterPresetInspector();
		$preview = new FilterPresetPreviewView( $renderer, $inspector );
		$templates = new FilterPresetTemplateView();
		$this->list_view = new FilterPresetListView( $renderer, $inspector, $preview );
		$this->form_view = new FilterPresetFormView( $renderer, $preview, $templates );
	}

	public function render( $active_slug, array $tabs ) {
		$presets = FilterPresets::all();
		$preset_id = $this->current_preset_id();
		$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );
		$is_form = 'new' === $view || '' !== $preset_id;
		$preset = '' !== $preset_id ? FilterPresets::get( $preset_id ) : null;
		$form_state = $is_form ? AdminFormState::pull( 'filter-preset' ) : null;

		if ( $is_form && ! $preset ) {
			$preset = FilterPresets::blank();
			$preset['id'] = $preset_id;
		}
		if ( $form_state ) {
			$preset = array_merge( FilterPresets::blank(), $preset ?: [], $form_state['values'] ?? [] );
		}

		$this->renderer->render_shell(
			$active_slug,
			$tabs,
			[
				'title'   => $is_form ? __( 'Edit Filter Preset', 'elementor-implementation-toolkit' ) : __( 'Filter Presets', 'elementor-implementation-toolkit' ),
				'actions' => [
					[
						'label' => __( 'Add New', 'elementor-implementation-toolkit' ),
						'url'   => admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&view=new' ),
					],
				],
			],
			function () use ( $is_form, $preset, $presets, $form_state ) {
				$this->renderer->render_notice( sanitize_key( wp_unslash( $_GET['eit_notice'] ?? '' ) ) );
				$this->renderer->render_form_error( $form_state['error'] ?? '' );
				if ( $is_form ) {
					$this->form_view->render( $preset );
					return;
				}
				$this->list_view->render( $presets );
			}
		);
	}

	public function handle_save() {
		$this->assert_can_manage();
		check_admin_referer( self::SAVE_ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FilterPresets sanitizes the nested contract field by field.
		$raw = isset( $_POST['preset'] ) && is_array( $_POST['preset'] ) ? wp_unslash( $_POST['preset'] ) : [];
		$raw = $this->normalize_preset_post( $raw );
		$id = FilterPresets::save( $raw );
		if ( is_wp_error( $id ) ) {
			AdminFormState::store( 'filter-preset', $raw, $id->get_error_message() );
			$preset_id = sanitize_key( $raw['id'] ?? '' );
			$this->redirect( $preset_id ? [ 'page' => AdminPages::FILTERS_SLUG, 'preset' => $preset_id, 'eit_notice' => 'error' ] : [ 'page' => AdminPages::FILTERS_SLUG, 'view' => 'new', 'eit_notice' => 'error' ] );
		}
		$after_save = isset( $_POST['eit_after_save'] ) ? sanitize_key( wp_unslash( $_POST['eit_after_save'] ) ) : '';

		if ( 'open_template' === $after_save ) {
			$template_id = $this->get_or_create_template_for_preset( $id );
			if ( ! is_wp_error( $template_id ) ) {
				wp_safe_redirect( FilterTemplateManager::get_edit_url( $template_id ) );
				exit;
			}
			$this->redirect( [ 'page' => AdminPages::FILTERS_SLUG, 'preset' => $id, 'eit_notice' => 'error' ] );
		}

		$this->redirect( [ 'page' => AdminPages::FILTERS_SLUG, 'preset' => $id, 'eit_notice' => 'saved' ] );
	}

	public function handle_delete() {
		$this->assert_can_manage();
		$id = $this->posted_or_requested_id( 'preset' );
		check_admin_referer( self::DELETE_ACTION . '_' . $id );
		FilterPresets::delete( $id );
		$this->redirect( [ 'page' => AdminPages::FILTERS_SLUG, 'eit_notice' => 'deleted' ] );
	}

	public function handle_duplicate() {
		$this->assert_can_manage();
		$id = $this->posted_or_requested_id( 'preset' );
		check_admin_referer( self::DUPLICATE_ACTION . '_' . $id );
		$preset = FilterPresets::get( $id );
		if ( ! $preset ) {
			$this->redirect( [ 'page' => AdminPages::FILTERS_SLUG, 'eit_notice' => 'error' ] );
		}
		$preset['id'] = '';
		$preset['slug'] = '';
		$preset['name'] = sprintf( __( '%s Copy', 'elementor-implementation-toolkit' ), $preset['name'] ?? $id );
		$new_id = FilterPresets::save( $preset );
		if ( is_wp_error( $new_id ) ) {
			$this->redirect( [ 'page' => AdminPages::FILTERS_SLUG, 'eit_notice' => 'error' ] );
		}
		$this->redirect( [ 'page' => AdminPages::FILTERS_SLUG, 'preset' => $new_id, 'eit_notice' => 'saved' ] );
	}

	public function handle_create_template() {
		$this->assert_can_manage();
		$id = $this->posted_or_requested_id( 'preset' );
		check_admin_referer( self::CREATE_TEMPLATE_ACTION . '_' . $id );
		$title = isset( $_POST['template_title'] ) ? sanitize_text_field( wp_unslash( $_POST['template_title'] ) ) : '';
		$template_id = FilterTemplateManager::create_filter_template( $id, $title );
		if ( is_wp_error( $template_id ) ) {
			$this->redirect( [ 'page' => AdminPages::FILTERS_SLUG, 'preset' => $id, 'eit_notice' => 'error' ] );
		}
		wp_safe_redirect( FilterTemplateManager::get_edit_url( $template_id ) );
		exit;
	}

	public function handle_delete_template() {
		$this->assert_can_manage();
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$preset_id = $this->posted_or_requested_id( 'preset' );
		check_admin_referer( self::DELETE_TEMPLATE_ACTION . '_' . $template_id );
		$deleted = FilterTemplateManager::delete_filter_template( $template_id );
		$this->redirect( [ 'page' => AdminPages::FILTERS_SLUG, 'preset' => $preset_id, 'eit_notice' => is_wp_error( $deleted ) ? 'error' : 'deleted' ] );
	}

	private function get_or_create_template_for_preset( $preset_id ) {
		$templates = FilterTemplateManager::get_templates( $preset_id );
		if ( ! empty( $templates ) ) {
			$template = reset( $templates );
			return absint( $template->ID );
		}
		return FilterTemplateManager::create_filter_template( $preset_id );
	}

	private function normalize_preset_post( $raw ) {
		$raw = is_array( $raw ) ? $raw : [];
		$filters = is_array( $raw['filters'] ?? null ) ? $raw['filters'] : [];
		$raw['filters'] = array_values( array_filter( $filters, [ $this, 'is_meaningful_filter' ] ) );
		return $raw;
	}

	public function is_meaningful_filter( $filter ) {
		if ( ! is_array( $filter ) ) {
			return false;
		}
		if ( ! empty( $filter['enabled'] ) || ! empty( $filter['radio_show_all'] ) ) {
			return true;
		}
		foreach ( [ 'label', 'field_binding', 'field_binding_dynamic', 'key', 'resolved_key', 'query_var', 'options', 'default_value' ] as $field ) {
			if ( '' !== trim( (string) ( $filter[ $field ] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function current_preset_id() {
		return isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : '';
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
