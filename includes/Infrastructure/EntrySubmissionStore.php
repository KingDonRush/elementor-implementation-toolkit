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

	const LEASE_SECONDS = 120;

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
		if ( 'processing' === $existing['status'] && $this->lease_expired( $existing ) ) {
			$reclaimed = $this->reclaim( $existing );
			if ( $reclaimed ) {
				return [ 'claimed' => true, 'record' => $reclaimed ];
			}
		}
		return [ 'claimed' => false, 'record' => $existing ];
	}

	public function persist( $id, $item_id, array $response ) {
		return $this->transition( $id, 'processing', 'persisted', $item_id, $response );
	}

	public function succeed( $id, $item_id, array $response ) {
		$record = $this->get( $id );
		if ( ! $record || ! in_array( $record['status'], [ 'processing', 'persisted' ], true ) ) {
			return new \WP_Error( 'eit_entry_idempotency_finish_failed', __( 'The submission result could not be recorded.', 'elementor-implementation-toolkit' ) );
		}
		return $this->transition( $id, $record['status'], 'succeeded', $item_id, $response );
	}

	public function fail( $id, \WP_Error $error ) {
		return $this->transition( $id, 'processing', 'failed', null, [], $error->get_error_code() );
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

	private function transition( $id, $from, $status, $item_id, array $response, $error_code = null ) {
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
			[ 'id' => (string) $id, 'status' => sanitize_key( $from ) ]
		);
		return 1 === $result ? $this->get( $id ) : new \WP_Error( 'eit_entry_idempotency_finish_failed', __( 'The submission result could not be recorded.', 'elementor-implementation-toolkit' ) );
	}

	private function lease_expired( array $record ) {
		$updated = strtotime( (string) ( $record['updated_at'] ?? '' ) . ' UTC' );
		return false !== $updated && $updated <= time() - self::LEASE_SECONDS;
	}

	private function reclaim( array $record ) {
		global $wpdb;

		$updated = current_time( 'mysql', true );
		$result = $wpdb->update(
			Tables::name( Tables::ENTRY_SUBMISSIONS ),
			[ 'updated_at' => $updated, 'error_code' => null ],
			[ 'id' => (string) $record['id'], 'status' => 'processing', 'updated_at' => (string) $record['updated_at'] ]
		);
		return 1 === $result ? $this->get( $record['id'] ) : null;
	}

	private function hydrate( array $row ) {
		$row['response'] = JsonCodec::decode( $row['response'], [] );
		return $row;
	}
}
