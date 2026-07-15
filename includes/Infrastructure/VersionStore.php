<?php
/**
 * Insert-only immutable Blueprint version storage.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VersionStore {

	public function insert( $blueprint_id, array $document, $checksum ) {
		global $wpdb;

		$existing = $this->by_checksum( $blueprint_id, $checksum );
		if ( $existing ) {
			return $existing;
		}
		$encoded = JsonCodec::encode( $document );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		$version = $this->next_version( $blueprint_id );
		$result = $wpdb->insert(
			Tables::name( Tables::VERSIONS ),
			[
				'blueprint_id' => (string) $blueprint_id,
				'version' => $version,
				'checksum' => (string) $checksum,
				'schema_version' => (string) ( $document['api_version'] ?? '' ),
				'document' => $encoded,
				'published_at' => current_time( 'mysql', true ),
			]
		);
		if ( false === $result ) {
			return new \WP_Error( 'eit_blueprint_version_write_failed', __( 'Immutable Blueprint version could not be written.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] );
		}
		return $this->get( (int) $wpdb->insert_id );
	}

	public function get( $id ) {
		global $wpdb;

		$table = Tables::name( Tables::VERSIONS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	public function by_checksum( $blueprint_id, $checksum ) {
		global $wpdb;

		$table = Tables::name( Tables::VERSIONS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE blueprint_id = %s AND checksum = %s", $blueprint_id, $checksum ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	public function for_blueprint( $blueprint_id ) {
		global $wpdb;

		$table = Tables::name( Tables::VERSIONS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE blueprint_id = %s ORDER BY version DESC", $blueprint_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	private function next_version( $blueprint_id ) {
		global $wpdb;

		$table = Tables::name( Tables::VERSIONS );
		$maximum = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM `{$table}` WHERE blueprint_id = %s", $blueprint_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $maximum + 1;
	}

	private function hydrate( array $row ) {
		$row['id'] = (int) $row['id'];
		$row['version'] = (int) $row['version'];
		$row['document'] = JsonCodec::decode( $row['document'], [] );
		return $row;
	}
}
