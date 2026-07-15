<?php
/**
 * Small transaction boundary for atomic Blueprint metadata changes.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Transaction {

	public function run( callable $callback ) {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'eit_transaction_start_failed', __( 'Could not start the Toolkit database transaction.', 'elementor-implementation-toolkit' ) );
		}
		try {
			$result = $callback();
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $result;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'eit_transaction_commit_failed', __( 'Could not commit the Toolkit database transaction.', 'elementor-implementation-toolkit' ) );
			}
			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'eit_transaction_exception', __( 'The Toolkit database transaction was rolled back.', 'elementor-implementation-toolkit' ), [ 'exception' => sanitize_text_field( $error->getMessage() ) ] );
		}
	}
}
