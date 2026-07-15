<?php
/**
 * Count and checksum proof recorded after publication.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReconciliationStore {

	public function record( $blueprint_id, $change_set_id, $status, array $counts, array $checksums ) {
		global $wpdb;

		$encoded_counts = JsonCodec::encode( $counts );
		$encoded_checksums = JsonCodec::encode( $checksums );
		if ( is_wp_error( $encoded_counts ) || is_wp_error( $encoded_checksums ) ) {
			return is_wp_error( $encoded_counts ) ? $encoded_counts : $encoded_checksums;
		}
		$id = Uuid::v4();
		$result = $wpdb->insert(
			Tables::name( Tables::RECONCILIATIONS ),
			[
				'id' => $id,
				'blueprint_id' => (string) $blueprint_id,
				'change_set_id' => (string) $change_set_id,
				'status' => sanitize_key( $status ),
				'counts' => $encoded_counts,
				'checksums' => $encoded_checksums,
				'reconciled_at' => current_time( 'mysql', true ),
			]
		);
		return false === $result ? new \WP_Error( 'eit_reconciliation_write_failed', __( 'Toolkit reconciliation proof could not be saved.', 'elementor-implementation-toolkit' ) ) : $id;
	}
}
