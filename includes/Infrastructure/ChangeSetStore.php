<?php
/**
 * Prepared, confirmable Blueprint change sets.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChangeSetStore {

	const STATUSES = [ 'blocked', 'prepared', 'applying', 'applied', 'reconciled', 'failed', 'rolled_back' ];

	public function create( array $record ) {
		global $wpdb;

		$impact = JsonCodec::encode( $record['impact'] ?? [] );
		$artifacts = JsonCodec::encode( $record['compiled_artifacts'] ?? [] );
		if ( is_wp_error( $impact ) || is_wp_error( $artifacts ) ) {
			return is_wp_error( $impact ) ? $impact : $artifacts;
		}
		$now = current_time( 'mysql', true );
		$status = in_array( $record['status'] ?? 'prepared', [ 'blocked', 'prepared' ], true ) ? $record['status'] ?? 'prepared' : 'prepared';
		$result = $wpdb->insert(
			Tables::name( Tables::CHANGE_SETS ),
			[
				'id' => (string) $record['id'],
				'blueprint_id' => (string) $record['blueprint_id'],
				'from_version_id' => empty( $record['from_version_id'] ) ? null : absint( $record['from_version_id'] ),
				'draft_checksum' => (string) $record['draft_checksum'],
				'status' => $status,
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

	public function published_for_checksum( $blueprint_id, $draft_checksum ) {
		global $wpdb;

		$table = Tables::name( Tables::CHANGE_SETS );
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from the closed Toolkit registry.
				"SELECT * FROM `{$table}` WHERE blueprint_id = %s AND draft_checksum = %s AND status IN ('applied','reconciled') ORDER BY applied_at DESC,created_at DESC LIMIT 1",
				$blueprint_id,
				$draft_checksum
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'eit_change_set_read_failed', __( 'Blueprint publication lineage could not be read safely.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] );
		}
		return $row ? $this->hydrate( $row ) : null;
	}

	public function prepared_for_draft( $blueprint_id, $from_version_id, $draft_checksum ) {
		$records = $this->prepared_candidates_for_draft( $blueprint_id, $from_version_id, $draft_checksum );
		return is_wp_error( $records ) ? $records : ( $records[0] ?? null );
	}

	public function prepared_candidates_for_draft( $blueprint_id, $from_version_id, $draft_checksum ) {
		global $wpdb;

		$table = Tables::name( Tables::CHANGE_SETS );
		$from_version_id = null === $from_version_id ? 0 : absint( $from_version_id );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from the closed Toolkit registry.
				"SELECT * FROM `{$table}` WHERE blueprint_id = %s AND draft_checksum = %s AND status = 'prepared' AND ((from_version_id IS NULL AND %d = 0) OR from_version_id = %d) ORDER BY created_at DESC,id DESC FOR UPDATE",
				$blueprint_id,
				$draft_checksum,
				$from_version_id,
				$from_version_id
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'eit_change_set_read_failed', __( 'Prepared Blueprint authority could not be read safely.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] );
		}
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	public function rotate_confirmation( $id, $expected_hash, $confirmation_hash, $user_id = 0 ) {
		global $wpdb;

		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $expected_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $confirmation_hash ) ) {
			return new \WP_Error( 'eit_change_set_confirmation_invalid', __( 'Blueprint confirmation authority is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$result = $wpdb->update(
			Tables::name( Tables::CHANGE_SETS ),
			[ 'confirmation_hash' => $confirmation_hash, 'created_by' => absint( $user_id ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => (string) $id, 'status' => 'prepared', 'confirmation_hash' => (string) $expected_hash ]
		);
		if ( 1 === $result ) {
			return $this->get( $id );
		}
		return false === $result
			? new \WP_Error( 'eit_change_set_write_failed', __( 'Blueprint confirmation authority could not be rotated.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] )
			: new \WP_Error( 'eit_change_set_state_conflict', __( 'Blueprint change set changed state before confirmation could rotate.', 'elementor-implementation-toolkit' ) );
	}

	public function rolled_back_for_checksum( $blueprint_id, $draft_checksum ) {
		global $wpdb;

		$table = Tables::name( Tables::CHANGE_SETS );
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from the closed Toolkit registry.
				"SELECT * FROM `{$table}` WHERE blueprint_id = %s AND draft_checksum = %s AND status = 'rolled_back' ORDER BY updated_at DESC,created_at DESC LIMIT 1",
				$blueprint_id,
				$draft_checksum
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'eit_change_set_read_failed', __( 'Rolled-back Blueprint lineage could not be read safely.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] );
		}
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
