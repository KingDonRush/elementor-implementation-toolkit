<?php
/**
 * Verifies the capability-gated Blueprint administration REST contract.
 *
 * Run with: wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-blueprint-admin.php
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\Uuid;
use EIT\Infrastructure\BlueprintStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assertions = 0;
$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$server = rest_get_server();
if ( ! isset( $server->get_routes()['/eit/v1/blueprints'] ) ) {
	do_action( 'rest_api_init' );
}
$dispatch = function ( $method, $path, array $body = null ) use ( $server ) {
	$request = new WP_REST_Request( $method, $path );
	if ( null !== $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	return $server->dispatch( $request );
};

$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admin_ids ), 'An administrator fixture is required.' );
$blueprint_id = Uuid::v4();
$entity_id = Uuid::v4();
$document = [
	'api_version' => BlueprintValidator::API_VERSION,
	'kind' => BlueprintValidator::KIND,
	'id' => $blueprint_id,
	'slug' => 'qa-admin-' . substr( str_replace( '-', '', $blueprint_id ), 0, 8 ),
	'name' => 'QA incomplete administrative draft',
	'version' => 1,
	'nodes' => [ [ 'id' => $entity_id, 'type' => 'entity', 'lane' => 'data', 'name' => 'Incomplete entity', 'config' => [ 'mode' => 'structured' ] ] ],
	'connections' => [],
];

try {
	wp_set_current_user( 0 );
	$forbidden = $dispatch( 'GET', '/eit/v1/blueprints' );
	$assert( in_array( $forbidden->get_status(), [ 401, 403 ], true ), 'Anonymous Blueprint administration was not blocked.' );

	wp_set_current_user( (int) $admin_ids[0] );
	$created = $dispatch( 'POST', '/eit/v1/blueprints', [ 'document' => $document ] );
	$assert( 200 === $created->get_status(), 'Incomplete draft could not be stored for correction.' );
	$assert( false === $created->get_data()['validation']['valid'], 'Incomplete draft was reported as valid.' );

	$validation = $dispatch( 'POST', '/eit/v1/blueprints/' . $blueprint_id . '/validate' );
	$assert( 200 === $validation->get_status() && false === $validation->get_data()['valid'], 'Validation did not expose the incomplete graph.' );
	$impact = $dispatch( 'POST', '/eit/v1/blueprints/' . $blueprint_id . '/impact' );
	$assert( 422 === $impact->get_status(), 'Impact preparation did not block the incomplete draft.' );

	$mismatch = $dispatch( 'PUT', '/eit/v1/blueprints/' . Uuid::v4(), [ 'document' => $document ] );
	$assert( 409 === $mismatch->get_status(), 'Route and document ID mismatch was not blocked.' );
	$large = new WP_REST_Request( 'POST', '/eit/v1/blueprints' );
	$large->set_body( str_repeat( 'x', 524289 ) );
	$assert( 413 === $server->dispatch( $large )->get_status(), 'Administrative request body bound was not enforced.' );

	$schema = $dispatch( 'GET', '/eit/v1/blueprint-schema' );
	$assert( 200 === $schema->get_status() && isset( $schema->get_data()['node_types']['entity'] ), 'Blueprint schema endpoint is incomplete.' );
	$list = $dispatch( 'GET', '/eit/v1/blueprints' );
	$ids = array_column( $list->get_data()['items'], 'id' );
	$assert( in_array( $blueprint_id, $ids, true ), 'Stored draft is absent from Systems.' );

	$deleted = $dispatch( 'DELETE', '/eit/v1/blueprints/' . $blueprint_id );
	$assert( 200 === $deleted->get_status() && true === $deleted->get_data()['deleted'], 'Unpublished draft could not be deleted.' );
	$missing = $dispatch( 'GET', '/eit/v1/blueprints/' . $blueprint_id );
	$assert( 404 === $missing->get_status(), 'Deleted Blueprint still resolves.' );
} finally {
	wp_set_current_user( (int) $admin_ids[0] );
	( new BlueprintStore() )->delete_unpublished( $blueprint_id );
}

WP_CLI::success( sprintf( 'Blueprint admin REST verification passed: %d assertions.', $assertions ) );
