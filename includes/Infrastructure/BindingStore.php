<?php
/**
 * Stable Field ID to adapter storage bindings with legacy aliases.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BindingStore {

	public function insert_many( $blueprint_id, $version_id, array $bindings ) {
		global $wpdb;

		foreach ( $bindings as $binding ) {
			$aliases = JsonCodec::encode( array_values( array_unique( $binding['aliases'] ?? [] ) ) );
			if ( is_wp_error( $aliases ) ) {
				return $aliases;
			}
			$existing = $this->get( $version_id, $binding['field_id'] ?? '' );
			if ( $existing ) {
				if ( $existing['storage_key'] !== ( $binding['storage_key'] ?? '' ) ) {
					return new \WP_Error( 'eit_binding_identity_collision', __( 'Published Field ID cannot point to a different storage key.', 'elementor-implementation-toolkit' ) );
				}
				continue;
			}
			$result = $wpdb->insert(
				Tables::name( Tables::BINDINGS ),
				[
					'blueprint_id' => (string) $blueprint_id,
					'version_id' => absint( $version_id ),
					'field_id' => (string) ( $binding['field_id'] ?? '' ),
					'adapter' => sanitize_key( $binding['adapter'] ?? '' ),
					'storage_key' => sanitize_key( $binding['storage_key'] ?? '' ),
					'aliases' => $aliases,
					'created_at' => current_time( 'mysql', true ),
				]
			);
			if ( false === $result ) {
				return new \WP_Error( 'eit_binding_write_failed', __( 'Field binding could not be persisted.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] );
			}
		}
		return true;
	}

	public function get( $version_id, $field_id ) {
		global $wpdb;

		$table = Tables::name( Tables::BINDINGS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE version_id = %d AND field_id = %s", absint( $version_id ), $field_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	public function for_version( $version_id ) {
		global $wpdb;

		$table = Tables::name( Tables::BINDINGS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE version_id = %d ORDER BY field_id", absint( $version_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	private function hydrate( array $row ) {
		$row['id'] = (int) $row['id'];
		$row['version_id'] = (int) $row['version_id'];
		$row['aliases'] = JsonCodec::decode( $row['aliases'], [] );
		return $row;
	}
}
