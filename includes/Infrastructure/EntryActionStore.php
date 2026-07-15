<?php
/**
 * Retryable jobs for Entry Surface side effects.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryActionStore {

	public function enqueue( array $job ) {
		global $wpdb;

		$context = JsonCodec::encode( $job['context'] ?? [] );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$id = Uuid::v4();
		$now = current_time( 'mysql', true );
		$previous_suppression = $wpdb->suppress_errors( true );
		$result = $wpdb->insert(
			Tables::name( Tables::ACTION_JOBS ),
			[
				'id' => $id,
				'blueprint_id' => (string) $job['blueprint_id'],
				'surface_id' => (string) $job['surface_id'],
				'submission_id' => (string) $job['submission_id'],
				'action_id' => (string) $job['action_id'],
				'action_type' => sanitize_key( $job['action_type'] ),
				'event' => sanitize_key( $job['event'] ),
				'status' => 'queued',
				'attempts' => 0,
				'context' => $context,
				'result' => null,
				'error_code' => null,
				'error_message' => null,
				'available_at' => $now,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		$wpdb->suppress_errors( $previous_suppression );
		if ( false !== $result ) {
			return $this->get( $id );
		}
		return $this->find_existing( $job['submission_id'], $job['action_id'], $job['event'] ) ?: new \WP_Error( 'eit_entry_action_enqueue_failed', __( 'The Entry action could not be queued.', 'elementor-implementation-toolkit' ) );
	}

	public function claim( $id ) {
		global $wpdb;

		$table = Tables::name( Tables::ACTION_JOBS );
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %s AND status IN ('queued','failed') AND attempts < 5 AND available_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$id,
				current_time( 'mysql', true )
			)
		);
		return 1 === $result ? $this->get( $id ) : null;
	}

	public function finish( $id, $result ) {
		global $wpdb;

		$is_error = is_wp_error( $result );
		$encoded = JsonCodec::encode( $is_error ? [] : (array) $result );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		$job = $this->get( $id );
		$delay = min( 3600, 60 * ( 2 ** max( 0, (int) ( $job['attempts'] ?? 1 ) - 1 ) ) );
		$data = [
			'status' => $is_error ? 'failed' : 'succeeded',
			'result' => $encoded,
			'error_code' => $is_error ? sanitize_key( $result->get_error_code() ) : null,
			'error_message' => $is_error ? sanitize_text_field( $result->get_error_message() ) : null,
			'available_at' => $is_error ? gmdate( 'Y-m-d H:i:s', time() + $delay ) : current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		];
		$updated = $wpdb->update( Tables::name( Tables::ACTION_JOBS ), $data, [ 'id' => (string) $id, 'status' => 'running' ] );
		return 1 === $updated ? $this->get( $id ) : new \WP_Error( 'eit_entry_action_finish_failed', __( 'The Entry action result could not be recorded.', 'elementor-implementation-toolkit' ) );
	}

	public function retry_now( $id ) {
		global $wpdb;
		$updated = $wpdb->update( Tables::name( Tables::ACTION_JOBS ), [ 'status' => 'queued', 'available_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => (string) $id, 'status' => 'failed' ] );
		return 1 === $updated ? $this->get( $id ) : null;
	}

	public function get( $id ) {
		global $wpdb;
		$table = Tables::name( Tables::ACTION_JOBS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %s", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	public function recent( $limit = 100, $status = null ) {
		global $wpdb;
		$table = Tables::name( Tables::ACTION_JOBS );
		$limit = min( 200, max( 1, absint( $limit ) ) );
		$rows = $status
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE status = %s ORDER BY updated_at DESC LIMIT %d", sanitize_key( $status ), $limit ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	private function find_existing( $submission_id, $action_id, $event ) {
		global $wpdb;
		$table = Tables::name( Tables::ACTION_JOBS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE submission_id = %s AND action_id = %s AND event = %s", $submission_id, $action_id, $event ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	private function hydrate( array $row ) {
		$row['attempts'] = (int) $row['attempts'];
		$row['context'] = JsonCodec::decode( $row['context'], [] );
		$row['result'] = JsonCodec::decode( $row['result'], [] );
		return $row;
	}
}
