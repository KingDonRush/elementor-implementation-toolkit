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
use EIT\Entry\EntryReferenceValidator;
use EIT\Entry\EntrySurfaceResolver;
use EIT\Entry\GuestIntakeGuard;
use EIT\Entry\PendingUploadStore;
use EIT\Infrastructure\EntryActionStore;
use EIT\Infrastructure\EntrySubmissionStore;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$assertions = 0;
$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
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
	'media' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:media' ),
	'condition' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:condition' ),
	'action' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:action' ),
	'step' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry:step' ),
];
$slug = 'eit_entry_verify';
$created_user = 0;
$reference_posts = [];
$reference_term = 0;
$pending_attachment = 0;

$cleanup = function () use ( $ids, $slug, &$created_user, &$reference_posts, &$reference_term, &$pending_attachment ) {
	global $wpdb;
	$pending_table = Tables::name( Tables::PENDING_UPLOADS );
	$pending_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,surface_id FROM `{$pending_table}` WHERE blueprint_id = %s", $ids['blueprint'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $pending_rows ?: [] as $pending ) {
		wp_clear_scheduled_hook( 'eit_cleanup_entry_pending_upload', [ $pending['id'], $pending['surface_id'] ] );
		( new PendingUploadStore() )->cleanup( $pending['id'], $pending['surface_id'], true );
		( new PendingUploadStore() )->cleanup( $pending['id'], $pending['surface_id'], true );
	}
	if ( $pending_attachment ) {
		wp_delete_attachment( $pending_attachment, true );
		$pending_attachment = 0;
	}
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
	$stale_reference_posts = get_posts(
		[
			'post_type' => 'any',
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_key' => '_eit_verify_entry_reference',
			'meta_value' => $ids['blueprint'],
		]
	);
	foreach ( $stale_reference_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $reference_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	$reference_posts = [];
	if ( $reference_term ) {
		wp_delete_term( $reference_term, 'category' );
		$reference_term = 0;
	} else {
		$stale_term = get_term_by( 'slug', 'eit-entry-reference', 'category' );
		if ( $stale_term ) {
			wp_delete_term( $stale_term->term_id, 'category' );
		}
	}
	RuntimeDefinitionProvider::invalidate();
};

try {
	$cleanup();
	$schema = SchemaManager::install();
	$assert( true === $schema, 'Entry infrastructure schema must install.' );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
	$guest_guard = new GuestIntakeGuard();
	$guest_contract = [ 'surface_id' => $ids['entry'], 'guest' => [ 'rate_limit_per_hour' => 1 ] ];
	$remote_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	$rate_option = 'eit_entry_rate_' . gmdate( 'YmdH' ) . '_' . hash( 'sha256', 'upload|' . $ids['entry'] . '|' . $remote_address );
	delete_option( $rate_option );
	for ( $attempt = 0; $attempt < 5; ++$attempt ) {
		$assert( true === $guest_guard->consume_upload( $guest_contract ), 'Atomic guest upload allowance rejected an in-budget request.' );
	}
	$rate_limited = $guest_guard->consume_upload( $guest_contract );
	$assert( is_wp_error( $rate_limited ) && 'eit_entry_guest_rate_limited' === $rate_limited->get_error_code(), 'Atomic guest limiter exceeded its exact hourly allowance.' );
	delete_option( $rate_option );
	wp_clear_scheduled_hook( 'eit_cleanup_entry_rate', [ $rate_option ] );
	$verify_pending_uploads = require __DIR__ . '/verification/entry/pending-upload-contract.php';
	$pending_table = $verify_pending_uploads( $assert, $ids, $guest_guard );
	$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
	$assert( ! empty( $admin[0] ), 'An administrator is required for Entry verification.' );
	wp_set_current_user( (int) $admin[0] );
	$term = wp_insert_term( 'EIT Entry Reference', 'category', [ 'slug' => 'eit-entry-reference' ] );
	$assert( ! is_wp_error( $term ), 'Entry reference taxonomy fixture must be created.' );
	$reference_term = (int) $term['term_id'];
	$reference_posts[] = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Allowed relation target', 'post_author' => (int) $admin[0], 'meta_input' => [ '_eit_verify_entry_reference' => $ids['blueprint'] ] ] );
	$reference_posts[] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Foreign entity target', 'post_author' => (int) $admin[0], 'meta_input' => [ '_eit_verify_entry_reference' => $ids['blueprint'] ] ] );
	$reference_contract = [ 'entity' => [ 'strategy' => 'cpt', 'definition' => [ 'slug' => 'post' ] ], 'fields' => [
		[ 'id' => $ids['choice'], 'type' => 'taxonomy', 'storage' => [ 'key' => 'category' ], 'taxonomy' => [ 'slug' => 'category' ] ],
		[ 'id' => $ids['note'], 'type' => 'relation', 'relation' => [ 'target_entity_id' => $ids['entity'], 'target' => [ 'strategy' => 'cpt', 'definition' => [ 'slug' => 'post', 'public' => true ], 'adapter' => [] ], 'ownership' => 'any', 'object_scope' => 'entity' ] ],
	] ];
	$reference_validator = new EntryReferenceValidator();
	$assert( true === $reference_validator->validate( $reference_contract, [ $ids['choice'] => [ $reference_term ], $ids['note'] => [ [ 'id' => $reference_posts[0] ] ] ] ), 'Existing authorized taxonomy and relation targets must pass.' );
	$guest_owned_contract = $reference_contract;
	$guest_owned_contract['fields'][1]['relation']['ownership'] = 'own';
	$guest_owned = $reference_validator->validate( $guest_owned_contract, [ $ids['note'] => [ [ 'id' => $reference_posts[0] ] ] ], true );
	$assert( is_wp_error( $guest_owned ) && 'eit_entry_reference_forbidden' === $guest_owned->get_error_code(), 'Guest user zero must never satisfy owned relation scope.' );
	$foreign_reference = $reference_validator->validate( $reference_contract, [ $ids['choice'] => [ $reference_term ], $ids['note'] => [ [ 'id' => $reference_posts[1] ] ] ] );
	$assert( is_wp_error( $foreign_reference ) && 'eit_entry_reference_forbidden' === $foreign_reference->get_error_code(), 'A relation ID from another Entity must be rejected before persistence.' );

	$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
	$fields = [
		$factory->make( $ids['title'], 'Title', 'short_text', [ 'validation' => [ 'required' => true ] ] ),
		$factory->make( $ids['quantity'], 'Quantity', 'integer', [ 'validation' => [ 'required' => true, 'min' => 0 ], 'indexing' => [ 'filter' => true, 'sort' => true ] ] ),
		$factory->make( $ids['choice'], 'Tier', 'single_choice', [ 'validation' => [ 'options' => [ [ 'value' => 'basic', 'label' => 'Basic' ], [ 'value' => 'premium', 'label' => 'Premium' ] ] ] ] ),
		$factory->make( $ids['note'], 'Premium note', 'short_text' ),
		$factory->make( $ids['repeater'], 'Rows', 'repeatable_group', [ 'validation' => [ 'children' => [ [ 'id' => $ids['child'], 'name' => 'Row label', 'type' => 'short_text' ] ] ] ] ),
		$factory->make( $ids['total'], 'Total', 'calculated', [ 'validation' => [ 'expression' => '{' . $ids['quantity'] . '} * 2' ] ] ),
		$factory->make( $ids['media'], 'Reference image', 'image' ),
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
				'guest' => [ 'enabled' => true, 'moderation_status' => 'review', 'minimum_seconds' => 2, 'rate_limit_per_hour' => 5, 'upload_max_bytes' => 1048576, 'upload_mime_types' => [ 'image/png' ] ],
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
	$entry_contract = ( new EntrySurfaceResolver() )->get( $ids['entry'] );
	$assert( $entry_contract && ! empty( $entry_contract['version_id'] ) && ! empty( $entry_contract['artifact_checksum'] ), 'Published Entry contract identity must be available for pending media.' );
	$pending_store = new PendingUploadStore( '', fn( $source, $destination ) => rename( $source, $destination ) );
	wp_set_current_user( 0 );
	$_SERVER['HTTP_USER_AGENT'] = 'EIT guest integration';
	$submission_rate_option = 'eit_entry_rate_' . gmdate( 'YmdH' ) . '_' . hash( 'sha256', 'submission|' . $ids['entry'] . '|' . $remote_address );
	delete_option( $submission_rate_option );
	wp_clear_scheduled_hook( 'eit_cleanup_entry_rate', [ $submission_rate_option ] );
	$guest_source = wp_tempnam( 'eit-entry-guest.png' );
	file_put_contents( $guest_source, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' ) );
	$guest_pending = $pending_store->quarantine(
		[ 'blueprint_id' => $ids['blueprint'], 'version_id' => $entry_contract['version_id'], 'contract_checksum' => $entry_contract['artifact_checksum'], 'surface_id' => $ids['entry'], 'field_id' => $ids['media'], 'actor_key' => $guest_guard->actor_key() ],
		[ 'tmp_name' => $guest_source, 'original_name' => 'guest.png', 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => filesize( $guest_source ) ]
	);
	$issued = time() - 3;
	$nonce = 'eit-guest-integration';
	$form_payload = $issued . '|' . $nonce;
	$form_signature = hash_hmac( 'sha256', $ids['entry'] . '|' . $form_payload, wp_salt( 'nonce' ) );
	$form_token = rtrim( strtr( base64_encode( $form_payload . '|' . $form_signature ), '+/', '-_' ), '=' );
	$guest_payload = [
		'surface_id' => $ids['entry'],
		'intent' => 'default',
		'idempotency_key' => 'verify-entry-guest-upload-1',
		'form_token' => $form_token,
		'company_website' => '',
		'values' => [ $ids['title'] => 'Guest upload', $ids['quantity'] => 1, $ids['choice'] => 'basic', $ids['media'] => $guest_pending ],
	];
	$guest_created = ( new EntrySubmissionService() )->submit( $guest_payload );
	$guest_pending_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$pending_table}` WHERE token_hash = %s", hash( 'sha256', $guest_pending['pending_token'] ?? '' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$guest_attachment = absint( $guest_pending_row['attachment_id'] ?? 0 );
	$assert( ! is_wp_error( $guest_created ) && 'review' === $guest_created['status'] && 'consumed' === ( $guest_pending_row['status'] ?? '' ) && $guest_attachment && ! get_post_meta( $guest_attachment, '_eit_entry_pending_surface', true ), 'A real logged-out guest submission did not consume protected media into moderated content.' );
	$guest_reuse = $guest_payload;
	$guest_reuse['idempotency_key'] = 'verify-entry-guest-upload-2';
	$assert( is_wp_error( ( new EntrySubmissionService() )->submit( $guest_reuse ) ), 'A consumed guest token was accepted by a second submission.' );
	( new Repository() )->delete( $slug, $guest_created['item_id'] );
	wp_delete_attachment( $guest_attachment, true );
	wp_set_current_user( (int) $admin[0] );

	$submission_source = wp_tempnam( 'eit-entry-submission.png' );
	file_put_contents( $submission_source, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' ) );
	$submission_pending = $pending_store->quarantine(
		[ 'blueprint_id' => $ids['blueprint'], 'version_id' => $entry_contract['version_id'], 'contract_checksum' => $entry_contract['artifact_checksum'], 'surface_id' => $ids['entry'], 'field_id' => $ids['media'], 'actor_key' => $guest_guard->actor_key() ],
		[ 'tmp_name' => $submission_source, 'original_name' => 'submission.png', 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => filesize( $submission_source ) ]
	);
	$assert( ! is_wp_error( $submission_pending ), 'Protected pending media fixture could not be issued for Entry submission.' );
	$service = new EntrySubmissionService();
	$payload = [
		'surface_id' => $ids['entry'],
		'intent' => 'default',
		'idempotency_key' => 'verify-entry-idempotency-1',
		'values' => [ $ids['title'] => 'Zero quantity', $ids['quantity'] => '0', $ids['choice'] => 'basic', $ids['note'] => 'hidden value', $ids['repeater'] => [ [ $ids['child'] => 'First row' ] ], $ids['media'] => $submission_pending ],
	];
	$created = $service->submit( $payload );
	$assert( ! is_wp_error( $created ), 'Entry submission must create content.' );
	$assert( 'draft' === $created['status'], 'Default create must honor the initial draft status.' );
	$submission_pending_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$pending_table}` WHERE token_hash = %s", hash( 'sha256', $submission_pending['pending_token'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$pending_attachment = absint( $submission_pending_row['attachment_id'] ?? 0 );
	$assert( 'consumed' === ( $submission_pending_row['status'] ?? '' ) && $pending_attachment && ! get_post_meta( $pending_attachment, '_eit_entry_pending_upload_id', true ), 'Entry submission did not atomically consume its protected pending media token.' );
	$assert( 'retryable_failure' === ( $created['actions'][0]['status'] ?? '' ), 'Unsafe local webhook must fail as a retryable job.' );
	$replayed = $service->submit( $payload );
	$assert( ! is_wp_error( $replayed ) && ! empty( $replayed['replayed'] ) && $created['item_id'] === $replayed['item_id'], 'Identical idempotent submission must replay without a duplicate.' );
	global $wpdb;
	$recovery_response = [
		'submission_id' => $created['submission_id'],
		'request_id' => $created['request_id'],
		'item_id' => $created['item_id'],
		'status' => $created['status'],
		'event' => $created['event'],
		'_recovery' => [ 'actor_id' => get_current_user_id(), 'is_guest' => false, 'pending_attachment_ids' => [ $pending_attachment ] ],
	];
	$wpdb->update( $pending_table, [ 'status' => 'promoted', 'updated_at' => current_time( 'mysql', true ) ], [ 'attachment_id' => $pending_attachment, 'submission_id' => $created['submission_id'] ] );
	update_post_meta( $pending_attachment, '_eit_entry_pending_upload_id', $submission_pending_row['id'] );
	$wpdb->update(
		Tables::name( Tables::ENTRY_SUBMISSIONS ),
		[ 'status' => 'persisted', 'response' => wp_json_encode( $recovery_response ), 'updated_at' => current_time( 'mysql', true ) ],
		[ 'id' => $created['submission_id'] ]
	);
	$recovered = $service->submit( $payload );
	$assert( ! is_wp_error( $recovered ) && ! empty( $recovered['replayed'] ) && $created['item_id'] === $recovered['item_id'], 'A persisted interruption must reconcile without creating content again.' );
	$recovered_pending_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$pending_table}` WHERE id = %s", $submission_pending_row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$assert( 'consumed' === $recovered_pending_status && ! get_post_meta( $pending_attachment, '_eit_entry_pending_upload_id', true ), 'Persisted recovery did not consume pending media before replaying actions.' );
	$rows = ( new Repository() )->query( $slug, [ 'status' => [ 'draft' ], 'per_page' => 10 ] );
	$assert( 1 === $rows['total'], 'Idempotent replay must leave exactly one content row.' );
	$assert( 0.0 === $rows['items'][0][ $fields[5]['storage']['key'] ], 'Calculated zero must persist as zero.' );
	$blocked_service = new EntrySubmissionService( [ 'references' => new class() extends EntryReferenceValidator {
		public function validate( array $contract, array $values, $guest = false, $item_id = 0 ) {
			return new WP_Error( 'eit_entry_reference_forbidden', 'QA reference denial.', [ 'status' => 403 ] );
		}
	} ] );
	$blocked_payload = $payload;
	$blocked_payload['idempotency_key'] = 'verify-entry-reference-blocked';
	$blocked_payload['values'][ $ids['title'] ] = 'Must not persist';
	$blocked = $blocked_service->submit( $blocked_payload );
	$rows_after_block = ( new Repository() )->query( $slug, [ 'status' => [ 'draft' ], 'per_page' => 10 ] );
	$assert( is_wp_error( $blocked ) && 'eit_entry_reference_forbidden' === $blocked->get_error_code(), 'Reference authorization must stop submission.' );
	$assert( 1 === $rows_after_block['total'], 'Reference denial happened after persistence.' );

	$changed = $payload;
	$changed['values'][ $ids['quantity'] ] = 1;
	$mismatch = $service->submit( $changed );
	$assert( is_wp_error( $mismatch ) && 'eit_entry_idempotency_mismatch' === $mismatch->get_error_code(), 'Reusing an idempotency key for different values must fail.' );

	$submission_store = new EntrySubmissionStore();
	$lease_identity = [
		'blueprint_id' => $ids['blueprint'],
		'surface_id' => $ids['entry'],
		'actor_key' => hash( 'sha256', 'lease-actor' ),
		'idempotency_hash' => hash( 'sha256', 'lease-key' ),
		'payload_checksum' => hash( 'sha256', 'lease-payload' ),
		'operation' => 'create',
	];
	$lease = $submission_store->claim( $lease_identity );
	$wpdb->update( Tables::name( Tables::ENTRY_SUBMISSIONS ), [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - EntrySubmissionStore::LEASE_SECONDS - 1 ) ], [ 'id' => $lease['record']['id'] ] );
	$reclaimed = $submission_store->claim( $lease_identity );
	$assert( ! is_wp_error( $reclaimed ) && $reclaimed['claimed'] && $lease['record']['id'] === $reclaimed['record']['id'], 'An expired processing lease must be reclaimed without changing submission identity.' );
	$submission_store->fail( $reclaimed['record']['id'], new WP_Error( 'qa_lease_cleanup', 'QA lease cleanup.' ) );

	$update = $payload;
	$update['item_id'] = $created['item_id'];
	$update['intent'] = 'publish';
	$update['idempotency_key'] = 'verify-entry-idempotency-2';
	$update['values'][ $ids['quantity'] ] = 2;
	$update['values'][ $ids['media'] ] = [ 'id' => $pending_attachment ];
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
	$wpdb->update( Tables::name( Tables::ACTION_JOBS ), [ 'status' => 'running', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - EntryActionStore::LEASE_SECONDS - 1 ) ], [ 'id' => $jobs[0]['id'] ] );
	$reclaimed_job = ( new EntryActionStore() )->claim( $jobs[0]['id'] );
	$assert( ! empty( $reclaimed_job ) && 'running' === $reclaimed_job['status'] && 2 === $reclaimed_job['attempts'], 'An interrupted action job must become retryable after its lease expires.' );
	( new EntryActionStore() )->finish( $jobs[0]['id'], new WP_Error( 'qa_retryable_action', 'QA retryable action.' ) );

	echo 'Entry Surface verification passed: ' . esc_html( (string) $assertions ) . " assertions.\n";
} finally {
	$cleanup();
}
