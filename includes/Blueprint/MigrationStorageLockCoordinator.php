<?php
/**
 * Closes storage gates and proves all earlier writer leases have drained.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\LockStore;
use EIT\Infrastructure\MigrationOperationStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationStorageLockCoordinator {

	const LEASE_TTL = 900;

	private $operations;
	private $locks;

	public function __construct( $operations = null, $locks = null ) {
		$this->operations = $operations ?: new MigrationOperationStore();
		$this->locks = $locks ?: new LockStore();
	}

	public function acquire( $blueprint_id, $change_set_id, $owner_id = 0, $heartbeat_consumer = null, array $heartbeat_leases = [], array $additional_scopes = [] ) {
		$records = $this->operations->for_change_set( $blueprint_id, $change_set_id );
		if ( is_wp_error( $records ) ) {
			return $records;
		}
		$scopes = $additional_scopes;
		foreach ( $records as $record ) {
			$operation = is_array( $record['operation'] ?? null ) ? $record['operation'] : [];
			$scopes[] = [ 'strategy' => $operation['strategy'] ?? '', 'storage_slug' => $operation['storage_slug'] ?? '' ];
		}
		return $this->acquire_scopes( $scopes, $owner_id, $heartbeat_consumer, $heartbeat_leases );
	}

	public function acquire_scopes( array $storage_scopes, $owner_id = 0, $heartbeat_consumer = null, array $heartbeat_leases = [] ) {
		$scopes = [];
		foreach ( $storage_scopes as $scope ) {
			$scope = is_array( $scope ) ? $scope : [];
			$gate = StorageMutationGuard::resource_key( $scope['strategy'] ?? '', $scope['storage_slug'] ?? '' );
			$writer_prefix = StorageMutationGuard::writer_prefix( $scope['strategy'] ?? '', $scope['storage_slug'] ?? '' );
			if ( '' === $gate || '' === $writer_prefix ) {
				return new \WP_Error( 'eit_migration_storage_lock_scope_invalid', __( 'Migration storage lock scope is invalid.', 'elementor-implementation-toolkit' ) );
			}
			$scopes[ $gate ] = $writer_prefix;
		}
		ksort( $scopes, SORT_STRING );
		return $this->close_scopes( $scopes, $owner_id, $heartbeat_consumer, $heartbeat_leases );
	}

	public function acquire_storage( $strategy, $storage_slug, $owner_id = 0 ) {
		return $this->acquire_scopes( [ [ 'strategy' => $strategy, 'storage_slug' => $storage_slug ] ], $owner_id );
	}

	private function close_scopes( array $scopes, $owner_id, $heartbeat_consumer = null, array $heartbeat_leases = [] ) {
		$leases = [];
		foreach ( array_keys( $scopes ) as $gate ) {
			$token = $this->locks->acquire( $gate, $owner_id, self::LEASE_TTL );
			if ( is_wp_error( $token ) ) {
				$this->release( $leases );
				return new \WP_Error(
					'eit_migration_storage_locked',
					__( 'Migration could not close every storage gate.', 'elementor-implementation-toolkit' ),
					[ 'resource' => $gate ]
				);
			}
			$leases[ $gate ] = $token;
		}
		foreach ( $scopes as $gate => $writer_prefix ) {
			$writers = $this->locks->active_with_prefix( $writer_prefix );
			if ( is_wp_error( $writers ) || $writers ) {
				$this->release( $leases );
				return is_wp_error( $writers ) ? $writers : new \WP_Error(
					'eit_migration_storage_locked',
					__( 'Migration is waiting for active content writes to finish.', 'elementor-implementation-toolkit' ),
					[ 'resource' => $gate, 'active_writer_count' => count( $writers ) ]
				);
			}
		}
		if ( null !== $heartbeat_consumer ) {
			$activated = $this->activate_heartbeat( $heartbeat_consumer, array_merge( $heartbeat_leases, $leases ) );
			if ( is_wp_error( $activated ) ) {
				$this->release( $leases, $heartbeat_consumer );
				return $activated;
			}
		}
		return $leases;
	}

	/**
	 * Heartbeat for long migrations; any lost gate ownership aborts the caller.
	 */
	public function renew( $leases ) {
		if ( ! is_array( $leases ) || ! $leases ) {
			return new \WP_Error( 'eit_migration_storage_lease_invalid', __( 'Migration storage leases are missing.', 'elementor-implementation-toolkit' ) );
		}
		foreach ( $leases as $gate => $token ) {
			$renewed = $this->locks->renew( $gate, $token, self::LEASE_TTL );
			if ( is_wp_error( $renewed ) || true !== $renewed ) {
				return new \WP_Error(
					'eit_migration_storage_lease_lost',
					__( 'Migration storage gate expired or changed ownership.', 'elementor-implementation-toolkit' ),
					[ 'resource' => $gate ]
				);
			}
		}
		return true;
	}

	public function activate_heartbeat( $consumer, $leases ) {
		if ( ! is_object( $consumer ) || ! method_exists( $consumer, 'set_heartbeat' ) ) {
			return new \WP_Error( 'eit_migration_heartbeat_unsupported', __( 'Migration executor cannot renew storage gates.', 'elementor-implementation-toolkit' ) );
		}
		$renewed = $this->renew( $leases );
		if ( is_wp_error( $renewed ) ) {
			return $renewed;
		}
		$configured = $consumer->set_heartbeat( function () use ( $leases ) {
			return $this->renew( $leases );
		} );
		return true === $configured ? true : $configured;
	}

	public function deactivate_heartbeat( $consumer ) {
		return is_object( $consumer ) && method_exists( $consumer, 'set_heartbeat' )
			? true === $consumer->set_heartbeat( null )
			: false;
	}

	public function release( $leases, $heartbeat_consumer = null ) {
		if ( ! is_array( $leases ) ) {
			return false;
		}
		if ( null !== $heartbeat_consumer ) {
			$this->deactivate_heartbeat( $heartbeat_consumer );
		}
		$result = true;
		foreach ( array_reverse( $leases, true ) as $resource => $token ) {
			$result = $this->locks->release( $resource, $token ) && $result;
		}
		return $result;
	}
}
