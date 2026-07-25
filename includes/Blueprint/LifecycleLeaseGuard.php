<?php
/**
 * Renews common lifecycle locks and storage gates as one fail-closed lease set.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LifecycleLeaseGuard {

	const LEASE_TTL = 900;

	private $locks;
	private $leases = [];

	public function __construct( $locks, array $leases = [] ) {
		$this->locks = $locks;
		$this->add( $leases );
	}

	public function add( array $leases ) {
		foreach ( $leases as $resource => $token ) {
			$resource = sanitize_key( $resource );
			if ( '' === $resource || '' === (string) $token ) {
				return $this->error( 'eit_lifecycle_lease_invalid', __( 'Lifecycle lease scope is invalid.', 'elementor-implementation-toolkit' ), $resource );
			}
			$this->leases[ $resource ] = (string) $token;
		}
		return true;
	}

	public function pulse() {
		if ( ! $this->leases || ! is_object( $this->locks ) || ! method_exists( $this->locks, 'renew' ) ) {
			return $this->error( 'eit_lifecycle_lease_invalid', __( 'Lifecycle leases cannot be renewed safely.', 'elementor-implementation-toolkit' ) );
		}
		foreach ( $this->leases as $resource => $token ) {
			$renewed = $this->locks->renew( $resource, $token, self::LEASE_TTL );
			if ( is_wp_error( $renewed ) || true !== $renewed ) {
				return $this->error( 'eit_lifecycle_lease_lost', __( 'Lifecycle lock ownership expired or changed.', 'elementor-implementation-toolkit' ), $resource );
			}
		}
		return true;
	}

	public function callback() {
		return [ $this, 'pulse' ];
	}

	public static function storage_scopes( array $artifacts ) {
		$scopes = [];
		foreach ( $artifacts as $artifact ) {
			if ( ! is_array( $artifact ) || 'entity_definition' !== ( $artifact['kind'] ?? '' ) ) {
				continue;
			}
			$payload = is_array( $artifact['payload'] ?? null ) ? $artifact['payload'] : [];
			$strategy = sanitize_key( $payload['strategy'] ?? '' );
			$storage_slug = sanitize_key( $payload['definition']['slug'] ?? '' );
			if ( ! in_array( $strategy, [ 'cpt', 'cct' ], true ) || '' === $storage_slug ) {
				continue;
			}
			$key = $strategy . ':' . $storage_slug;
			$scopes[ $key ] = [ 'strategy' => $strategy, 'storage_slug' => $storage_slug ];
		}
		ksort( $scopes, SORT_STRING );
		return array_values( $scopes );
	}

	private function error( $code, $message, $resource = '' ) {
		$data = [ 'status' => 409 ];
		if ( '' !== $resource ) {
			$data['resource'] = $resource;
		}
		return new \WP_Error( $code, $message, $data );
	}
}
