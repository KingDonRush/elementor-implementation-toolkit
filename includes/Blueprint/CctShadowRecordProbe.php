<?php
/**
 * Reads CCT rows directly and hydrates them with one explicitly supplied contract.
 */

namespace EIT\Blueprint;

use EIT\CCT\FieldTypes;
use EIT\CCT\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctShadowRecordProbe {

	const PAGE_SIZE = 100;

	public function snapshot( $slug, array $fields ) {
		global $wpdb;

		$table = SchemaManager::table_name( $slug );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $found ) {
			return [ 'available' => false, 'rows' => [] ];
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $columns ) || array_diff( [ 'id', 'status' ], $columns ) ) {
			return [ 'available' => false, 'rows' => [] ];
		}
		$available = array_fill_keys( $columns, true );
		$page = 0;
		$rows = [];
		do {
			$offset = $page * self::PAGE_SIZE;
			$sql = $wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status IN ('publish','draft','review','archived') ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::PAGE_SIZE,
				$offset
			);
			$batch = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $batch ) ) {
				return [ 'available' => false, 'rows' => [] ];
			}
			foreach ( $batch as $row ) {
				$values = [];
				foreach ( $fields as $field ) {
					$definition = is_array( $field['definition'] ?? null ) ? $field['definition'] : [];
					$column = SchemaManager::column_name( $field['key'] ?? '' );
					$values[] = isset( $available[ $column ] )
						? FieldTypes::decode( $row[ $column ] ?? null, $definition )
						: null;
				}
				$rows[] = [ 'id' => absint( $row['id'] ?? 0 ), 'status' => sanitize_key( $row['status'] ?? '' ), 'values' => $values ];
			}
			++$page;
		} while ( self::PAGE_SIZE === count( $batch ) );

		return [ 'available' => true, 'rows' => $rows ];
	}
}
