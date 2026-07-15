<?php
/**
 * Durable idempotency ledger for Entry Surface mutations.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntrySubmissionStore {

	public function claim( array $identity ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$id = Uuid::v4();
		$previous_suppression = $wpdb->suppress_errors( true );
		$result = $wpdb->insert(
			Tables::name( Tables::ENTRY_SUBMISSIONS ),
			[
				'id' => $id,
				'blueprint_id' => (string) $identity['blueprint_id'],
				'surface_id' => (string) $identity['surface_id'],
				'actor_key' => (string) $identity['actor_key'],
				'idempotency_hash' => (string) $identity['idempotency_hash'],
				'payload_checksum' => (string) $identity['payload_checksum'],
				'operation' => sanitize_key( $identity['operation'] ),
				'item_id' => null,
				'status' => 'processing',
				'response' => null,
				'error_code' => null,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		$wpdb->suppress_errors( $previous_suppression );
		if ( false !== $result ) {
			return [ 'claimed' => true, 'record' => $this->get( $id ) ];
		}

		$existing = $this->find( $identity['surface_id'], $identity['actor_key'], $identity['idempotency_hash'] );
		if ( ! $existing ) {
			return new \WP_Error( 'eit_entry_idempotency_claim_failed', __( 'The submission could not acquire an idempotency claim.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! hash_equals( $existing['payload_checksum'], (string) $identity['payload_checksum'] ) ) {
			return new \WP_Error( 'eit_entry_idempotency_mismatch', __( 'This idempotency key was already used for different values.', 'elementor-implementation-toolkit' ) );
		}
		return [ 'claimed' => false, 'record' => $existing ];
	}

	public function succeed( $id, $item_id, array $response ) {
		return $this->finish( $id, 'succeeded', $item_id, $response );
	}

	public function fail( $id, \WP_Error $error ) {
		return $this->finish( $id, 'failed', null, [], $error->get_error_code() );
	}

	public function get( $id ) {
		global $wpdb;

		$table = Tables::name( Tables::ENTRY_SUBMISSIONS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %s", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	private function find( $surface_id, $actor_key, $idempotency_hash ) {
		global $wpdb;

		$table = Tables::name( Tables::ENTRY_SUBMISSIONS );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE surface_id = %s AND actor_key = %s AND idempotency_hash = %s", $surface_id, $actor_key, $idempotency_hash ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	private function finish( $id, $status, $item_id, array $response, $error_code = null ) {
		global $wpdb;

		$encoded = JsonCodec::encode( $response );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		$result = $wpdb->update(
			Tables::name( Tables::ENTRY_SUBMISSIONS ),
			[
				'status' => sanitize_key( $status ),
				'item_id' => null === $item_id ? null : (string) $item_id,
				'response' => $encoded,
				'error_code' => $error_code ? sanitize_key( $error_code ) : null,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => (string) $id, 'status' => 'processing' ]
		);
		return 1 === $result ? $this->get( $id ) : new \WP_Error( 'eit_entry_idempotency_finish_failed', __( 'The submission result could not be recorded.', 'elementor-implementation-toolkit' ) );
	}

	private function hydrate( array $row ) {
		$row['response'] = JsonCodec::decode( $row['response'], [] );
		return $row;
	}
}
