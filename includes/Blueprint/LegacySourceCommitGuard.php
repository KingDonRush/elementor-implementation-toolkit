<?php
/**
 * Locks and rechecks raw legacy authority immediately before activation CAS.
 */

namespace EIT\Blueprint;

use EIT\CCT\DefinitionManager as CctDefinitions;
use EIT\CPT\DefinitionManager as CptDefinitions;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacySourceCommitGuard {

	private $snapshots;
	private $raw_reader;

	public function __construct( ?LegacyAuthoritySnapshot $snapshots = null, $raw_reader = null ) {
		$this->snapshots = $snapshots ?: new LegacyAuthoritySnapshot();
		$this->raw_reader = is_callable( $raw_reader ) ? $raw_reader : [ $this, 'read_and_lock' ];
	}

	public function validate_and_lock( array $change_set, array $blueprint ) {
		$snapshot = $change_set['compiled_artifacts']['legacy_authority'] ?? null;
		$imported = 'legacy_shadow' === ( $blueprint['draft_document']['origin']['mode'] ?? '' );
		if ( ! is_array( $snapshot ) ) {
			return $imported
				? $this->error( 'eit_legacy_authority_snapshot_missing', __( 'Imported Blueprint has no frozen legacy activation authority.', 'elementor-implementation-toolkit' ) )
				: true;
		}
		if ( ! $imported ) {
			return $this->error( 'eit_legacy_authority_snapshot_unexpected', __( 'Native Blueprint cannot consume legacy activation authority.', 'elementor-implementation-toolkit' ) );
		}

		$validated = $this->snapshots->validate( $snapshot );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if (
			(string) ( $change_set['blueprint_id'] ?? '' ) !== $validated['blueprint_id']
			|| (string) ( $blueprint['id'] ?? '' ) !== $validated['blueprint_id']
			|| ! $this->same_checksum( $validated['draft_checksum'], $change_set['draft_checksum'] ?? '' )
			|| ! $this->same_checksum( $validated['draft_checksum'], $blueprint['draft_checksum'] ?? '' )
		) {
			return $this->error( 'eit_legacy_authority_commit_scope_changed', __( 'Legacy activation scope changed before commit.', 'elementor-implementation-toolkit' ) );
		}
		$scope = $this->snapshots->validate_document_scope( $validated, $blueprint['draft_document'] ?? [] );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return $this->validate_snapshot_and_lock( $validated );
	}

	public function validate_snapshot_and_lock( array $snapshot ) {
		$validated = $this->snapshots->validate( $snapshot );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		try {
			$current = call_user_func( $this->raw_reader, $validated['source_type'], $validated['source_key'] );
		} catch ( \Throwable $error ) {
			return $this->error( 'eit_legacy_authority_commit_read_failed', __( 'Raw legacy source could not be locked for activation.', 'elementor-implementation-toolkit' ) );
		}
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! is_array( $current ) || empty( $current['available'] ) ) {
			return $this->error( 'eit_legacy_authority_source_missing', __( 'Frozen legacy source no longer exists.', 'elementor-implementation-toolkit' ) );
		}
		return $this->same_checksum( $validated['source_checksum'], $current['source_checksum'] ?? '' )
			? true
			: $this->error( 'eit_legacy_authority_source_changed', __( 'Raw legacy source changed before Blueprint activation committed.', 'elementor-implementation-toolkit' ) );
	}

	public function read_and_lock( $source_type, $source_key ) {
		$source_type = sanitize_key( $source_type );
		return 'elementor_document' === $source_type
			? $this->read_elementor_document( absint( $source_key ) )
			: $this->read_option_source( $source_type, $source_key );
	}

	private function read_option_source( $source_type, $source_key ) {
		global $wpdb;

		$options = [
			'cpt' => CptDefinitions::OPTION,
			'cct' => CctDefinitions::OPTION,
			'filter_preset' => FilterPresets::OPTION,
		];
		if ( ! isset( $options[ $source_type ] ) ) {
			return $this->error( 'eit_legacy_authority_source_unsupported', __( 'Legacy source type cannot be locked safely.', 'elementor-implementation-toolkit' ) );
		}
		$table = $wpdb->options;
		$wpdb->last_error = '';
		$serialized = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM `{$table}` WHERE option_name = %s FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name comes from wpdb.
				$options[ $source_type ]
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->read_error( $wpdb->last_error );
		}
		$records = null === $serialized ? null : maybe_unserialize( $serialized );
		$key = sanitize_key( $source_key );
		$raw = is_array( $records ) && is_array( $records[ $key ] ?? null ) ? $records[ $key ] : null;
		return null === $raw
			? [ 'available' => false, 'source_checksum' => '' ]
			: [ 'available' => true, 'source_checksum' => $this->checksum( $raw ) ];
	}

	private function read_elementor_document( $post_id ) {
		global $wpdb;

		$posts = $wpdb->posts;
		$postmeta = $wpdb->postmeta;
		$wpdb->last_error = '';
		$post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID,post_type,post_status,post_modified_gmt FROM `{$posts}` WHERE ID = %d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name comes from wpdb.
				$post_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->read_error( $wpdb->last_error );
		}
		if ( ! is_array( $post ) ) {
			return [ 'available' => false, 'source_checksum' => '' ];
		}

		$wpdb->last_error = '';
		$elementor_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id,meta_value FROM `{$postmeta}` WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name comes from wpdb.
				$post_id,
				'_elementor_data'
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->read_error( $wpdb->last_error );
		}
		$elementor_data = is_array( $elementor_rows ) && isset( $elementor_rows[0]['meta_value'] ) ? $elementor_rows[0]['meta_value'] : null;
		$raw = [
			'id' => (int) $post['ID'],
			'type' => (string) $post['post_type'],
			'status' => (string) $post['post_status'],
			'modified' => (string) $post['post_modified_gmt'],
			'data' => null === $elementor_data ? '' : maybe_unserialize( $elementor_data ),
		];
		return [ 'available' => true, 'source_checksum' => $this->checksum( $raw ) ];
	}

	private function checksum( $value ) {
		return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private function same_checksum( $expected, $actual ) {
		$expected = strtolower( (string) $expected );
		$actual = strtolower( (string) $actual );
		return (bool) preg_match( '/^[a-f0-9]{64}$/', $expected )
			&& (bool) preg_match( '/^[a-f0-9]{64}$/', $actual )
			&& hash_equals( $expected, $actual );
	}

	private function read_error( $database_error ) {
		return new \WP_Error(
			'eit_legacy_authority_commit_read_failed',
			__( 'Raw legacy source could not be locked for activation.', 'elementor-implementation-toolkit' ),
			[ 'status' => 409, 'database_error' => sanitize_text_field( $database_error ) ]
		);
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, $message, [ 'status' => 409 ] );
	}
}
