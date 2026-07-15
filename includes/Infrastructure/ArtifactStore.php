<?php
/**
 * Immutable compiled artifact storage.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArtifactStore {

	public function insert_many( $blueprint_id, $version_id, array $artifacts ) {
		foreach ( $artifacts as $artifact ) {
			$result = $this->insert( $blueprint_id, $version_id, $artifact );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	public function insert( $blueprint_id, $version_id, array $artifact ) {
		global $wpdb;

		$id = (string) ( $artifact['id'] ?? '' );
		$existing = $this->get( $id );
		if ( $existing ) {
			return hash_equals( $existing['checksum'], (string) ( $artifact['checksum'] ?? '' ) )
				? true
				: new \WP_Error( 'eit_artifact_identity_collision', __( 'Compiled artifact identity collided with different content.', 'elementor-implementation-toolkit' ) );
		}
		$payload = JsonCodec::encode( $artifact['payload'] ?? [] );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$result = $wpdb->insert(
			Tables::name( Tables::ARTIFACTS ),
			[
				'id' => $id,
				'blueprint_id' => (string) $blueprint_id,
				'version_id' => absint( $version_id ),
				'node_id' => $artifact['node_id'] ?? null,
				'kind' => sanitize_key( $artifact['kind'] ?? '' ),
				'checksum' => (string) ( $artifact['checksum'] ?? '' ),
				'payload' => $payload,
				'created_at' => current_time( 'mysql', true ),
			]
		);
		return false === $result
			? new \WP_Error( 'eit_artifact_write_failed', __( 'Compiled artifact could not be persisted.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] )
			: true;
	}

	public function get( $id ) {
		global $wpdb;

		$table = Tables::name( Tables::ARTIFACTS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %s", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	public function for_version( $version_id, $kind = null ) {
		global $wpdb;

		$table = Tables::name( Tables::ARTIFACTS );
		if ( null === $kind ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE version_id = %d ORDER BY kind,id", absint( $version_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE version_id = %d AND kind = %s ORDER BY id", absint( $version_id ), sanitize_key( $kind ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	public function active_for_blueprint( $blueprint_id, $kind = null ) {
		$blueprint = ( new BlueprintStore() )->get( $blueprint_id );
		return $blueprint && $blueprint['active_version_id']
			? $this->for_version( $blueprint['active_version_id'], $kind )
			: [];
	}

	private function hydrate( array $row ) {
		$row['version_id'] = (int) $row['version_id'];
		$row['payload'] = JsonCodec::decode( $row['payload'], [] );
		return $row;
	}
}
