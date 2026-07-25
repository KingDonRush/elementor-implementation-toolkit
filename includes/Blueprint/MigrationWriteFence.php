<?php
/**
 * Blocks Toolkit writes while a field migration has an unsettled snapshot.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\MigrationOperationRecordCodec;
use EIT\Infrastructure\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationWriteFence {

	const FENCED_STATUSES = [
		'target_preparing',
		'target_prepared',
		'copying',
		'copied',
		'validating',
		'validated',
		'switching',
		'switched',
		'reconciling',
		'rolling_back',
		'retryable_failure',
	];

	public function guard_contract( array $contract ) {
		$blueprint_id = (string) ( $contract['blueprint_id'] ?? '' );
		$entity_id = (string) ( $contract['entity_id'] ?? $contract['entity']['entity_id'] ?? '' );
		if ( '' === $blueprint_id || '' === $entity_id ) {
			return new \WP_Error( 'eit_migration_fence_scope_missing', __( 'Entry storage scope is incomplete.', 'elementor-implementation-toolkit' ) );
		}
		$operations = $this->active_operations( $blueprint_id );
		if ( is_wp_error( $operations ) ) {
			return $operations;
		}
		foreach ( $operations as $record ) {
			if ( hash_equals( $entity_id, (string) ( $record['operation']['entity_id'] ?? '' ) ) ) {
				return $this->fenced_error( $record );
			}
		}
		return true;
	}

	public function guard_storage( $strategy, $storage_slug ) {
		$strategy = sanitize_key( $strategy );
		$storage_slug = sanitize_key( $storage_slug );
		if ( '' === $strategy || '' === $storage_slug ) {
			return new \WP_Error( 'eit_migration_fence_scope_missing', __( 'Storage scope is incomplete.', 'elementor-implementation-toolkit' ) );
		}
		$operations = $this->active_operations();
		if ( is_wp_error( $operations ) ) {
			return $operations;
		}
		foreach ( $operations as $record ) {
			$operation = $record['operation'];
			if ( $strategy === sanitize_key( $operation['strategy'] ?? '' ) && $storage_slug === sanitize_key( $operation['storage_slug'] ?? '' ) ) {
				return $this->fenced_error( $record );
			}
		}
		return true;
	}

	public function invalidate() {
		// Deliberately uncached: write authorization must observe the durable
		// migration state at the moment each mutation begins.
	}

	private function active_operations( $blueprint_id = '' ) {
		global $wpdb;

		$table = Tables::name( Tables::MIGRATION_OPERATIONS );
		$placeholders = implode( ', ', array_fill( 0, count( self::FENCED_STATUSES ), '%s' ) );
		$params = self::FENCED_STATUSES;
		$sql = "SELECT id,blueprint_id,status,resume_status,field_id,adapter,operation_checksum,source_identity_hash,target_identity_hash,operation FROM `{$table}` WHERE status IN ({$placeholders})";
		if ( '' !== $blueprint_id ) {
			$sql .= ' AND blueprint_id = %s';
			$params[] = $blueprint_id;
		}
		$sql .= ' ORDER BY id';
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table and placeholders are internal allowlists.
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'eit_migration_fence_unavailable', __( 'Migration write safety could not be verified.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] );
		}
		$records = [];
		foreach ( $rows ?: [] as $row ) {
			$record = MigrationOperationRecordCodec::hydrate( $row );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			if ( 'retryable_failure' === $record['status'] && ! in_array( $record['resume_status'], self::FENCED_STATUSES, true ) ) {
				continue;
			}
			$records[] = $record;
		}
		return $records;
	}

	private function fenced_error( array $record ) {
		return new \WP_Error(
			'eit_migration_write_fenced',
			__( 'This content is temporarily read-only while a verified field migration is in progress.', 'elementor-implementation-toolkit' ),
			[ 'operation_id' => (string) ( $record['id'] ?? '' ), 'status' => (string) ( $record['status'] ?? '' ) ]
		);
	}
}
