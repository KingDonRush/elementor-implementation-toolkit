<?php
/**
 * Capability-gated REST lifecycle for the executable Blueprint workspace.
 */

namespace EIT\Rest;

use EIT\Admin\AdminPages;
use EIT\Admin\BlueprintAdminPresenter;
use EIT\Blueprint\BlueprintModule;
use EIT\Blueprint\MigrationService;
use EIT\Diagnostics\HandoffNotesGenerator;
use EIT\Diagnostics\ScenarioRunner;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\RunStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlueprintAdminController {

	const NAMESPACE = 'eit/v1';
	const MAX_BODY_BYTES = 524288;

	private $presenter;

	public function init_hooks() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		$permission = [ $this, 'can_manage' ];
		register_rest_route( self::NAMESPACE, '/blueprints', [
			[ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'list_blueprints' ], 'permission_callback' => $permission ],
			[ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'save_blueprint' ], 'permission_callback' => $permission ],
		] );
		register_rest_route( self::NAMESPACE, '/blueprints/(?P<id>[a-f0-9-]{36})', [
			[ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'get_blueprint' ], 'permission_callback' => $permission ],
			[ 'methods' => \WP_REST_Server::EDITABLE, 'callback' => [ $this, 'save_blueprint' ], 'permission_callback' => $permission ],
			[ 'methods' => \WP_REST_Server::DELETABLE, 'callback' => [ $this, 'delete_blueprint' ], 'permission_callback' => $permission ],
		] );
		$this->register_action_route( '/blueprints/(?P<id>[a-f0-9-]{36})/validate', 'validate_blueprint' );
		$this->register_action_route( '/blueprints/(?P<id>[a-f0-9-]{36})/impact', 'prepare_impact' );
		$this->register_action_route( '/blueprints/(?P<id>[a-f0-9-]{36})/rollback', 'rollback' );
		$this->register_action_route( '/change-sets/(?P<id>[a-f0-9-]{36})/apply', 'apply' );
		$this->register_action_route( '/change-sets/(?P<id>[a-f0-9-]{36})/reconcile', 'reconcile' );
		register_rest_route( self::NAMESPACE, '/blueprint-schema', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'schema' ], 'permission_callback' => $permission ] );
		register_rest_route( self::NAMESPACE, '/runs', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'runs' ], 'permission_callback' => $permission ] );
		register_rest_route( self::NAMESPACE, '/runs/(?P<id>[a-f0-9-]{36})', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'run' ], 'permission_callback' => $permission ] );
		register_rest_route( self::NAMESPACE, '/diagnostics', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'diagnostics' ], 'permission_callback' => $permission ] );
		register_rest_route( self::NAMESPACE, '/migration/inventory', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'migration_inventory' ], 'permission_callback' => $permission ] );
		$this->register_action_route( '/migration/prepare', 'prepare_migration' );
		$this->register_action_route( '/migration/apply', 'apply_migration' );
		register_rest_route( self::NAMESPACE, '/qa-scenarios', [
			[ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'scenarios' ], 'permission_callback' => $permission ],
			[ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'save_scenario' ], 'permission_callback' => $permission ],
		] );
		$this->register_action_route( '/qa-scenarios/(?P<id>[a-f0-9-]{36})/run', 'run_scenario' );
		register_rest_route( self::NAMESPACE, '/handoff-notes', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'handoff_notes' ], 'permission_callback' => $permission ] );
	}

	public function can_manage() {
		return current_user_can( AdminPages::CAPABILITY );
	}

	public function list_blueprints() {
		return rest_ensure_response( [ 'items' => $this->presenter()->systems() ] );
	}

	public function get_blueprint( \WP_REST_Request $request ) {
		$system = $this->presenter()->system( $request['id'] );
		return $system ? rest_ensure_response( $system ) : $this->error( 'eit_blueprint_not_found', __( 'Blueprint was not found.', 'elementor-implementation-toolkit' ), 404 );
	}

	public function save_blueprint( \WP_REST_Request $request ) {
		$bounded = $this->check_body( $request );
		if ( is_wp_error( $bounded ) ) {
			return $bounded;
		}
		$document = $request->get_param( 'document' );
		if ( ! is_array( $document ) ) {
			return $this->error( 'eit_blueprint_document_required', __( 'A Blueprint document is required.', 'elementor-implementation-toolkit' ), 400 );
		}
		if ( ! empty( $request['id'] ) && strtolower( (string) $request['id'] ) !== strtolower( (string) ( $document['id'] ?? '' ) ) ) {
			return $this->error( 'eit_blueprint_id_mismatch', __( 'Route and document Blueprint IDs do not match.', 'elementor-implementation-toolkit' ), 409 );
		}
		$result = BlueprintModule::lifecycle()->save_draft( $document );
		if ( is_wp_error( $result ) ) {
			return $this->status( $result, 422 );
		}
		return rest_ensure_response( $this->presenter()->system( $result['id'] ) );
	}

	public function delete_blueprint( \WP_REST_Request $request ) {
		$result = ( new BlueprintStore() )->delete_unpublished( $request['id'] );
		return is_wp_error( $result ) ? $this->status( $result, 409 ) : rest_ensure_response( [ 'deleted' => (bool) $result ] );
	}

	public function validate_blueprint( \WP_REST_Request $request ) {
		$result = BlueprintModule::lifecycle()->validate_draft( $request['id'] );
		return rest_ensure_response( $result->to_array() );
	}

	public function prepare_impact( \WP_REST_Request $request ) {
		$result = BlueprintModule::lifecycle()->prepare( $request['id'], get_current_user_id() );
		return is_wp_error( $result ) ? $this->status( $result, 422 ) : rest_ensure_response( $result );
	}

	public function apply( \WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'confirmation_token' );
		$result = BlueprintModule::lifecycle()->apply( $request['id'], $token, get_current_user_id() );
		return is_wp_error( $result ) ? $this->status( $result, 409 ) : rest_ensure_response( $result );
	}

	public function reconcile( \WP_REST_Request $request ) {
		$result = BlueprintModule::lifecycle()->reconcile( $request['id'] );
		return is_wp_error( $result ) ? $this->status( $result, 409 ) : rest_ensure_response( $result );
	}

	public function rollback( \WP_REST_Request $request ) {
		$target = sanitize_key( (string) $request->get_param( 'target' ) );
		$is_legacy = 'legacy' === $target;
		$version_id = absint( $request->get_param( 'version_id' ) );
		$reason = sanitize_textarea_field( $request->get_param( 'reason' ) );
		if ( ( ! $is_legacy && ! $version_id ) || '' === trim( $reason ) ) {
			return $this->error( 'eit_rollback_reason_required', __( 'Choose a rollback target and record a reason.', 'elementor-implementation-toolkit' ), 400 );
		}
		$result = BlueprintModule::lifecycle()->rollback( $request['id'], $is_legacy ? 'legacy' : $version_id, $reason, get_current_user_id() );
		return is_wp_error( $result ) ? $this->status( $result, 409 ) : rest_ensure_response( $this->presenter()->system( $request['id'] ) );
	}

	public function schema() {
		return rest_ensure_response( $this->presenter()->schema() );
	}

	public function runs( \WP_REST_Request $request ) {
		return rest_ensure_response( [ 'items' => $this->presenter()->recent_runs( min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?: 50 ) ) ) ) ] );
	}

	public function run( \WP_REST_Request $request ) {
		$run = ( new RunStore() )->with_events( $request['id'] );
		return $run ? rest_ensure_response( $run ) : $this->error( 'eit_run_not_found', __( 'Toolkit run was not found.', 'elementor-implementation-toolkit' ), 404 );
	}

	public function diagnostics() {
		return rest_ensure_response( $this->presenter()->diagnostics() );
	}

	public function migration_inventory() {
		return rest_ensure_response( ( new MigrationService() )->inventory() );
	}

	public function prepare_migration( \WP_REST_Request $request ) {
		$bounded = $this->check_body( $request );
		if ( is_wp_error( $bounded ) ) {
			return $bounded;
		}
		$selection = $request->get_param( 'selection' );
		$result = ( new MigrationService() )->prepare( is_array( $selection ) ? $selection : [], get_current_user_id() );
		return is_wp_error( $result ) ? $this->status( $result, 422 ) : rest_ensure_response( $result );
	}

	public function apply_migration( \WP_REST_Request $request ) {
		$result = ( new MigrationService() )->apply( (string) $request->get_param( 'confirmation_token' ), get_current_user_id() );
		return is_wp_error( $result ) ? $this->status( $result, 409 ) : rest_ensure_response( $result );
	}

	public function scenarios() {
		return rest_ensure_response( [ 'items' => ( new ScenarioRunner() )->all() ] );
	}

	public function save_scenario( \WP_REST_Request $request ) {
		$bounded = $this->check_body( $request );
		if ( is_wp_error( $bounded ) ) {
			return $bounded;
		}
		$scenario = $request->get_param( 'scenario' );
		$result = ( new ScenarioRunner() )->save( is_array( $scenario ) ? $scenario : [], get_current_user_id() );
		return is_wp_error( $result ) ? $this->status( $result, 422 ) : rest_ensure_response( $result );
	}

	public function run_scenario( \WP_REST_Request $request ) {
		$result = ( new ScenarioRunner() )->run( $request['id'] );
		return is_wp_error( $result ) ? $this->status( $result, 422 ) : rest_ensure_response( $result );
	}

	public function handoff_notes() {
		return rest_ensure_response( [ 'markdown' => ( new HandoffNotesGenerator() )->generate(), 'generated_at' => gmdate( 'c' ) ] );
	}

	private function register_action_route( $route, $callback ) {
		register_rest_route( self::NAMESPACE, $route, [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, $callback ], 'permission_callback' => [ $this, 'can_manage' ] ] );
	}

	private function check_body( \WP_REST_Request $request ) {
		return strlen( (string) $request->get_body() ) <= self::MAX_BODY_BYTES
			? true
			: $this->error( 'eit_blueprint_body_too_large', __( 'Blueprint request exceeds the 512 KB administrative limit.', 'elementor-implementation-toolkit' ), 413 );
	}

	private function status( \WP_Error $error, $status ) {
		$data = $error->get_error_data();
		$error->add_data( array_merge( is_array( $data ) ? $data : [], [ 'status' => $status ] ) );
		return $error;
	}

	private function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}

	private function presenter() {
		if ( null === $this->presenter ) {
			$this->presenter = new BlueprintAdminPresenter();
		}
		return $this->presenter;
	}
}
