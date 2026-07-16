<?php
/**
 * Owns protected and public filesystem paths for pending Entry uploads.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PendingUploadFiles {

	const MARKER = 'eit-private-upload-storage-v1';

	private $directory;
	private $mover;

	public function __construct( $directory = '', callable $mover = null ) {
		$this->directory = trim( (string) $directory );
		$this->mover = $mover ?: static fn( $source, $destination ) => move_uploaded_file( $source, $destination );
	}

	public function quarantine( $source, $storage_name ) {
		$directory = $this->protected_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$destination = $directory . DIRECTORY_SEPARATOR . $storage_name;
		if ( ! call_user_func( $this->mover, (string) $source, $destination ) || ! is_file( $destination ) || is_link( $destination ) || ! $this->inside( $destination, $directory ) ) {
			return $this->error( 'eit_entry_pending_move_failed', 'The upload could not enter protected pending storage.' );
		}
		@chmod( $destination, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Containment is the hard gate; restrictive permissions are best-effort.
		return [ 'path' => $destination, 'checksum' => hash_file( 'sha256', $destination ) ];
	}

	public function source_path( array $record ) {
		$directory = $this->protected_directory();
		if ( is_wp_error( $directory ) || basename( (string) ( $record['storage_name'] ?? '' ) ) !== (string) ( $record['storage_name'] ?? '' ) ) {
			return $this->error( 'eit_entry_pending_path_invalid', 'The protected pending path is invalid.' );
		}
		return $directory . DIRECTORY_SEPARATOR . $record['storage_name'];
	}

	public function destination( array $record ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return $this->error( 'eit_entry_pending_uploads_unavailable', 'WordPress media storage is unavailable.' );
		}
		$extension = preg_replace( '/[^a-zA-Z0-9]/', '', (string) pathinfo( $record['original_name'] ?? '', PATHINFO_EXTENSION ) );
		$filename = 'eit-pending-' . str_replace( '-', '', (string) $record['id'] ) . ( $extension ? '.' . strtolower( $extension ) : '' );
		$relative = (string) ( $record['promoted_path'] ?? '' );
		if ( '' === $relative ) {
			$relative = ltrim( trailingslashit( $uploads['subdir'] ?? '' ) . $filename, '/\\' );
		}
		$relative = str_replace( '\\', '/', $relative );
		if ( '' === $relative || str_contains( $relative, '../' ) || basename( $relative ) !== $filename ) {
			return $this->error( 'eit_entry_pending_destination_invalid', 'The pending media destination is invalid.' );
		}
		$absolute = trailingslashit( $uploads['basedir'] ) . $relative;
		$parent = dirname( $absolute );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			return $this->error( 'eit_entry_pending_destination_failed', 'The pending media destination could not be created.' );
		}
		if ( ! $this->inside( $parent, $uploads['basedir'] ) ) {
			return $this->error( 'eit_entry_pending_destination_invalid', 'The pending media destination escaped WordPress uploads.' );
		}
		return [ 'relative' => $relative, 'absolute' => $absolute, 'partial' => $absolute . '.eit-partial' ];
	}

	public function materialize( array $record, array $destination ) {
		$source = $this->source_path( $record );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( is_file( $destination['absolute'] ) ) {
			return $this->matches( $destination['absolute'], $record )
				? true
				: $this->error( 'eit_entry_pending_destination_conflict', 'The pending media destination has unexpected content.' );
		}
		if ( ! is_file( $source ) || ! $this->matches( $source, $record ) ) {
			return $this->error( 'eit_entry_pending_file_missing', 'The protected pending file is unavailable or changed.' );
		}
		if ( is_file( $destination['partial'] ) ) {
			wp_delete_file( $destination['partial'] );
		}
		if ( ! copy( $source, $destination['partial'] ) || ! $this->matches( $destination['partial'], $record ) || ! rename( $destination['partial'], $destination['absolute'] ) ) {
			if ( is_file( $destination['partial'] ) ) {
				wp_delete_file( $destination['partial'] );
			}
			return $this->error( 'eit_entry_pending_promote_failed', 'The protected pending file could not be promoted safely.' );
		}
		@chmod( $destination['absolute'], 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- WordPress hosting permissions vary.
		return true;
	}

	public function delete_source( array $record ) {
		$source = $this->source_path( $record );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( is_file( $source ) ) {
			wp_delete_file( $source );
		}
		return ! is_file( $source ) ? true : $this->error( 'eit_entry_pending_source_delete_failed', 'The protected pending source could not be removed.' );
	}

	public function release_attachment( $attachment_id ) {
		foreach ( [ '_eit_entry_pending_upload_id', '_eit_entry_pending_surface', '_eit_entry_pending_actor', '_eit_entry_pending_at' ] as $meta_key ) {
			delete_post_meta( absint( $attachment_id ), $meta_key );
		}
	}

	public function delete_unconsumed( array $record ) {
		$attachment_id = absint( $record['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			$attachment = get_page_by_path( 'eit-pending-' . str_replace( '-', '', (string) $record['id'] ), OBJECT, 'attachment' );
			$attachment_id = $attachment ? (int) $attachment->ID : 0;
		}
		$attachment_owner = $attachment_id ? (string) get_post_meta( $attachment_id, '_eit_entry_pending_upload_id', true ) : '';
		if ( $attachment_id && '' !== $attachment_owner && ! hash_equals( (string) $record['id'], $attachment_owner ) ) {
			return $this->error( 'eit_entry_pending_attachment_conflict', 'Expired pending media ownership is inconsistent.' );
		}
		if ( $attachment_id && ( '' === $attachment_owner || hash_equals( (string) $record['id'], $attachment_owner ) ) ) {
			wp_delete_attachment( $attachment_id, true );
			if ( 'attachment' === get_post_type( $attachment_id ) ) {
				return $this->error( 'eit_entry_pending_attachment_delete_failed', 'Expired pending media could not be removed.' );
			}
		}
		if ( empty( $record['promoted_path'] ) ) {
			return $this->delete_source( $record );
		}
		$destination = $this->destination( $record );
		if ( is_wp_error( $destination ) ) {
			return $destination;
		}
		$derivatives = glob( dirname( $destination['absolute'] ) . DIRECTORY_SEPARATOR . pathinfo( $destination['absolute'], PATHINFO_FILENAME ) . '-*' );
		foreach ( is_array( $derivatives ) ? $derivatives : [] as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				wp_delete_file( $path );
			}
			if ( is_file( $path ) || is_link( $path ) ) {
				return $this->error( 'eit_entry_pending_derivative_delete_failed', 'Expired pending image derivatives could not be removed.' );
			}
		}
		foreach ( [ $destination['partial'], $destination['absolute'] ] as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			if ( is_file( $path ) ) {
				return $this->error( 'eit_entry_pending_file_delete_failed', 'Expired pending file bytes could not be removed.' );
			}
		}
		return $this->delete_source( $record );
	}

	public function protected_directory() {
		// Freeze the first accepted location so configuration drift cannot orphan pending bytes.
		$registered = $this->directory ? '' : (string) get_option( 'eit_private_upload_directory_path', '' );
		$candidate = $registered ?: (string) apply_filters( 'eit_private_upload_directory', $this->directory ?: $this->default_directory() );
		$created = ! is_dir( $candidate );
		if ( $created && ! wp_mkdir_p( $candidate ) ) {
			return $this->error( 'eit_entry_pending_directory_failed', 'Protected pending storage could not be created.' );
		}
		$directory = realpath( $candidate );
		$uploads = wp_upload_dir();
		$document_root = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ?? '' ) );
		if ( false === $directory || ! is_writable( $directory ) || $this->inside( $directory, ABSPATH ) || ( $document_root && $this->inside( $directory, $document_root ) ) || ( empty( $uploads['error'] ) && $this->inside( $directory, $uploads['basedir'] ) ) ) {
			return $this->error( 'eit_entry_pending_directory_public', 'Pending upload storage must be writable and outside public server paths.' );
		}
		$marker = $directory . DIRECTORY_SEPARATOR . '.eit-owned';
		$entries = array_values( array_diff( scandir( $directory ) ?: [], [ '.', '..' ] ) );
		if ( ! is_file( $marker ) && ! $created && $entries ) {
			return $this->error( 'eit_entry_pending_directory_unowned', 'Pending upload storage must be an empty or Toolkit-owned directory.' );
		}
		if ( ! is_file( $marker ) && false === file_put_contents( $marker, self::MARKER, LOCK_EX ) ) {
			return $this->error( 'eit_entry_pending_directory_failed', 'Protected pending storage ownership could not be recorded.' );
		}
		if ( ! hash_equals( self::MARKER, trim( (string) file_get_contents( $marker ) ) ) ) {
			return $this->error( 'eit_entry_pending_directory_unowned', 'Pending upload storage ownership is invalid.' );
		}
		@chmod( $directory, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Path containment remains the hard gate.
		if ( ! update_option( 'eit_private_upload_directory_path', $directory, false ) && $directory !== get_option( 'eit_private_upload_directory_path' ) ) {
			return $this->error( 'eit_entry_pending_directory_record_failed', 'Protected pending storage could not be registered for safe uninstall.' );
		}
		return $directory;
	}

	private function matches( $path, array $record ) {
		return is_file( $path ) && ! is_link( $path ) && absint( filesize( $path ) ) === absint( $record['size_bytes'] ?? 0 ) && hash_equals( (string) ( $record['file_checksum'] ?? '' ), (string) hash_file( 'sha256', $path ) );
	}

	private function default_directory() {
		global $wpdb;

		$scope = substr( hash( 'sha256', wp_normalize_path( ABSPATH ) . '|' . (string) $wpdb->prefix ), 0, 16 );
		return trailingslashit( get_temp_dir() ) . 'eit-private-uploads-' . $scope;
	}

	private function inside( $path, $parent ) {
		$path = str_replace( '\\', '/', rtrim( (string) realpath( $path ), '/\\' ) );
		$parent = str_replace( '\\', '/', rtrim( (string) realpath( $parent ), '/\\' ) );
		return '' !== $parent && ( $path === $parent || str_starts_with( $path . '/', $parent . '/' ) );
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, __( $message, 'elementor-implementation-toolkit' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
