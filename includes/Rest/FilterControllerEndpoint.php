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
		$resolver = new FilterResolver();

		return rest_ensure_response( $resolver->resolve( $request->get_json_params() ) );
	}
}
