<?php
/**
 * Central media policy shared by upload admission and pending-token replay.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryMediaPolicy {

	public function validate_upload( array $contract, array $field, array $file, $guest ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return $this->error( 'eit_entry_upload_missing', 'Choose a valid file to upload.', 400 );
		}
		$max_bytes = $this->max_bytes( $contract, $guest );
		if ( $max_bytes < 1 || (int) ( $file['size'] ?? 0 ) > $max_bytes ) {
			return $this->error( 'eit_entry_upload_size', 'The upload exceeds this Surface size limit.', 413 );
		}
		$checked = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ?? '' ) );
		if ( ! $this->accepts( $contract, $field, $checked['type'] ?? '', $checked['ext'] ?? '', $guest ) ) {
			return $this->error( 'eit_entry_upload_type', 'This file type is not allowed by the Entry Surface.', 415 );
		}
		return [ 'type' => sanitize_mime_type( $checked['type'] ), 'ext' => sanitize_key( $checked['ext'] ) ];
	}

	public function validate_pending( array $contract, array $field, array $record ) {
		$extension = strtolower( (string) pathinfo( $record['original_name'] ?? '', PATHINFO_EXTENSION ) );
		$max_bytes = $this->max_bytes( $contract, true );
		if ( $max_bytes < 1 || absint( $record['size_bytes'] ?? 0 ) > $max_bytes ) {
			return $this->error( 'eit_entry_pending_policy_changed', 'The pending upload no longer satisfies the active Surface size policy.', 409 );
		}
		if ( ! $this->accepts( $contract, $field, $record['mime_type'] ?? '', $extension, true ) ) {
			return $this->error( 'eit_entry_pending_policy_changed', 'The pending upload no longer satisfies the active Surface type policy.', 409 );
		}
		return true;
	}

	public function field_accepts( array $field, $mime_type, $extension ) {
		$mime_type = strtolower( sanitize_mime_type( $mime_type ) );
		$extension = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $extension ) );
		if ( in_array( $field['type'] ?? '', [ 'image', 'gallery' ], true ) ) {
			return str_starts_with( $mime_type, 'image/' );
		}
		$accept = $field['validation']['accept'] ?? '';
		$tokens = is_array( $accept ) ? $accept : explode( ',', (string) $accept );
		$tokens = array_values( array_filter( array_map( fn( $token ) => strtolower( trim( (string) $token ) ), $tokens ) ) );
		if ( ! $tokens ) {
			return true;
		}
		foreach ( $tokens as $token ) {
			if ( $mime_type === $token || '.' . $extension === $token || ( str_ends_with( $token, '/*' ) && str_starts_with( $mime_type, substr( $token, 0, -1 ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function accepts( array $contract, array $field, $mime_type, $extension, $guest ) {
		$mime_type = sanitize_mime_type( $mime_type );
		$allowed = $guest ? array_filter( $contract['guest']['upload_mime_types'] ?? [] ) : array_values( get_allowed_mime_types() );
		return '' !== $mime_type
			&& '' !== (string) $extension
			&& in_array( $mime_type, $allowed, true )
			&& $this->field_accepts( $field, $mime_type, $extension );
	}

	private function max_bytes( array $contract, $guest ) {
		return $guest ? absint( $contract['guest']['upload_max_bytes'] ?? 0 ) : (int) wp_max_upload_size();
	}

	private function error( $code, $message, $status ) {
		return new \WP_Error( $code, __( $message, 'elementor-implementation-toolkit' ), [ 'status' => $status ] ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
