<?php
/**
 * Redacted execution history for compiler and lifecycle operations.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RunStore {

	public function start( $blueprint_id, $operation, $change_set_id = null, array $context = [] ) {
		global $wpdb;

		$encoded = JsonCodec::encode( $this->redact( $context ) );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		$id = Uuid::v4();
		$result = $wpdb->insert(
			Tables::name( Tables::RUNS ),
			[
				'id' => $id,
				'blueprint_id' => (string) $blueprint_id,
				'change_set_id' => $change_set_id,
				'operation' => sanitize_key( $operation ),
				'status' => 'running',
				'request_id' => Uuid::v4(),
				'context' => $encoded,
				'error_code' => null,
				'error_message' => null,
				'started_at' => current_time( 'mysql', true ),
				'finished_at' => null,
			]
		);
		return false === $result ? new \WP_Error( 'eit_run_start_failed', __( 'Toolkit run could not be recorded.', 'elementor-implementation-toolkit' ) ) : $this->get( $id );
	}

	public function finish( $id, $status, $error = null ) {
		global $wpdb;

		$data = [ 'status' => sanitize_key( $status ), 'finished_at' => current_time( 'mysql', true ) ];
		if ( is_wp_error( $error ) ) {
			$data['error_code'] = sanitize_key( $error->get_error_code() );
			$data['error_message'] = sanitize_text_field( $error->get_error_message() );
		}
		$result = $wpdb->update( Tables::name( Tables::RUNS ), $data, [ 'id' => $id, 'status' => 'running' ] );
		return 1 === $result ? $this->get( $id ) : false;
	}

	public function get( $id ) {
		global $wpdb;

		$table = Tables::name( Tables::RUNS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %s", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row ) {
			$row['context'] = JsonCodec::decode( $row['context'], [] );
		}
		return $row ?: null;
	}

	private function redact( array $context ) {
		foreach ( $context as $key => $value ) {
			if ( preg_match( '/secret|token|password|authorization|cookie/i', (string) $key ) ) {
				$context[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = $this->redact( $value );
			}
		}
		return $context;
	}
}
