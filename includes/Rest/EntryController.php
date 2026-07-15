<?php
/**
 * Public/authenticated REST contract for Entry Surfaces and submissions.
 */

namespace EIT\Rest;

use EIT\Entry\EntryContractPresenter;
use EIT\Entry\EntryMediaService;
use EIT\Entry\EntryPolicyEngine;
use EIT\Entry\EntryStorageGateway;
use EIT\Entry\EntrySubmissionService;
use EIT\Entry\EntrySurfaceResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryController {

	const NAMESPACE = 'eit/v1';
	const MAX_BODY_BYTES = 262144;

	private $resolver;
	private $storage;
	private $policy;
	private $presenter;
	private $submissions;
	private $media;

	public function __construct() {
		$this->resolver = new EntrySurfaceResolver();
		$this->storage = new EntryStorageGateway();
		$this->policy = new EntryPolicyEngine( $this->storage );
		$this->presenter = new EntryContractPresenter( $this->policy );
		$this->submissions = new EntrySubmissionService( [ 'resolver' => $this->resolver, 'storage' => $this->storage, 'policy' => $this->policy ] );
		$this->media = new EntryMediaService( $this->resolver, $this->policy );
	}

	public function init_hooks() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/entry-surfaces/(?P<id>[a-f0-9-]{36})',
			[
				'methods' => \WP_REST_Server::READABLE,
				'callback' => [ $this, 'contract' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/entry-submissions',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [ $this, 'submit' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/entry-media',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [ $this, 'upload' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function contract( \WP_REST_Request $request ) {
		$contract = $this->resolver->get( $request['id'] );
		if ( ! $contract ) {
			return $this->error( 'eit_entry_surface_not_found', __( 'Entry Surface was not found.', 'elementor-implementation-toolkit' ), 404 );
		}
		$item_id = absint( $request->get_param( 'item_id' ) );
		$operation = $item_id ? 'update' : 'create';
		$authorized = $this->policy->authorize( $contract, $operation, $item_id );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		$loaded = $item_id ? $this->storage->load_values( $contract, $item_id ) : null;
		return is_wp_error( $loaded ) ? $loaded : rest_ensure_response( $this->presenter->present( $contract, $loaded ) );
	}

	public function submit( \WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return $this->error( 'eit_entry_body_too_large', __( 'Entry submission exceeds the 256 KB limit.', 'elementor-implementation-toolkit' ), 413 );
		}
		$params = $request->get_json_params();
		$result = $this->submissions->submit( is_array( $params ) ? $params : [] );
		return is_wp_error( $result ) ? $this->with_default_status( $result, 422 ) : rest_ensure_response( $result );
	}

	public function upload( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$files = $request->get_file_params();
		$result = $this->media->upload( is_array( $params ) ? $params : [], is_array( $files['file'] ?? null ) ? $files['file'] : [] );
		return is_wp_error( $result ) ? $this->with_default_status( $result, 422 ) : rest_ensure_response( $result );
	}

	private function with_default_status( \WP_Error $error, $status ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$error->add_data( array_merge( is_array( $data ) ? $data : [], [ 'status' => $status ] ) );
		}
		return $error;
	}

	private function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
