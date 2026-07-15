<?php
/**
 * Expiring database lock for Blueprint publication and rollback.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LockStore {

	public function acquire( $resource_key, $owner_id = 0, $ttl = 120 ) {
		global $wpdb;

		$table = Tables::name( Tables::LOCKS );
		$resource_key = sanitize_key( $resource_key );
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE resource_key = %s AND expires_at <= %s", $resource_key, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$token = bin2hex( random_bytes( 32 ) );
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name resolves from the internal whitelist.
				"INSERT IGNORE INTO `{$table}` (resource_key,token_hash,owner_id,expires_at,created_at) VALUES (%s,%s,%d,%s,%s)",
				$resource_key,
				hash( 'sha256', $token ),
				absint( $owner_id ),
				gmdate( 'Y-m-d H:i:s', time() + max( 30, min( 900, (int) $ttl ) ) ),
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 !== $result
			? new \WP_Error( 'eit_blueprint_locked', __( 'Another Toolkit operation currently owns this Blueprint.', 'elementor-implementation-toolkit' ) )
			: $token;
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
}
