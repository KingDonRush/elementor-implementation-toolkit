<?php
/**
 * Public/authenticated REST transport for published Collection contracts.
 */

namespace EIT\Rest;

use EIT\Collection\CollectionAccessPolicy;
use EIT\Collection\CollectionContractPresenter;
use EIT\Collection\CollectionQueryService;
use EIT\Collection\CollectionRequestValidator;
use EIT\Collection\CollectionSurfaceResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionController {

	const NAMESPACE = 'eit/v1';

	private $resolver;
	private $access;
	private $presenter;
	private $validator;
	private $queries;

	public function __construct() {
		$this->resolver = new CollectionSurfaceResolver();
		$this->access = new CollectionAccessPolicy();
		$this->presenter = new CollectionContractPresenter();
		$this->validator = new CollectionRequestValidator();
	}

	public function init_hooks() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/collections/(?P<id>[a-f0-9-]{36})',
			[
				'methods' => \WP_REST_Server::READABLE,
				'callback' => [ $this, 'contract' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/collections/(?P<id>[a-f0-9-]{36})/query',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [ $this, 'query' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function contract( \WP_REST_Request $request ) {
		$contract = $this->resolve_authorized( $request['id'] );
		return is_wp_error( $contract ) ? $contract : rest_ensure_response( $this->presenter->present( $contract ) );
	}

	public function query( \WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > CollectionRequestValidator::MAX_BODY_BYTES ) {
			return $this->error( 'eit_collection_request_too_large', __( 'Collection request exceeds the 32 KB limit.', 'elementor-implementation-toolkit' ), 413 );
		}
		$contract = $this->resolve_authorized( $request['id'] );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}
		$payload = $request->get_json_params();
		$validated = $this->validator->validate( $contract, is_array( $payload ) ? $payload : null );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$result = $this->queries()->execute( $contract, $validated );
		return is_wp_error( $result ) ? $this->with_status( $result, 422 ) : rest_ensure_response( $result );
	}

	private function resolve_authorized( $collection_id ) {
		$contract = $this->resolver->get( $collection_id );
		if ( ! $contract ) {
			return $this->error( 'eit_collection_not_found', __( 'Collection was not found.', 'elementor-implementation-toolkit' ), 404 );
		}
		$authorized = $this->access->authorize( $contract );
		return is_wp_error( $authorized ) ? $authorized : $contract;
	}

	private function with_status( \WP_Error $error, $status ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$error->add_data( array_merge( is_array( $data ) ? $data : [], [ 'status' => $status ] ) );
		}
		return $error;
	}

	private function queries() {
		if ( ! $this->queries ) {
			$this->queries = new CollectionQueryService();
		}
		return $this->queries;
	}

	private function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
