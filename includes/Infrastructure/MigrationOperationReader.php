<?php
/**
 * Reads migration operation rows through the strict hydration boundary.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationOperationReader {

	public function get( $id ) {
		global $wpdb;

		$table = Tables::name( Tables::MIGRATION_OPERATIONS );
		return $this->one( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %s", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function for_change_set( $blueprint_id, $change_set_id ) {
		global $wpdb;

		$table = Tables::name( Tables::MIGRATION_OPERATIONS );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE blueprint_id = %s AND change_set_id = %s ORDER BY field_id", $blueprint_id, $change_set_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( null === $rows || '' !== $wpdb->last_error ) {
			return $this->read_error();
		}
		return MigrationOperationRecordCodec::hydrate_many( $rows );
	}

	public function by_target( $target_identity_hash ) {
		global $wpdb;

		$table = Tables::name( Tables::MIGRATION_OPERATIONS );
		return $this->one( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `target_identity_hash` = %s", $target_identity_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function by_field( $change_set_id, $field_id ) {
		global $wpdb;

		$table = Tables::name( Tables::MIGRATION_OPERATIONS );
		return $this->one( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE change_set_id = %s AND field_id = %s", $change_set_id, $field_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function one( $sql ) {
		global $wpdb;

		$wpdb->last_error = '';
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every caller supplies a prepared internal query.
		if ( '' !== $wpdb->last_error ) {
			return $this->read_error();
		}
		return $row ? MigrationOperationRecordCodec::hydrate( $row ) : null;
	}

	private function read_error() {
		global $wpdb;

		return new \WP_Error(
			'eit_migration_read_failed',
			__( 'Migration operation state could not be read safely.', 'elementor-implementation-toolkit' ),
			[ 'database_error' => sanitize_text_field( $wpdb->last_error ) ]
		);
	}
}
