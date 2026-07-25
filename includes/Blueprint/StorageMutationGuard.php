<?php
/**
 * Registers shared writer leases behind an exclusive migration gate.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\LockStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StorageMutationGuard {

	const LEASE_TTL = 900;

	private static $shared;

	private $fence;
	private $locks;
	private $lease_id_factory;
	private $held = [];

	public function __construct( ?MigrationWriteFence $fence = null, $locks = null, ?callable $lease_id_factory = null ) {
		$this->fence = $fence ?: new MigrationWriteFence();
		$this->locks = $locks ?: new LockStore();
		$this->lease_id_factory = $lease_id_factory ?: static function () {
			return bin2hex( random_bytes( 16 ) );
		};
	}

	public static function shared() {
		if ( null === self::$shared ) {
			self::$shared = new self();
		}
		return self::$shared;
	}

	public static function resource_key( $strategy, $storage_slug ) {
		$strategy = sanitize_key( $strategy );
		$storage_slug = sanitize_key( $storage_slug );
		return '' === $strategy || '' === $storage_slug ? '' : 'eit-write-' . $strategy . '-' . $storage_slug;
	}

	public static function writer_prefix( $strategy, $storage_slug ) {
		$strategy = sanitize_key( $strategy );
		$storage_slug = sanitize_key( $storage_slug );
		return '' === $strategy || '' === $storage_slug ? '' : 'eit-writer-' . $strategy . '-' . $storage_slug . '-';
	}

	/**
	 * Acquires a unique writer lease before rechecking the gate and fence.
	 */
	public function enter( $strategy, $storage_slug ) {
		$strategy = sanitize_key( $strategy );
		$storage_slug = sanitize_key( $storage_slug );
		$gate = self::resource_key( $strategy, $storage_slug );
		$prefix = self::writer_prefix( $strategy, $storage_slug );
		if ( '' === $gate || '' === $prefix || 158 < strlen( $prefix ) ) {
			return new \WP_Error( 'eit_migration_fence_scope_missing', __( 'Storage scope is incomplete.', 'elementor-implementation-toolkit' ) );
		}
		if ( isset( $this->held[ $gate ] ) ) {
			$renewed = $this->renew_held( $gate, $strategy, $storage_slug );
			if ( is_wp_error( $renewed ) ) {
				return $renewed;
			}
			++$this->held[ $gate ]['depth'];
			return $this->lease_payload( $gate );
		}

		$lease_id = sanitize_key( (string) call_user_func( $this->lease_id_factory ) );
		if ( '' === $lease_id ) {
			return new \WP_Error( 'eit_storage_writer_identity_invalid', __( 'Content writer lease identity could not be created.', 'elementor-implementation-toolkit' ) );
		}
		$writer_resource = $prefix . substr( $lease_id, 0, 32 );
		$token = $this->locks->acquire( $writer_resource, get_current_user_id(), self::LEASE_TTL );
		if ( is_wp_error( $token ) ) {
			return new \WP_Error(
				'eit_storage_writer_lease_unavailable',
				__( 'Content write lease could not be established safely.', 'elementor-implementation-toolkit' ),
				[ 'resource' => $gate ]
			);
		}
		$this->held[ $gate ] = [
			'token' => $token,
			'writer_resource' => $writer_resource,
			'identity' => hash( 'sha256', $writer_resource . '|' . $token ),
			'strategy' => $strategy,
			'storage_slug' => $storage_slug,
			'depth' => 1,
		];
		$allowed = $this->authorize( $gate, $strategy, $storage_slug );
		if ( is_wp_error( $allowed ) ) {
			$this->release_resource( $gate );
			return $allowed;
		}
		return $this->lease_payload( $gate );
	}

	/**
	 * Heartbeat for long-running writers; ownership loss is always fatal.
	 */
	public function renew( $lease ) {
		$gate = $this->lease_gate( $lease );
		if ( '' === $gate || ! $this->owns_lease( $gate, $lease ) ) {
			return new \WP_Error( 'eit_storage_writer_lease_stale', __( 'Content writer lease is no longer current.', 'elementor-implementation-toolkit' ) );
		}
		$held = $this->held[ $gate ];
		return $this->renew_held( $gate, $held['strategy'], $held['storage_slug'] );
	}

	public function leave( $lease ) {
		$gate = $this->lease_gate( $lease );
		if ( '' === $gate || ! $this->owns_lease( $gate, $lease ) ) {
			return false;
		}
		--$this->held[ $gate ]['depth'];
		if ( 0 < $this->held[ $gate ]['depth'] ) {
			return true;
		}
		return $this->release_resource( $gate );
	}

	public function release_all() {
		$result = true;
		foreach ( array_keys( $this->held ) as $gate ) {
			$result = $this->release_resource( $gate ) && $result;
		}
		return $result;
	}

	private function authorize( $gate, $strategy, $storage_slug ) {
		$closed = $this->locks->is_active( $gate );
		if ( is_wp_error( $closed ) ) {
			return $closed;
		}
		if ( $closed ) {
			return new \WP_Error(
				'eit_storage_write_locked',
				__( 'This content is temporarily read-only while its storage is changing.', 'elementor-implementation-toolkit' ),
				[ 'resource' => $gate ]
			);
		}
		return $this->fence->guard_storage( $strategy, $storage_slug );
	}

	private function renew_held( $gate, $strategy, $storage_slug ) {
		$held = $this->held[ $gate ];
		$renewed = $this->locks->renew( $held['writer_resource'], $held['token'], self::LEASE_TTL );
		if ( is_wp_error( $renewed ) || true !== $renewed ) {
			$this->release_resource( $gate );
			return new \WP_Error( 'eit_storage_writer_lease_lost', __( 'Content writer lease expired or changed ownership.', 'elementor-implementation-toolkit' ) );
		}
		return $this->authorize( $gate, $strategy, $storage_slug );
	}

	private function release_resource( $gate ) {
		$held = $this->held[ $gate ];
		unset( $this->held[ $gate ] );
		return $this->locks->release( $held['writer_resource'], $held['token'] );
	}

	private function lease_payload( $gate ) {
		return [
			'resource' => $gate,
			'identity' => $this->held[ $gate ]['identity'],
		];
	}

	private function lease_gate( $lease ) {
		return is_array( $lease ) ? sanitize_key( $lease['resource'] ?? '' ) : '';
	}

	private function owns_lease( $gate, $lease ) {
		$identity = is_array( $lease ) ? (string) ( $lease['identity'] ?? '' ) : '';
		return isset( $this->held[ $gate ] ) && '' !== $identity && hash_equals( $this->held[ $gate ]['identity'], $identity );
	}
}
