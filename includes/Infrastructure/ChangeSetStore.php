<?php
/**
 * Prepared, confirmable Blueprint change sets.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChangeSetStore {

	const STATUSES = [ 'prepared', 'applying', 'applied', 'reconciled', 'failed', 'rolled_back' ];

	public function create( array $record ) {
		global $wpdb;

		$impact = JsonCodec::encode( $record['impact'] ?? [] );
		$artifacts = JsonCodec::encode( $record['compiled_artifacts'] ?? [] );
		if ( is_wp_error( $impact ) || is_wp_error( $artifacts ) ) {
			return is_wp_error( $impact ) ? $impact : $artifacts;
		}
		$now = current_time( 'mysql', true );
		$result = $wpdb->insert(
			Tables::name( Tables::CHANGE_SETS ),
			[
				'id' => (string) $record['id'],
				'blueprint_id' => (string) $record['blueprint_id'],
				'from_version_id' => empty( $record['from_version_id'] ) ? null : absint( $record['from_version_id'] ),
				'draft_checksum' => (string) $record['draft_checksum'],
				'status' => 'prepared',
				'impact' => $impact,
				'compiled_artifacts' => $artifacts,
				'confirmation_hash' => (string) $record['confirmation_hash'],
				'created_by' => absint( $record['created_by'] ?? 0 ),
				'created_at' => $now,
				'updated_at' => $now,
				'applied_at' => null,
			]
		);
		return false === $result
			? new \WP_Error( 'eit_change_set_write_failed', __( 'Blueprint change set could not be prepared.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] )
			: $this->get( $record['id'] );
	}

	public function get( $id ) {
		global $wpdb;

		$table = Tables::name( Tables::CHANGE_SETS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %s", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	public function transition( $id, $from, $to, array $extra = [] ) {
		global $wpdb;

		if ( ! in_array( $from, self::STATUSES, true ) || ! in_array( $to, self::STATUSES, true ) ) {
			return new \WP_Error( 'eit_change_set_invalid_transition', __( 'Blueprint change set transition is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$data = array_merge( [ 'status' => $to, 'updated_at' => current_time( 'mysql', true ) ], $extra );
		$result = $wpdb->update( Tables::name( Tables::CHANGE_SETS ), $data, [ 'id' => $id, 'status' => $from ] );
		return 1 === $result
			? $this->get( $id )
			: new \WP_Error( 'eit_change_set_state_conflict', __( 'Blueprint change set changed state before this operation completed.', 'elementor-implementation-toolkit' ) );
	}

	private function hydrate( array $row ) {
		$row['from_version_id'] = null === $row['from_version_id'] ? null : (int) $row['from_version_id'];
		$row['created_by'] = (int) $row['created_by'];
		$row['impact'] = JsonCodec::decode( $row['impact'], [] );
		$row['compiled_artifacts'] = JsonCodec::decode( $row['compiled_artifacts'], [] );
		return $row;
	}
}
