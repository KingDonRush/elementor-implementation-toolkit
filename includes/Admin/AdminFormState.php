<?php
/**
 * Short-lived per-user form state for safe redirect-after-post recovery.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminFormState {

	const TTL = 300;

	public static function store( $context, array $values, $error ) {
		set_transient(
			self::key( $context ),
			[
				'values' => $values,
				'error'  => sanitize_text_field( $error ),
			],
			self::TTL
		);
	}

	public static function pull( $context ) {
		$key = self::key( $context );
		$state = get_transient( $key );
		delete_transient( $key );

		return is_array( $state ) ? $state : null;
	}

	private static function key( $context ) {
		return 'eit_form_' . absint( get_current_user_id() ) . '_' . substr( md5( sanitize_key( $context ) ), 0, 16 );
	}
}
