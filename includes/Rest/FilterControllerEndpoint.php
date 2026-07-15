<?php
/**
 * REST endpoint for DOM-provider filtering.
 */

namespace EIT\Rest;

use EIT\Support\FilterResolver;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterControllerEndpoint {

	public function init_hooks() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route(
			'eit/v1',
			'/filter',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'filter' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function filter( WP_REST_Request $request ) {
		$payload = ( new FilterRequestPolicy() )->validate( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( is_array( $payload ) && 'cct' === ( $payload['provider'] ?? '' ) ) {
			$result = ( new CctFilterProvider() )->resolve( $payload );
			if ( ! is_wp_error( $result ) ) {
				$result['requestCost'] = absint( $payload['_eitRequestCost'] ?? 0 );
			}
			return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
		}

		$resolver = new FilterResolver();

		$result = $resolver->resolve( $payload );
		$result['requestCost'] = absint( $payload['_eitRequestCost'] ?? 0 );

		return rest_ensure_response( $result );
	}
}
