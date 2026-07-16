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
	private $pending;
	private $media_policy;

	public function __construct( EntrySurfaceResolver $resolver = null, EntryPolicyEngine $policy = null, GuestIntakeGuard $guard = null, PendingUploadStore $pending = null, EntryMediaPolicy $media_policy = null ) {
		$this->resolver = $resolver ?: new EntrySurfaceResolver();
		$this->policy = $policy ?: new EntryPolicyEngine();
		$this->guard = $guard ?: new GuestIntakeGuard();
		$this->pending = $pending ?: new PendingUploadStore();
		$this->media_policy = $media_policy ?: new EntryMediaPolicy();
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
		$original_name = sanitize_file_name( $file['name'] ?? '' );
		if ( $guest ) {
			return $this->pending->quarantine(
				[
					'blueprint_id' => $contract['blueprint_id'],
					'version_id' => $contract['version_id'],
					'contract_checksum' => $contract['artifact_checksum'],
					'surface_id' => $contract['surface_id'],
					'field_id' => $field['id'],
					'actor_key' => $this->guard->actor_key(),
				],
				[
					'tmp_name' => $file['tmp_name'],
					'original_name' => $original_name,
					'mime_type' => $checked['type'],
					'extension' => $checked['ext'],
					'size_bytes' => $file['size'] ?? 0,
				]
			);
		}
		$file['name'] = $this->pending_filename( $checked['ext'] ?? '' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$_FILES['eit_entry_file'] = $file;
		$attachment_id = media_handle_upload(
			'eit_entry_file',
			0,
			[ 'post_status' => 'private', 'post_title' => pathinfo( $original_name, PATHINFO_FILENAME ) ],
			[ 'test_form' => false ]
		);
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
			'name' => get_the_title( $attachment_id ),
			'mime' => get_post_mime_type( $attachment_id ),
			'url' => wp_get_attachment_url( $attachment_id ),
		];
	}

	private function validate_file( array $contract, array $field, array $file, $guest ) {
		return $this->media_policy->validate_upload( $contract, $field, $file, $guest );
	}

	private function field_accepts( array $field, $mime_type, $extension ) {
		return $this->media_policy->field_accepts( $field, $mime_type, $extension );
	}

	private function pending_filename( $extension ) {
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			$token = wp_generate_password( 32, false, false );
		}
		$extension = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $extension );
		return 'eit-pending-' . strtolower( $token ) . ( $extension ? '.' . strtolower( $extension ) : '' );
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
