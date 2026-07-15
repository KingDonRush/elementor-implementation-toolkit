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

	private function renderer() {
		if ( ! $this->renderer ) {
			$this->renderer = new EntryRenderer();
		}
		return $this->renderer;
	}
}
