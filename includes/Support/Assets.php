<?php
/**
 * Script and style registration.
 */

namespace EIT\Support;

use EIT\Admin\AdminPages;
use EIT\CPT\CptManager;
use EIT\Admin\CctItemAdmin;
use EIT\Elementor\FilterController\FilterTypeRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	public function init_hooks() {
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'elementor/frontend/after_register_scripts', [ $this, 'register_assets' ] );
		add_action( 'elementor/frontend/after_register_styles', [ $this, 'register_assets' ] );
	}

	public function register_assets() {
		wp_register_script(
			'eit-frontend',
			EIT_URL . 'assets/js/eit-frontend.js',
			[ 'jquery' ],
			EIT_VERSION,
			true
		);

		wp_register_style(
			'eit-frontend',
			EIT_URL . 'assets/css/eit-frontend.css',
			[],
			(string) filemtime( EIT_PATH . 'assets/css/eit-frontend.css' )
		);

		wp_register_script(
			'eit-editor',
			EIT_URL . 'assets/js/eit-editor.js',
			[ 'jquery', 'elementor-editor' ],
			EIT_VERSION,
			true
		);

		wp_register_style(
			'eit-editor',
			EIT_URL . 'assets/css/eit-editor.css',
			[],
			EIT_VERSION
		);

		wp_register_script(
			'eit-admin',
			EIT_URL . 'assets/js/eit-admin.js',
			[],
			EIT_VERSION,
			true
		);

		wp_register_style(
			'eit-admin',
			EIT_URL . 'assets/css/eit-admin.css',
			[],
			EIT_VERSION
		);

		wp_localize_script(
			'eit-frontend',
			'eitConfig',
			[
				'restUrl' => esc_url_raw( rest_url( 'eit/v1/filter' ) ),
				'entryRestUrl' => esc_url_raw( rest_url( 'eit/v1' ) ),
				'collectionRestUrl' => esc_url_raw( rest_url( 'eit/v1/collections' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => [
					'loading'    => __( 'Filtering...', 'elementor-implementation-toolkit' ),
					'empty'      => __( 'No matching items found.', 'elementor-implementation-toolkit' ),
					'error'      => __( 'Filters could not be updated. Your current results are still visible.', 'elementor-implementation-toolkit' ),
					'targetMissing' => __( 'The connected listing could not be found.', 'elementor-implementation-toolkit' ),
					'filter'     => __( 'Filter', 'elementor-implementation-toolkit' ),
					'page'       => __( 'Page', 'elementor-implementation-toolkit' ),
					'previous'   => __( 'Previous', 'elementor-implementation-toolkit' ),
					'next'       => __( 'Next', 'elementor-implementation-toolkit' ),
					'from'       => __( 'From', 'elementor-implementation-toolkit' ),
					'to'         => __( 'to', 'elementor-implementation-toolkit' ),
					'clear'      => __( 'Clear', 'elementor-implementation-toolkit' ),
					'all'        => __( 'All', 'elementor-implementation-toolkit' ),
					'items'      => __( 'items', 'elementor-implementation-toolkit' ),
					'entrySaving' => __( 'Saving…', 'elementor-implementation-toolkit' ),
					'entrySaved' => __( 'Your changes were saved.', 'elementor-implementation-toolkit' ),
					'entrySaveChanges' => __( 'Save changes', 'elementor-implementation-toolkit' ),
					'entryAutosaved' => __( 'Draft autosaved.', 'elementor-implementation-toolkit' ),
					'entryError' => __( 'Your changes are still in the form. Review the error and try again.', 'elementor-implementation-toolkit' ),
					'entryUploading' => __( 'Uploading media…', 'elementor-implementation-toolkit' ),
					'entryCalculationWaiting' => __( 'Complete the number fields to calculate.', 'elementor-implementation-toolkit' ),
				],
			]
		);

		wp_localize_script(
			'eit-editor',
			'eitEditorConfig',
			[
				'restUrl'          => esc_url_raw( rest_url( 'eit/v1/' ) ),
				'presetSaveUrl'    => esc_url_raw( rest_url( 'eit/v1/filter-presets' ) ),
				'restNonce'        => wp_create_nonce( 'wp_rest' ),
				'canManagePresets' => current_user_can( AdminPages::CAPABILITY ),
				'filterTypes'      => FilterTypeRegistry::editor_metadata(),
				'i18n'             => [
					'detectedTargets'     => __( 'Detected listings', 'elementor-implementation-toolkit' ),
					'noTargets'           => __( 'No listings detected on this canvas yet.', 'elementor-implementation-toolkit' ),
					'useTarget'           => __( 'Use this listing', 'elementor-implementation-toolkit' ),
					'fallback'            => __( 'Manual selector remains available for difficult cases.', 'elementor-implementation-toolkit' ),
					'presetNameRequired'   => __( 'Add a preset name before saving.', 'elementor-implementation-toolkit' ),
					'presetSaving'         => __( 'Saving preset...', 'elementor-implementation-toolkit' ),
					'presetSaved'          => __( 'Preset saved.', 'elementor-implementation-toolkit' ),
					'presetSaveFailed'     => __( 'Could not save preset.', 'elementor-implementation-toolkit' ),
					'presetSelectRequired' => __( 'Select a preset first.', 'elementor-implementation-toolkit' ),
					'presetImportConfirm'  => __( 'Importing this preset will replace the current local widget filter controls. Continue?', 'elementor-implementation-toolkit' ),
					'presetImporting'           => __( 'Importing preset...', 'elementor-implementation-toolkit' ),
					'presetImported'            => __( 'Preset imported as local widget controls.', 'elementor-implementation-toolkit' ),
					'presetImportFailed'        => __( 'Could not import preset.', 'elementor-implementation-toolkit' ),
					'editorCompatFallbackTitle' => __( 'Compatibility fallback active', 'elementor-implementation-toolkit' ),
					'editorCompatFallback'      => __( 'Elementor did not refresh this panel natively, so the toolkit applied its editor fallback. The frontend output remains controlled by the widget settings.', 'elementor-implementation-toolkit' ),
				],
			]
		);
	}

	public function enqueue_editor_assets() {
		$this->register_assets();
		wp_enqueue_script( 'eit-editor' );
		wp_enqueue_style( 'eit-editor' );
	}

	public function enqueue_admin_assets( $hook_suffix ) {
		$is_toolkit_page = false !== strpos( (string) $hook_suffix, 'eit-' ) || false !== strpos( (string) $hook_suffix, 'implementation-toolkit' );
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_systems_screen = $screen && 'toplevel_page_' . AdminPages::DASHBOARD_SLUG === $screen->id;
		$is_managed_cpt_screen = $screen && ! empty( $screen->post_type ) && array_key_exists( $screen->post_type, CptManager::all() );
		$is_cct_screen = false !== strpos( (string) $hook_suffix, CctItemAdmin::PAGE_PREFIX );

		if ( ! $is_toolkit_page && ! $is_managed_cpt_screen && ! $is_cct_screen ) {
			return;
		}

		$this->register_assets();
		if ( $is_toolkit_page || $is_cct_screen || $is_managed_cpt_screen ) {
			wp_enqueue_script( 'eit-admin' );
		}
		if ( $is_cct_screen || $is_managed_cpt_screen ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'eit-admin' );
		if ( $is_systems_screen ) {
			$this->enqueue_systems_assets();
		}
	}

	private function enqueue_systems_assets() {
		wp_register_script(
			'eit-systems',
			EIT_URL . 'assets/js/eit-systems.js',
			[ 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ],
			EIT_VERSION,
			true
		);
		wp_register_style(
			'eit-systems',
			EIT_URL . 'assets/css/eit-systems.css',
			[ 'wp-components' ],
			EIT_VERSION
		);
		wp_localize_script(
			'eit-systems',
			'eitSystemsConfig',
			[
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'restRoot' => '/eit/v1',
				'version' => EIT_VERSION,
			]
		);
		wp_set_script_translations( 'eit-systems', 'elementor-implementation-toolkit', EIT_PATH . 'languages' );
		wp_enqueue_script( 'eit-systems' );
		wp_enqueue_style( 'eit-systems' );
	}
}
