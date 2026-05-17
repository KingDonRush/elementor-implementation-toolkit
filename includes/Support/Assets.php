<?php
/**
 * Script and style registration.
 */

namespace EIT\Support;

use EIT\CPT\CptManager;

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
			EIT_VERSION
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
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => [
					'loading'    => __( 'Filtering...', 'elementor-implementation-toolkit' ),
					'empty'      => __( 'No matching items found.', 'elementor-implementation-toolkit' ),
					'page'       => __( 'Page', 'elementor-implementation-toolkit' ),
					'previous'   => __( 'Previous', 'elementor-implementation-toolkit' ),
					'next'       => __( 'Next', 'elementor-implementation-toolkit' ),
					'clear'      => __( 'Clear', 'elementor-implementation-toolkit' ),
					'all'        => __( 'All', 'elementor-implementation-toolkit' ),
				],
			]
		);

		wp_localize_script(
			'eit-editor',
			'eitEditorConfig',
			[
				'i18n' => [
					'detectedTargets' => __( 'Detected listings', 'elementor-implementation-toolkit' ),
					'noTargets'       => __( 'No listings detected on this canvas yet.', 'elementor-implementation-toolkit' ),
					'useTarget'       => __( 'Use this listing', 'elementor-implementation-toolkit' ),
					'fallback'        => __( 'Manual selector remains available for difficult cases.', 'elementor-implementation-toolkit' ),
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
		$is_managed_cpt_screen = $screen && ! empty( $screen->post_type ) && array_key_exists( $screen->post_type, CptManager::all() );

		if ( ! $is_toolkit_page && ! $is_managed_cpt_screen ) {
			return;
		}

		$this->register_assets();
		if ( $is_toolkit_page ) {
			wp_enqueue_script( 'eit-admin' );
		}
		wp_enqueue_style( 'eit-admin' );
	}
}
