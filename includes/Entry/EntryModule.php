<?php
/**
 * Boots frontend Entry workspaces, REST endpoints and retry hooks.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryModule {

	private $renderer;

	public function init_hooks() {
		add_action( 'init', [ $this, 'register_runtime' ] );
		add_action( 'eit_retry_entry_action', [ $this, 'retry_action' ] );
		add_action( 'eit_cleanup_entry_upload', [ $this, 'cleanup_upload' ], 10, 2 );
		add_action( 'eit_cleanup_entry_pending_upload', [ $this, 'cleanup_pending_upload' ], 10, 2 );
		add_action( PendingUploadStore::SWEEP_HOOK, [ $this, 'sweep_pending_uploads' ] );
		add_action( 'eit_cleanup_entry_rate', [ $this, 'cleanup_rate' ] );
	}

	public function register_runtime() {
		register_post_status(
			'eit_archived',
			[
				'label' => _x( 'Archived', 'post status', 'elementor-implementation-toolkit' ),
				'public' => false,
				'internal' => true,
				'exclude_from_search' => true,
				'show_in_admin_all_list' => true,
				'show_in_admin_status_list' => true,
			]
		);
		add_shortcode( 'eit_entry_surface', [ $this->renderer(), 'shortcode' ] );
		$scheduled = ( new PendingUploadStore() )->ensure_sweeper();
		if ( is_wp_error( $scheduled ) ) {
			do_action( 'eit_pending_upload_sweeper_error', $scheduled );
		}
	}

	public function retry_action( $job_id ) {
		( new EntryActionDispatcher() )->retry( $job_id );
	}

	public function cleanup_upload( $attachment_id, $surface_id ) {
		$pending = (string) get_post_meta( absint( $attachment_id ), '_eit_entry_pending_surface', true );
		if ( $pending && hash_equals( (string) $surface_id, $pending ) ) {
			wp_delete_attachment( absint( $attachment_id ), true );
		}
	}

	public function cleanup_pending_upload( $pending_id, $surface_id ) {
		( new PendingUploadStore() )->cleanup( $pending_id, $surface_id );
	}

	public function sweep_pending_uploads() {
		( new PendingUploadStore() )->sweep();
	}

	public function cleanup_rate( $option_name ) {
		$option_name = (string) $option_name;
		if ( preg_match( '/^eit_entry_rate_\d{10}_[a-f0-9]{64}$/', $option_name ) ) {
			delete_option( $option_name );
		}
	}

	private function renderer() {
		if ( ! $this->renderer ) {
			$this->renderer = new EntryRenderer();
		}
		return $this->renderer;
	}
}
