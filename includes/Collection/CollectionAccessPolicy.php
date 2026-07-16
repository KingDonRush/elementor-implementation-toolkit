<?php
/**
 * Enforces published Collection access without trusting browser identities.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionAccessPolicy {

	public function authorize( array $contract ) {
		if ( 'public' === ( $contract['access'] ?? 'authenticated' ) ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'eit_collection_authentication_required', __( 'Sign in to view this Collection.', 'elementor-implementation-toolkit' ), [ 'status' => 401 ] );
		}
		$capability = sanitize_key( $contract['policy']['capability'] ?? 'read' );
		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error( 'eit_collection_access_denied', __( 'You cannot view this Collection.', 'elementor-implementation-toolkit' ), [ 'status' => 403 ] );
		}
		return true;
	}
}
