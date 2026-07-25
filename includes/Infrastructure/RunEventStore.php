<?php
/**
 * Ordered redacted events for the Flight Recorder.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RunEventStore {

	private $redactor;

	public function __construct( ?PayloadRedactor $redactor = null ) {
		$this->redactor = $redactor ?: new PayloadRedactor();
	}

	public function append( $run_id, $event_type, array $payload = [], $duration_ms = null ) {
		global $wpdb;

		$table = Tables::name( Tables::RUN_EVENTS );
		$sequence = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sequence),0) + 1 FROM `{$table}` WHERE run_id = %s", (string) $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$encoded = JsonCodec::encode( $this->redactor->redact( $payload ) );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		$result = $wpdb->insert(
			$table,
			[
				'run_id' => (string) $run_id,
				'sequence' => max( 1, $sequence ),
				'event_type' => sanitize_key( $event_type ),
				'payload' => $encoded,
				'duration_ms' => null === $duration_ms ? null : round( max( 0, (float) $duration_ms ), 3 ),
				'recorded_at' => current_time( 'mysql', true ),
			]
		);
		return false === $result
			? new \WP_Error( 'eit_run_event_failed', __( 'Flight Recorder event could not be stored.', 'elementor-implementation-toolkit' ) )
			: (int) $wpdb->insert_id;
	}

	public function for_run( $run_id ) {
		global $wpdb;

		$table = Tables::name( Tables::RUN_EVENTS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE run_id = %s ORDER BY sequence", (string) $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return [];
		}
		foreach ( $rows as &$row ) {
			$row['id'] = (int) $row['id'];
			$row['sequence'] = (int) $row['sequence'];
			$row['duration_ms'] = null === $row['duration_ms'] ? null : (float) $row['duration_ms'];
			$row['payload'] = JsonCodec::decode( $row['payload'], [] );
		}
		unset( $row );
		return $rows;
	}
}
