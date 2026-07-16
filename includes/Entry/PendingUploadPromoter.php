<?php
/**
 * Reconciles a claimed protected upload into one deterministic attachment.
 */

namespace EIT\Entry;

use EIT\Infrastructure\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PendingUploadPromoter {

	private $files;

	public function __construct( PendingUploadFiles $files ) {
		$this->files = $files;
	}

	public function promote( array $record ) {
		global $wpdb;

		if ( empty( $record['submission_id'] ) || ! in_array( $record['status'], [ 'claimed', 'promoting', 'promoted' ], true ) ) {
			return $this->error( 'eit_entry_pending_not_claimed', 'The pending upload has no active submission claim.' );
		}
		$attachment = $this->find_attachment( $record );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( $attachment ) {
			if ( 'claimed' === $record['status'] ) {
				return $this->error( 'eit_entry_pending_attachment_conflict', 'An attachment existed before pending promotion acquired its durable state.' );
			}
			$reconciled = $this->reconcile_attachment( $record, $attachment );
			return is_wp_error( $reconciled ) ? $reconciled : $this->finish( $record, $attachment );
		}
		$destination = $this->files->destination( $record );
		if ( is_wp_error( $destination ) ) {
			return $destination;
		}
		if ( 'promoting' !== $record['status'] || (string) $record['promoted_path'] !== $destination['relative'] ) {
			$updated = $wpdb->update(
				Tables::name( Tables::PENDING_UPLOADS ),
				[ 'status' => 'promoting', 'promoted_path' => $destination['relative'], 'last_error' => null, 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $record['id'], 'status' => $record['status'], 'submission_id' => $record['submission_id'] ]
			);
			if ( 1 !== $updated ) {
				return $this->error( 'eit_entry_pending_promote_claim_lost', 'The pending upload promotion claim was lost.' );
			}
			$record['status'] = 'promoting';
			$record['promoted_path'] = $destination['relative'];
		}
		$materialized = $this->files->materialize( $record, $destination );
		if ( is_wp_error( $materialized ) ) {
			$this->remember_error( $record['id'], $materialized );
			return $materialized;
		}
		$attachment = $this->create_attachment( $record, $destination );
		if ( is_wp_error( $attachment ) ) {
			$this->remember_error( $record['id'], $attachment );
			return $attachment;
		}
		return $this->finish( $record, $attachment );
	}

	private function finish( array $record, $attachment_id ) {
		global $wpdb;

		$updated = $wpdb->update(
			Tables::name( Tables::PENDING_UPLOADS ),
			[ 'status' => 'promoted', 'attachment_id' => absint( $attachment_id ), 'last_error' => null, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $record['id'], 'status' => $record['status'], 'submission_id' => $record['submission_id'] ]
		);
		if ( 1 !== $updated && ! $this->is_recorded( $record, $attachment_id ) ) {
			return $this->error( 'eit_entry_pending_promote_record_failed', 'The promoted media could not be recorded safely.' );
		}
		$removed = $this->files->delete_source( $record );
		if ( is_wp_error( $removed ) ) {
			$this->remember_error( $record['id'], $removed );
			return $removed;
		}
		return [ 'id' => absint( $attachment_id ), 'name' => get_the_title( $attachment_id ), 'mime' => get_post_mime_type( $attachment_id ) ];
	}

	private function find_attachment( array $record ) {
		$attachment_id = absint( $record['attachment_id'] ?? 0 );
		if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) && hash_equals( (string) $record['id'], (string) get_post_meta( $attachment_id, '_eit_entry_pending_upload_id', true ) ) ) {
			return $attachment_id;
		}
		$attachment = get_page_by_path( $this->attachment_slug( $record ), OBJECT, 'attachment' );
		if ( ! $attachment ) {
			return 0;
		}
		$owner = (string) get_post_meta( $attachment->ID, '_eit_entry_pending_upload_id', true );
		return '' === $owner || hash_equals( (string) $record['id'], $owner )
			? (int) $attachment->ID
			: $this->error( 'eit_entry_pending_attachment_conflict', 'The deterministic pending attachment belongs to another upload.' );
	}

	private function create_attachment( array $record, array $destination ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => $record['mime_type'],
				'post_title' => sanitize_text_field( pathinfo( $record['original_name'], PATHINFO_FILENAME ) ),
				'post_name' => $this->attachment_slug( $record ),
				'post_status' => 'private',
			],
			$destination['absolute'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		$reconciled = $this->reconcile_attachment( $record, $attachment_id, $destination );
		if ( is_wp_error( $reconciled ) ) {
			wp_delete_attachment( $attachment_id, true );
			return $reconciled;
		}
		return $attachment_id;
	}

	private function reconcile_attachment( array $record, $attachment_id, array $destination = [] ) {
		if ( ! $destination ) {
			$destination = $this->files->destination( $record );
			if ( is_wp_error( $destination ) ) {
				return $destination;
			}
		}
		$owner = (string) get_post_meta( $attachment_id, '_eit_entry_pending_upload_id', true );
		if ( 'private' !== get_post_status( $attachment_id ) || ( $owner && ! hash_equals( (string) $record['id'], $owner ) ) ) {
			return $this->error( 'eit_entry_pending_attachment_conflict', 'The deterministic pending attachment belongs to another upload.' );
		}
		update_post_meta( $attachment_id, '_eit_entry_pending_upload_id', $record['id'] );
		update_post_meta( $attachment_id, '_eit_entry_pending_surface', $record['surface_id'] );
		update_post_meta( $attachment_id, '_eit_entry_pending_actor', $record['actor_key'] );
		update_post_meta( $attachment_id, '_eit_entry_pending_at', time() );
		update_attached_file( $attachment_id, $destination['absolute'] );
		$attached_file = get_attached_file( $attachment_id, true );
		if ( ! hash_equals( (string) $record['id'], (string) get_post_meta( $attachment_id, '_eit_entry_pending_upload_id', true ) ) || ! $attached_file || ! hash_equals( wp_normalize_path( $destination['absolute'] ), wp_normalize_path( $attached_file ) ) ) {
			return $this->error( 'eit_entry_pending_attachment_record_failed', 'The pending attachment metadata could not be recorded safely.' );
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $destination['absolute'] );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
		return true;
	}

	private function remember_error( $id, \WP_Error $error ) {
		global $wpdb;

		$wpdb->update(
			Tables::name( Tables::PENDING_UPLOADS ),
			[ 'last_error' => sanitize_text_field( $error->get_error_code() ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ]
		);
	}

	private function is_recorded( array $record, $attachment_id ) {
		global $wpdb;

		$table = Tables::name( Tables::PENDING_UPLOADS );
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT status,submission_id,attachment_id FROM `{$table}` WHERE id = %s", $record['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $current
			&& 'promoted' === $current['status']
			&& hash_equals( (string) $record['submission_id'], (string) $current['submission_id'] )
			&& absint( $attachment_id ) === absint( $current['attachment_id'] );
	}

	private function attachment_slug( array $record ) {
		return 'eit-pending-' . str_replace( '-', '', (string) $record['id'] );
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, __( $message, 'elementor-implementation-toolkit' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
