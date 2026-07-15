<?php
/**
 * WordPress-native entry points for Systems, Runs, Diagnostics and Settings.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPages {

	const CAPABILITY = 'manage_options';
	const DASHBOARD_SLUG = 'eit-toolkit';
	const RUNS_SLUG = 'eit-runs';
	const DIAGNOSTICS_SLUG = 'eit-diagnostics';
	const INTEGRATIONS_SLUG = 'eit-integrations';
	const FILTERS_SLUG = 'eit-filter-presets';
	const CPT_SLUG = 'eit-cpt-manager';
	const CCT_SLUG = 'eit-content-types';
	const ENTRY_RECOVERY_SLUG = 'eit-entry-recovery';

	private $renderer;
	private $views;
	private $filter_preset_admin;
	private $cpt_manager_admin;
	private $cct_definition_admin;
	private $cct_item_admin;
	private $entry_recovery_admin;

	public function init_hooks() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_' . FilterPresetAdmin::SAVE_ACTION, [ $this->filter_preset_admin(), 'handle_save' ] );
		add_action( 'admin_post_' . FilterPresetAdmin::DELETE_ACTION, [ $this->filter_preset_admin(), 'handle_delete' ] );
		add_action( 'admin_post_' . FilterPresetAdmin::DUPLICATE_ACTION, [ $this->filter_preset_admin(), 'handle_duplicate' ] );
		add_action( 'admin_post_' . FilterPresetAdmin::CREATE_TEMPLATE_ACTION, [ $this->filter_preset_admin(), 'handle_create_template' ] );
		add_action( 'admin_post_' . FilterPresetAdmin::DELETE_TEMPLATE_ACTION, [ $this->filter_preset_admin(), 'handle_delete_template' ] );
		add_action( 'admin_post_' . CptManagerAdmin::SAVE_ACTION, [ $this->cpt_manager_admin(), 'handle_save' ] );
		add_action( 'admin_post_' . CptManagerAdmin::DELETE_ACTION, [ $this->cpt_manager_admin(), 'handle_delete' ] );
		add_action( 'admin_post_' . CptManagerAdmin::DUPLICATE_ACTION, [ $this->cpt_manager_admin(), 'handle_duplicate' ] );
		add_action( 'admin_post_' . CctDefinitionAdmin::SAVE_ACTION, [ $this->cct_definition_admin(), 'handle_save' ] );
		add_action( 'admin_post_' . CctDefinitionAdmin::ARCHIVE_ACTION, [ $this->cct_definition_admin(), 'handle_archive' ] );
		add_action( 'admin_post_' . CctDefinitionAdmin::RESTORE_ACTION, [ $this->cct_definition_admin(), 'handle_restore' ] );
		add_action( 'admin_post_' . CctDefinitionAdmin::DELETE_ACTION, [ $this->cct_definition_admin(), 'handle_delete' ] );
		add_action( 'admin_post_' . CctItemAdmin::SAVE_ACTION, [ $this->cct_item_admin(), 'handle_save' ] );
		add_action( 'admin_post_' . CctItemAdmin::DELETE_ACTION, [ $this->cct_item_admin(), 'handle_delete' ] );
		add_action( 'admin_menu', [ $this->cct_item_admin(), 'register_menus' ], 20 );
		add_action( 'admin_post_' . EntryRecoveryAdmin::RETRY_ACTION, [ $this->entry_recovery_admin(), 'handle_retry' ] );
	}

	public function register_menu() {
		add_menu_page( __( 'Implementation Toolkit', 'elementor-implementation-toolkit' ), __( 'Implementation Toolkit', 'elementor-implementation-toolkit' ), self::CAPABILITY, self::DASHBOARD_SLUG, [ $this, 'render_systems' ], 'dashicons-superhero', 58 );
		$this->submenu( self::DASHBOARD_SLUG, __( 'Systems', 'elementor-implementation-toolkit' ), [ $this, 'render_systems' ] );
		$this->submenu( self::RUNS_SLUG, __( 'Runs', 'elementor-implementation-toolkit' ), [ $this, 'render_runs' ] );
		$this->submenu( self::DIAGNOSTICS_SLUG, __( 'Diagnostics', 'elementor-implementation-toolkit' ), [ $this, 'render_diagnostics' ] );
		$this->submenu( self::INTEGRATIONS_SLUG, __( 'Settings', 'elementor-implementation-toolkit' ), [ $this, 'render_settings' ] );

		add_submenu_page( null, __( 'Filter Presets', 'elementor-implementation-toolkit' ), __( 'Filter Presets', 'elementor-implementation-toolkit' ), self::CAPABILITY, self::FILTERS_SLUG, [ $this, 'render_filters' ] );
		add_submenu_page( null, __( 'Legacy Post Types', 'elementor-implementation-toolkit' ), __( 'Legacy Post Types', 'elementor-implementation-toolkit' ), self::CAPABILITY, self::CPT_SLUG, [ $this, 'render_cpts' ] );
		add_submenu_page( null, __( 'Legacy Content Types', 'elementor-implementation-toolkit' ), __( 'Legacy Content Types', 'elementor-implementation-toolkit' ), self::CAPABILITY, self::CCT_SLUG, [ $this, 'render_ccts' ] );
		add_submenu_page( null, __( 'Entry Recovery', 'elementor-implementation-toolkit' ), __( 'Entry Recovery', 'elementor-implementation-toolkit' ), self::CAPABILITY, self::ENTRY_RECOVERY_SLUG, [ $this, 'render_entry_recovery' ] );
	}

	public function render_systems() {
		$this->shell( self::DASHBOARD_SLUG, __( 'Systems', 'elementor-implementation-toolkit' ), __( 'Build executable contracts first; runtime changes happen only after impact review and confirmation.', 'elementor-implementation-toolkit' ), [ $this->views(), 'render_systems_mount' ] );
	}

	public function render_runs() {
		$this->shell( self::RUNS_SLUG, __( 'Runs', 'elementor-implementation-toolkit' ), __( 'Trace governed execution without exposing secrets or complete content payloads.', 'elementor-implementation-toolkit' ), [ $this->views(), 'render_runs' ] );
	}

	public function render_diagnostics() {
		$this->shell( self::DIAGNOSTICS_SLUG, __( 'Diagnostics', 'elementor-implementation-toolkit' ), __( 'Health is reported from schema checks and registered extension contracts, never from decorative status.', 'elementor-implementation-toolkit' ), [ $this->views(), 'render_diagnostics' ] );
	}

	public function render_settings() {
		$this->shell( self::INTEGRATIONS_SLUG, __( 'Settings', 'elementor-implementation-toolkit' ), __( 'Review product boundaries and reach legacy recovery surfaces during the 1.x migration.', 'elementor-implementation-toolkit' ), [ $this->views(), 'render_settings' ] );
	}

	public function render_filters() {
		$this->filter_preset_admin()->render( self::FILTERS_SLUG, $this->tabs() );
	}

	public function render_cpts() {
		$this->cpt_manager_admin()->render( self::CPT_SLUG, $this->tabs() );
	}

	public function render_ccts() {
		$this->cct_definition_admin()->render( self::CCT_SLUG, $this->tabs() );
	}

	public function render_entry_recovery() {
		$this->shell( self::INTEGRATIONS_SLUG, __( 'Entry Recovery', 'elementor-implementation-toolkit' ), __( 'Retry failed side effects without recreating or editing the content mutation that already succeeded.', 'elementor-implementation-toolkit' ), [ $this->entry_recovery_admin(), 'render' ] );
	}

	private function submenu( $slug, $label, $callback ) {
		add_submenu_page( self::DASHBOARD_SLUG, $label, $label, self::CAPABILITY, $slug, $callback );
	}

	private function shell( $slug, $title, $description, $content ) {
		$this->renderer()->render_shell( $slug, $this->tabs(), [ 'title' => $title, 'description' => $description ], $content );
	}

	private function tabs() {
		return [
			self::DASHBOARD_SLUG => [ 'label' => __( 'Systems', 'elementor-implementation-toolkit' ) ],
			self::RUNS_SLUG => [ 'label' => __( 'Runs', 'elementor-implementation-toolkit' ) ],
			self::DIAGNOSTICS_SLUG => [ 'label' => __( 'Diagnostics', 'elementor-implementation-toolkit' ) ],
			self::INTEGRATIONS_SLUG => [ 'label' => __( 'Settings', 'elementor-implementation-toolkit' ) ],
		];
	}

	private function renderer() {
		if ( ! $this->renderer ) {
			$this->renderer = new AdminRenderer();
		}
		return $this->renderer;
	}

	private function views() {
		if ( ! $this->views ) {
			$this->views = new BlueprintAdminViews();
		}
		return $this->views;
	}

	private function filter_preset_admin() {
		if ( ! $this->filter_preset_admin ) {
			$this->filter_preset_admin = new FilterPresetAdmin( $this->renderer() );
		}
		return $this->filter_preset_admin;
	}

	private function cpt_manager_admin() {
		if ( ! $this->cpt_manager_admin ) {
			$this->cpt_manager_admin = new CptManagerAdmin( $this->renderer() );
		}
		return $this->cpt_manager_admin;
	}

	private function cct_definition_admin() {
		if ( ! $this->cct_definition_admin ) {
			$this->cct_definition_admin = new CctDefinitionAdmin( $this->renderer() );
		}
		return $this->cct_definition_admin;
	}

	private function cct_item_admin() {
		if ( ! $this->cct_item_admin ) {
			$this->cct_item_admin = new CctItemAdmin();
		}
		return $this->cct_item_admin;
	}

	private function entry_recovery_admin() {
		if ( ! $this->entry_recovery_admin ) {
			$this->entry_recovery_admin = new EntryRecoveryAdmin();
		}
		return $this->entry_recovery_admin;
	}
}
