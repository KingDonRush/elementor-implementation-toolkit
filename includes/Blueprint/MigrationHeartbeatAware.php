<?php
/**
 * Optional heartbeat support for bounded migration drivers.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait MigrationHeartbeatAware {

	private $migration_heartbeat;

	public function set_heartbeat( $heartbeat = null ) {
		if ( null !== $heartbeat && ! is_callable( $heartbeat ) ) {
			return new \WP_Error( 'eit_migration_heartbeat_invalid', __( 'Migration driver heartbeat is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$this->migration_heartbeat = $heartbeat;
		return true;
	}

	protected function pulse_heartbeat() {
		$result = $this->migration_heartbeat ? call_user_func( $this->migration_heartbeat ) : true;
		return true === $result || is_wp_error( $result )
			? $result
			: new \WP_Error( 'eit_migration_storage_lease_lost', __( 'Migration driver lock heartbeat failed.', 'elementor-implementation-toolkit' ) );
	}
}
