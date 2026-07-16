<?php
/**
 * Durable shadow-import and comparison records.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationStore {

	public function save( array $record ) {
		global $wpdb;

		$source_type = sanitize_key( $record['source_type'] ?? '' );
		$source_key = sanitize_text_field( $record['source_key'] ?? '' );
		$id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'migration:' . $source_type . ':' . $source_key );
		$comparison = JsonCodec::encode( $record['comparison'] ?? [] );
		if ( is_wp_error( $comparison ) ) {
			return $comparison;
		}
		$existing = $this->get_by_source( $source_type, $source_key );
		$now = current_time( 'mysql', true );
		$data = [
			'source_type' => $source_type,
			'source_key' => $source_key,
			'blueprint_id' => (string) ( $record['blueprint_id'] ?? '' ),
			'source_checksum' => (string) ( $record['source_checksum'] ?? '' ),
			'draft_checksum' => (string) ( $record['draft_checksum'] ?? '' ),
			'status' => sanitize_key( $record['status'] ?? 'inspected' ),
			'comparison' => $comparison,
			'created_by' => absint( $record['created_by'] ?? 0 ),
			'updated_at' => $now,
		];
		if ( $existing ) {
			$result = $wpdb->update( Tables::name( Tables::MIGRATIONS ), $data, [ 'id' => $existing['id'] ] );
		} else {
			$data['id'] = $id;
			$data['created_at'] = $now;
			$result = $wpdb->insert( Tables::name( Tables::MIGRATIONS ), $data );
		}
		return false === $result
			? new \WP_Error( 'eit_migration_record_failed', __( 'Migration evidence could not be recorded.', 'elementor-implementation-toolkit' ) )
			: $this->get_by_source( $source_type, $source_key );
	}

	public function get_by_source( $source_type, $source_key ) {
		global $wpdb;

		$table = Tables::name( Tables::MIGRATIONS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE source_type = %s AND source_key = %s", sanitize_key( $source_type ), (string) $source_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	public function all() {
		global $wpdb;

		$table = Tables::name( Tables::MIGRATIONS );
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY source_type,source_key", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	private function hydrate( array $row ) {
		$row['created_by'] = (int) $row['created_by'];
		$row['comparison'] = JsonCodec::decode( $row['comparison'], [] );
		return $row;
	}
}
