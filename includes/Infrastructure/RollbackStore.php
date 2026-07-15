<?php
/**
 * Immutable Blueprint rollback audit records.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RollbackStore {

	public function record( $blueprint_id, $from_version_id, $to_version_id, $reason, $created_by = 0 ) {
		global $wpdb;

		$id = Uuid::v4();
		$result = $wpdb->insert(
			Tables::name( Tables::ROLLBACKS ),
			[
				'id' => $id,
				'blueprint_id' => (string) $blueprint_id,
				'from_version_id' => absint( $from_version_id ),
				'to_version_id' => absint( $to_version_id ),
				'reason' => sanitize_textarea_field( $reason ),
				'created_by' => absint( $created_by ),
				'created_at' => current_time( 'mysql', true ),
			]
		);
		return false === $result ? new \WP_Error( 'eit_rollback_write_failed', __( 'Toolkit rollback audit record could not be saved.', 'elementor-implementation-toolkit' ) ) : $id;
	}
}
