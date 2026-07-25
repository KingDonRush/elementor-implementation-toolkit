<?php
/**
 * Expiring database lock for Blueprint publication and rollback.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LockStore {

	const MIN_TTL = 30;
	const MAX_TTL = 900;

	public function acquire( $resource_key, $owner_id = 0, $ttl = 120 ) {
		global $wpdb;

		$table = Tables::name( Tables::LOCKS );
		$resource_key = sanitize_key( $resource_key );
		if ( '' === $resource_key ) {
			return $this->error( 'eit_lock_resource_invalid', __( 'Toolkit lock scope is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$now = current_time( 'mysql', true );
		$pruned = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE resource_key = %s AND expires_at <= %s", $resource_key, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $pruned ) {
			return $this->error( 'eit_lock_store_unavailable', __( 'Toolkit lock state could not be verified.', 'elementor-implementation-toolkit' ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name resolves from the internal whitelist.
				"INSERT IGNORE INTO `{$table}` (resource_key,token_hash,owner_id,expires_at,created_at) VALUES (%s,%s,%d,%s,%s)",
				$resource_key,
				hash( 'sha256', $token ),
				absint( $owner_id ),
				$this->expires_at( $ttl ),
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 !== $result
			? new \WP_Error( 'eit_blueprint_locked', __( 'Another Toolkit operation currently owns this Blueprint.', 'elementor-implementation-toolkit' ) )
			: $token;
	}

	/**
	 * Renews only the still-active lease owned by this exact token.
	 */
	public function renew( $resource_key, $token, $ttl = 120 ) {
		global $wpdb;

		$table = Tables::name( Tables::LOCKS );
		$resource_key = sanitize_key( $resource_key );
		$token_hash = hash( 'sha256', (string) $token );
		$now = current_time( 'mysql', true );
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name resolves from the internal whitelist.
				"UPDATE `{$table}` SET expires_at = %s WHERE resource_key = %s AND token_hash = %s AND expires_at > %s",
				$this->expires_at( $ttl ),
				$resource_key,
				$token_hash,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $result ) {
			return $this->error( 'eit_lock_store_unavailable', __( 'Toolkit lock state could not be renewed.', 'elementor-implementation-toolkit' ) );
		}
		if ( 1 === $result || $this->owns_active( $resource_key, $token_hash, $now ) ) {
			return true;
		}
		return $this->error( 'eit_lock_lease_lost', __( 'Toolkit lock ownership expired or changed.', 'elementor-implementation-toolkit' ) );
	}

	public function is_active( $resource_key ) {
		global $wpdb;

		$table = Tables::name( Tables::LOCKS );
		$resource_key = sanitize_key( $resource_key );
		$now = current_time( 'mysql', true );
		$pruned = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE resource_key = %s AND expires_at <= %s", $resource_key, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $pruned ) {
			return $this->error( 'eit_lock_store_unavailable', __( 'Toolkit lock state could not be verified.', 'elementor-implementation-toolkit' ) );
		}
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT resource_key FROM `{$table}` WHERE resource_key = %s AND expires_at > %s LIMIT 1", $resource_key, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->error( 'eit_lock_store_unavailable', __( 'Toolkit lock state could not be verified.', 'elementor-implementation-toolkit' ) );
		}
		return null !== $found;
	}

	/**
	 * Returns active lease resource keys without exposing their ownership tokens.
	 */
	public function active_with_prefix( $resource_prefix ) {
		global $wpdb;

		$table = Tables::name( Tables::LOCKS );
		$resource_prefix = sanitize_key( $resource_prefix );
		if ( '' === $resource_prefix ) {
			return $this->error( 'eit_lock_resource_invalid', __( 'Toolkit lock scope is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$now = current_time( 'mysql', true );
		$like = $wpdb->esc_like( $resource_prefix ) . '%';
		$pruned = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE resource_key LIKE %s AND expires_at <= %s", $like, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $pruned ) {
			return $this->error( 'eit_lock_store_unavailable', __( 'Toolkit writer leases could not be verified.', 'elementor-implementation-toolkit' ) );
		}
		$resources = $wpdb->get_col( $wpdb->prepare( "SELECT resource_key FROM `{$table}` WHERE resource_key LIKE %s AND expires_at > %s ORDER BY resource_key ASC", $like, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->error( 'eit_lock_store_unavailable', __( 'Toolkit writer leases could not be verified.', 'elementor-implementation-toolkit' ) );
		}
		return array_values( array_map( 'strval', (array) $resources ) );
	}

	public function release( $resource_key, $token ) {
		global $wpdb;

		$table = Tables::name( Tables::LOCKS );
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name resolves from the internal whitelist.
				"DELETE FROM `{$table}` WHERE resource_key = %s AND token_hash = %s",
				sanitize_key( $resource_key ),
				hash( 'sha256', (string) $token )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === $result;
	}

	private function owns_active( $resource_key, $token_hash, $now ) {
		global $wpdb;

		$table = Tables::name( Tables::LOCKS );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name resolves from the internal whitelist.
				"SELECT resource_key FROM `{$table}` WHERE resource_key = %s AND token_hash = %s AND expires_at > %s LIMIT 1",
				$resource_key,
				$token_hash,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return '' === (string) $wpdb->last_error && null !== $found;
	}

	private function expires_at( $ttl ) {
		return gmdate( 'Y-m-d H:i:s', time() + max( self::MIN_TTL, min( self::MAX_TTL, (int) $ttl ) ) );
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, $message );
	}
}
