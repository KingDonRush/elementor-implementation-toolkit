<?php
/**
 * Allowlisted database access used by the CCT field migration driver.
 */

namespace EIT\Blueprint;

use EIT\CCT\SchemaManager as CctSchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CctMigrationDatabaseAccess {

	protected function storage_exists( $slug ) {
		return CctSchemaManager::table_exists( $slug );
	}

	protected function table_name( $slug ) {
		return CctSchemaManager::table_name( $slug );
	}

	protected function storage_columns( $table ) {
		global $wpdb;

		$wpdb->last_error = '';
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is derived by CCT SchemaManager and allowlisted.
		return '' === $wpdb->last_error ? array_column( $columns ?: [], null, 'Field' ) : new \WP_Error( 'eit_migration_storage_read_failed', __( 'CCT migration could not inspect storage.', 'elementor-implementation-toolkit' ) );
	}

	protected function source_null_count( $table, $column ) {
		global $wpdb;

		$wpdb->last_error = '';
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column are derived and allowlisted.
		return '' === $wpdb->last_error ? (int) $count : new \WP_Error( 'eit_migration_storage_read_failed', __( 'CCT migration could not inspect NULL source values.', 'elementor-implementation-toolkit' ) );
	}

	protected function entity_ids( $table, $cursor, $limit, $maximum = 0 ) {
		global $wpdb;

		$bounded = 0 < $maximum ? ' AND id <= %d' : '';
		$params = 0 < $maximum ? [ $cursor, $maximum, $limit ] : [ $cursor, $limit ];
		$sql = "SELECT id FROM `{$table}` WHERE id > %d{$bounded} ORDER BY id ASC LIMIT %d";
		$wpdb->last_error = '';
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SQL is prepared; table is derived and allowlisted.
		return '' === $wpdb->last_error ? array_map( 'absint', $ids ?: [] ) : new \WP_Error( 'eit_migration_storage_read_failed', __( 'CCT migration could not read record identifiers.', 'elementor-implementation-toolkit' ) );
	}

	protected function row_values( $table, array $ids, array $columns ) {
		global $wpdb;

		if ( ! $ids ) {
			return [];
		}
		$select = implode( ',', array_map( fn( $column ) => "`{$column}`", $columns ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = $wpdb->prepare( "SELECT id,{$select} FROM `{$table}` WHERE id IN ({$placeholders}) ORDER BY id ASC", array_map( 'absint', $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are derived and allowlisted.
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; identifiers are derived and allowlisted.
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'eit_migration_storage_read_failed', __( 'CCT migration could not read field values.', 'elementor-implementation-toolkit' ) );
		}
		return array_column( $rows ?: [], null, 'id' );
	}

	protected function write_target_value( $table, $column, $id, $expected, $value ) {
		global $wpdb;

		$set = null === $value ? 'NULL' : '%s';
		$guard = null === $expected ? "`{$column}` IS NULL" : "`{$column}` = %s";
		$params = [];
		if ( null !== $value ) {
			$params[] = $value;
		}
		$params[] = $id;
		if ( null !== $expected ) {
			$params[] = $expected;
		}
		$sql = "UPDATE `{$table}` SET `{$column}` = {$set} WHERE id = %d AND {$guard}";
		$wpdb->last_error = '';
		$result = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SQL is prepared; identifiers are derived and allowlisted.
		return false === $result || '' !== $wpdb->last_error ? new \WP_Error( 'eit_migration_target_write_failed', __( 'CCT migration target could not be written.', 'elementor-implementation-toolkit' ) ) : $result;
	}
}
