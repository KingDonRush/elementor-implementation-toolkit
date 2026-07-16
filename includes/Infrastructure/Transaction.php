<?php
/**
 * Small transaction boundary for atomic Blueprint metadata changes.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Transaction {

	private static $depth = 0;

	public function run( callable $callback ) {
		global $wpdb;

		$level = self::$depth;
		$savepoint = 'eit_tx_' . $level;
		$opening = 0 === $level ? 'START TRANSACTION' : 'SAVEPOINT ' . $savepoint;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Savepoint name is derived only from internal integer depth.
		if ( false === $wpdb->query( $opening ) ) {
			return new \WP_Error( 'eit_transaction_start_failed', __( 'Could not start the Toolkit database transaction.', 'elementor-implementation-toolkit' ) );
		}
		++self::$depth;
		try {
			$result = $callback();
			if ( is_wp_error( $result ) ) {
				$this->rollback( $level, $savepoint );
				return $result;
			}
			$closing = 0 === $level ? 'COMMIT' : 'RELEASE SAVEPOINT ' . $savepoint;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Savepoint name is derived only from internal integer depth.
			if ( false === $wpdb->query( $closing ) ) {
				$this->rollback( $level, $savepoint );
				return new \WP_Error( 'eit_transaction_commit_failed', __( 'Could not commit the Toolkit database transaction.', 'elementor-implementation-toolkit' ) );
			}
			--self::$depth;
			return $result;
		} catch ( \Throwable $error ) {
			$this->rollback( $level, $savepoint );
			do_action( 'eit_transaction_failed', get_class( $error ) );
			return new \WP_Error( 'eit_transaction_exception', __( 'The Toolkit database transaction was rolled back.', 'elementor-implementation-toolkit' ) );
		}
	}

	private function rollback( $level, $savepoint ) {
		global $wpdb;

		--self::$depth;
		if ( 0 === $level ) {
			$wpdb->query( 'ROLLBACK' );
			return;
		}
		$wpdb->query( 'ROLLBACK TO SAVEPOINT ' . $savepoint ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal integer-derived savepoint.
		$wpdb->query( 'RELEASE SAVEPOINT ' . $savepoint ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal integer-derived savepoint.
	}
}
