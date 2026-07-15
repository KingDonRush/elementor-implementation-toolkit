<?php
/**
 * WordPress integration verification for governed Entry Surface workflows.
 */

use EIT\Blueprint\BlueprintModule;
use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\RuntimeDefinitionProvider;
use EIT\Blueprint\Uuid;
use EIT\CCT\Repository;
use EIT\CCT\SchemaManager as CctSchema;
use EIT\Entry\EntrySubmissionService;
use EIT\Infrastructure\EntryActionStore;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\Tables;

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

$ids = [
	'blueprint' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:blueprint' ),
	'entity' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:entity' ),
	'group' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:group' ),
	'entry' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:surface' ),
	'policy' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:policy' ),
	'title' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:title' ),
	'quantity' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:quantity' ),
	'choice' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:choice' ),
	'note' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:note' ),
	'repeater' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:repeater' ),
	'child' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:child' ),
	'total' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:total' ),
	'condition' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:condition' ),
	'action' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:action' ),
	'step' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:step' ),
];
$slug = 'eit_entry_verify';
$created_user = 0;

$cleanup = function () use ( $ids, $slug, &$created_user ) {
	global $wpdb;
	$jobs = ( new EntryActionStore() )->recent( 200 );
	foreach ( $jobs as $job ) {
		if ( $ids['blueprint'] === $job['blueprint_id'] ) {
			wp_clear_scheduled_hook( 'eit_retry_entry_action', [ $job['id'] ] );
		}
	}
	foreach ( [ Tables::ACTION_JOBS, Tables::ENTRY_SUBMISSIONS, Tables::RELATIONS, Tables::MULTIVALUES, Tables::RECONCILIATIONS, Tables::ROLLBACKS, Tables::RUNS, Tables::BINDINGS, Tables::ARTIFACTS, Tables::CHANGE_SETS, Tables::VERSIONS ] as $table_key ) {
		$wpdb->delete( Tables::name( $table_key ), [ 'blueprint_id' => $ids['blueprint'] ] );
	}
	$wpdb->delete( Tables::name( Tables::BLUEPRINTS ), [ 'id' => $ids['blueprint'] ] );
	$wpdb->query( 'DROP TABLE IF EXISTS `' . CctSchema::table_name( $slug ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( $created_user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $created_user );
		$created_user = 0;
	}
	RuntimeDefinitionProvider::invalidate();
};

try {
	$cleanup();
	$schema = SchemaManager::install();
	$assert( true === $schema, 'Entry infrastructure schema must install.' );
	$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
	$assert( ! empty( $admin[0] ), 'An administrator is required for Entry verification.' );
	wp_set_current_user( (int) $admin[0] );

	$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
	$fields = [
		$factory->make( $ids['title'], 'Title', 'short_text', [ 'validation' => [ 'required' => true ] ] ),
		$factory->make( $ids['quantity'], 'Quantity', 'integer', [ 'validation' => [ 'required' => true, 'min' => 0 ], 'indexing' => [ 'filter' => true, 'sort' => true ] ] ),
		$factory->make( $ids['choice'], 'Tier', 'single_choice', [ 'validation' => [ 'options' => [ [ 'value' => 'basic', 'label' => 'Basic' ], [ 'value' => 'premium', 'label' => 'Premium' ] ] ] ] ),
		$factory->make( $ids['note'], 'Premium note', 'short_text' ),
		$factory->make( $ids['repeater'], 'Rows', 'repeatable_group', [ 'validation' => [ 'children' => [ [ 'id' => $ids['child'], 'name' => 'Row label', 'type' => 'short_text' ] ] ] ] ),
		$factory->make( $ids['total'], 'Total', 'calculated', [ 'validation' => [ 'expression' => '{' . $ids['quantity'] . '} * 2' ] ] ),
	];
	$document = [
		'api_version' => BlueprintValidator::API_VERSION,
		'kind' => BlueprintValidator::KIND,
		'id' => $ids['blueprint'],
		'slug' => 'verify-entry-surface',
		'name' => 'Entry verification system',
		'version' => 1,
		'nodes' => [
			[ 'id' => $ids['entity'], 'type' => 'entity', 'lane' => 'data', 'name' => 'Verification item', 'config' => [ 'slug' => $slug, 'mode' => 'structured', 'public' => false, 'high_volume' => true ] ],
			[ 'id' => $ids['group'], 'type' => 'field_group', 'lane' => 'data', 'name' => 'Verification fields', 'config' => [ 'fields' => $fields ] ],
			[ 'id' => $ids['entry'], 'type' => 'entry_surface', 'lane' => 'experience', 'name' => 'Verification workspace', 'config' => [
				'operations' => [ 'create', 'update', 'submit_review', 'publish', 'archive', 'restore' ],
				'initial_status' => 'draft',
				'title_field_id' => $ids['title'],
				'steps' => [ [ 'id' => $ids['step'], 'name' => 'Details', 'field_ids' => array_column( $fields, 'id' ) ] ],
				'conditions' => [ [ 'id' => $ids['condition'], 'source_field_id' => $ids['choice'], 'operator' => 'equals', 'value' => 'premium', 'effect' => 'show', 'target_field_id' => $ids['note'] ] ],
				'actions' => [ [ 'id' => $ids['action'], 'type' => 'webhook', 'events' => [ 'created' ], 'config' => [ 'url' => 'http://127.0.0.1/eit-entry-verification' ] ] ],
				'autosave' => [ 'enabled' => true, 'interval_seconds' => 30 ],
				'guest' => [ 'enabled' => false ],
			] ],
			[ 'id' => $ids['policy'], 'type' => 'policy', 'lane' => 'governance', 'name' => 'Owned authoring', 'config' => [ 'capability' => 'edit_posts', 'publish_capability' => 'publish_posts', 'ownership' => 'own', 'object_scope' => 'entity' ] ],
		],
		'connections' => [
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:edge:fields' ), 'type' => 'entity_fields', 'from' => $ids['entity'], 'to' => $ids['group'] ],
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:edge:surface' ), 'type' => 'entry_for', 'from' => $ids['entity'], 'to' => $ids['entry'] ],
			[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:edge:policy' ), 'type' => 'governs_entry', 'from' => $ids['policy'], 'to' => $ids['entry'] ],
		],
	];
	$lifecycle = BlueprintModule::lifecycle();
	$saved = $lifecycle->save_draft( $document );
	$assert( ! is_wp_error( $saved ), 'Entry Blueprint draft must save.' );
	$prepared = $lifecycle->prepare( $ids['blueprint'], get_current_user_id() );
	$assert( ! is_wp_error( $prepared ) && ! empty( $prepared['confirmation_token'] ), 'Entry Blueprint impact must prepare.' );
	$published = $lifecycle->apply( $prepared['id'], $prepared['confirmation_token'], get_current_user_id() );
	$assert( ! is_wp_error( $published ), 'Entry Blueprint must publish.' );

	$service = new EntrySubmissionService();
	$payload = [
		'surface_id' => $ids['entry'],
		'intent' => 'default',
		'idempotency_key' => 'verify-entry-idempotency-1',
		'values' => [ $ids['title'] => 'Zero quantity', $ids['quantity'] => '0', $ids['choice'] => 'basic', $ids['note'] => 'hidden value', $ids['repeater'] => [ [ $ids['child'] => 'First row' ] ] ],
	];
	$created = $service->submit( $payload );
	$assert( ! is_wp_error( $created ), 'Entry submission must create content.' );
	$assert( 'draft' === $created['status'], 'Default create must honor the initial draft status.' );
	$assert( 'retryable_failure' === ( $created['actions'][0]['status'] ?? '' ), 'Unsafe local webhook must fail as a retryable job.' );
	$replayed = $service->submit( $payload );
	$assert( ! is_wp_error( $replayed ) && ! empty( $replayed['replayed'] ) && $created['item_id'] === $replayed['item_id'], 'Identical idempotent submission must replay without a duplicate.' );
	$rows = ( new Repository() )->query( $slug, [ 'status' => [ 'draft' ], 'per_page' => 10 ] );
	$assert( 1 === $rows['total'], 'Idempotent replay must leave exactly one content row.' );
	$assert( 0.0 === $rows['items'][0][ $fields[5]['storage']['key'] ], 'Calculated zero must persist as zero.' );

	$changed = $payload;
	$changed['values'][ $ids['quantity'] ] = 1;
	$mismatch = $service->submit( $changed );
	$assert( is_wp_error( $mismatch ) && 'eit_entry_idempotency_mismatch' === $mismatch->get_error_code(), 'Reusing an idempotency key for different values must fail.' );

	$update = $payload;
	$update['item_id'] = $created['item_id'];
	$update['intent'] = 'publish';
	$update['idempotency_key'] = 'verify-entry-idempotency-2';
	$update['values'][ $ids['quantity'] ] = 2;
	$published_item = $service->submit( $update );
	$assert( ! is_wp_error( $published_item ) && 'publish' === $published_item['status'], 'Authorized publish transition must succeed.' );

	$new_user = wp_insert_user( [ 'user_login' => 'eit_entry_verify_author', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'eit-entry-verify@example.invalid', 'role' => 'author' ] );
	$assert( ! is_wp_error( $new_user ), 'Ownership verification user must be created.' );
	$created_user = (int) $new_user;
	wp_set_current_user( $created_user );
	$foreign = $update;
	$foreign['idempotency_key'] = 'verify-entry-idempotency-foreign';
	$forbidden = $service->submit( $foreign );
	$assert( is_wp_error( $forbidden ) && 'eit_entry_ownership_forbidden' === $forbidden->get_error_code(), 'Browser item ID must not bypass ownership.' );

	wp_set_current_user( (int) $admin[0] );
	$archive = $service->submit( [ 'surface_id' => $ids['entry'], 'item_id' => $created['item_id'], 'intent' => 'archive', 'idempotency_key' => 'verify-entry-idempotency-3', 'values' => [] ] );
	$assert( ! is_wp_error( $archive ) && 'archived' === $archive['status'], 'Archive transition must preserve content.' );
	$restore = $service->submit( [ 'surface_id' => $ids['entry'], 'item_id' => $created['item_id'], 'intent' => 'restore', 'idempotency_key' => 'verify-entry-idempotency-4', 'values' => [] ] );
	$assert( ! is_wp_error( $restore ) && 'draft' === $restore['status'], 'Restore transition must reactivate content as a draft.' );

	$rest = rest_do_request( new WP_REST_Request( 'GET', '/eit/v1/entry-surfaces/' . $ids['entry'] ) );
	$assert( 200 === $rest->get_status(), 'Entry contract REST projection must be readable by an authorized actor.' );
	$projection = $rest->get_data();
	$assert( ! isset( $projection['fields'][0]['storage'] ), 'Browser contract must not expose storage keys.' );
	$html = do_shortcode( '[eit_entry_surface id="' . $ids['entry'] . '"]' );
	$assert( false !== strpos( $html, 'data-eit-entry-workspace' ) && false === stripos( $html, 'gutenberg' ), 'Frontend workspace must render without accidental Gutenberg UI.' );

	$jobs = array_values( array_filter( ( new EntryActionStore() )->recent( 100 ), fn( $job ) => $ids['blueprint'] === $job['blueprint_id'] ) );
	$assert( 1 === count( $jobs ) && 'failed' === $jobs[0]['status'], 'Failed external action must remain visible and retryable without duplicate content.' );

	echo 'Entry Surface verification passed: ' . $assertions . " assertions.\n";
} finally {
	$cleanup();
}
