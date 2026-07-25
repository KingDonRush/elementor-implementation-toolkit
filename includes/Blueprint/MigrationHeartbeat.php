<?php
/**
 * Propagates one fail-closed lease heartbeat through migration orchestration.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MigrationHeartbeat {

	private $callback;
	private $drivers;

	public function __construct( array $drivers ) {
		$this->drivers = $drivers;
	}

	public function configure( $callback = null ) {
		if ( null !== $callback && ! is_callable( $callback ) ) {
			return new \WP_Error( 'eit_migration_heartbeat_invalid', __( 'Migration heartbeat is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$configured = [];
		foreach ( $this->drivers as $driver ) {
			if ( ! method_exists( $driver, 'set_heartbeat' ) ) {
				continue;
			}
			$result = $driver->set_heartbeat( $callback );
			if ( is_wp_error( $result ) || false === $result ) {
				foreach ( $configured as $previous ) {
					$previous->set_heartbeat( null );
				}
				return is_wp_error( $result ) ? $result : new \WP_Error( 'eit_migration_heartbeat_invalid', __( 'Migration driver rejected its heartbeat.', 'elementor-implementation-toolkit' ) );
			}
			$configured[] = $driver;
		}
		$this->callback = $callback;
		return true;
	}

	public function pulse() {
		$result = $this->callback ? call_user_func( $this->callback ) : true;
		return true === $result || is_wp_error( $result )
			? $result
			: new \WP_Error( 'eit_migration_storage_lease_lost', __( 'Migration lock heartbeat failed.', 'elementor-implementation-toolkit' ) );
	}
}
