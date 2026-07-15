<?php
/**
 * Controlled media upload for authenticated and explicitly enabled guest Surfaces.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryMediaService {

	private $resolver;
	private $policy;
	private $guard;

	public function __construct( EntrySurfaceResolver $resolver = null, EntryPolicyEngine $policy = null, GuestIntakeGuard $guard = null ) {
		$this->resolver = $resolver ?: new EntrySurfaceResolver();
		$this->policy = $policy ?: new EntryPolicyEngine();
		$this->guard = $guard ?: new GuestIntakeGuard();
	}

	public function upload( array $request, array $file ) {
		$contract = $this->resolver->get( $request['surface_id'] ?? '' );
		if ( ! $contract ) {
			return new \WP_Error( 'eit_entry_surface_not_found', __( 'Entry Surface was not found.', 'elementor-implementation-toolkit' ), [ 'status' => 404 ] );
		}
		$field = $this->field( $contract, $request['field_id'] ?? '' );
		if ( ! $field || ! in_array( $field['type'], [ 'image', 'gallery', 'file' ], true ) ) {
			return $this->error( 'eit_entry_upload_field_invalid', 'This Surface field does not accept media.', 400 );
		}
		$authorized = $this->policy->authorize( $contract, absint( $request['item_id'] ?? 0 ) ? 'update' : 'create', absint( $request['item_id'] ?? 0 ) );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		$guest = ! is_user_logged_in();
		if ( $guest ) {
			$guarded = $this->guard->verify( $contract, $request, false );
			if ( is_wp_error( $guarded ) ) {
				return $guarded;
			}
			$limited = $this->guard->consume_upload( $contract );
			if ( is_wp_error( $limited ) ) {
				return $limited;
			}
		} elseif ( ! current_user_can( 'upload_files' ) ) {
			return $this->error( 'eit_entry_upload_forbidden', 'Your account cannot upload media.', 403 );
		}
		$checked = $this->validate_file( $contract, $field, $file, $guest );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$_FILES['eit_entry_file'] = $file;
		$attachment_id = media_handle_upload( 'eit_entry_file', 0, [], [ 'test_form' => false ] );
		unset( $_FILES['eit_entry_file'] );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		update_post_meta( $attachment_id, '_eit_entry_pending_surface', $contract['surface_id'] );
		update_post_meta( $attachment_id, '_eit_entry_pending_actor', $this->guard->actor_key() );
		update_post_meta( $attachment_id, '_eit_entry_pending_at', time() );
		wp_schedule_single_event( time() + DAY_IN_SECONDS, 'eit_cleanup_entry_upload', [ $attachment_id, $contract['surface_id'] ] );
		return [
			'id' => $attachment_id,
			'url' => wp_get_attachment_url( $attachment_id ),
			'name' => get_the_title( $attachment_id ),
			'mime' => get_post_mime_type( $attachment_id ),
		];
	}

	private function validate_file( array $contract, array $field, array $file, $guest ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return $this->error( 'eit_entry_upload_missing', 'Choose a valid file to upload.', 400 );
		}
		$max_bytes = $guest ? absint( $contract['guest']['upload_max_bytes'] ?? 0 ) : (int) wp_max_upload_size();
		if ( $max_bytes < 1 || (int) ( $file['size'] ?? 0 ) > $max_bytes ) {
			return $this->error( 'eit_entry_upload_size', 'The upload exceeds this Surface size limit.', 413 );
		}
		$allowed = $guest ? array_filter( $contract['guest']['upload_mime_types'] ?? [] ) : array_values( get_allowed_mime_types() );
		$checked = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ?? '' ) );
		$needs_image = in_array( $field['type'], [ 'image', 'gallery' ], true );
		if ( empty( $checked['type'] ) || ! in_array( $checked['type'], $allowed, true ) || ( $needs_image && 0 !== strpos( $checked['type'], 'image/' ) ) ) {
			return $this->error( 'eit_entry_upload_type', 'This file type is not allowed by the Entry Surface.', 415 );
		}
		return true;
	}

	private function field( array $contract, $field_id ) {
		foreach ( $contract['fields'] ?? [] as $field ) {
			if ( (string) $field_id === ( $field['id'] ?? '' ) ) {
				return $field;
			}
		}
		return null;
	}

	private function error( $code, $message, $status ) {
		return new \WP_Error( $code, __( $message, 'elementor-implementation-toolkit' ), [ 'status' => $status ] ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
