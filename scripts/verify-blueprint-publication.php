<?php
/**
 * WordPress integration verification for Blueprint publication authority.
 *
 * Run with:
 * wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-blueprint-publication.php
 */

use EIT\Blueprint\Canonicalizer;
use EIT\Blueprint\Compiler;
use EIT\Blueprint\LegacyImporter;
use EIT\Blueprint\LifecycleLeaseGuard;
use EIT\Blueprint\LifecycleService;
use EIT\Blueprint\MigrationPublicationGuard;
use EIT\Blueprint\MigrationService;
use EIT\Blueprint\ShadowComparator;
use EIT\Blueprint\StorageOwnershipValidator;
use EIT\Blueprint\Uuid;
use EIT\CPT\DefinitionManager as CptDefinitions;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\ChangeSetStore;
use EIT\Infrastructure\LockStore;
use EIT\Infrastructure\MigrationStore;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\StorageClaimStore;
use EIT\Infrastructure\Tables;
use EIT\Infrastructure\VersionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assertions = 0;
$blueprint_id = '';
$collision_blueprint_id = '';
$registered_post_type = false;
$lease_resources = [];
$suffix = substr( str_replace( '-', '', Uuid::v4() ), 0, 8 );
$source_key = 'eit_gate_' . $suffix;
$legacy_before = get_option( CptDefinitions::OPTION, [] );
$legacy_before = is_array( $legacy_before ) ? $legacy_before : [];

$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};

$cleanup = function () use ( &$blueprint_id, &$collision_blueprint_id, &$registered_post_type, &$lease_resources, $source_key, $legacy_before ) {
	global $wpdb;

	if ( $registered_post_type && post_type_exists( $source_key ) ) {
		unregister_post_type( $source_key );
	}
	update_option( CptDefinitions::OPTION, $legacy_before, false );
	$lock_table = Tables::name( Tables::LOCKS );
	foreach ( $lease_resources as $lease_resource ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$lock_table}` WHERE resource_key = %s", $lease_resource ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$blueprint_ids = array_filter( [ $blueprint_id, $collision_blueprint_id ] );
	if ( ! $blueprint_ids ) {
		return;
	}
	$run_table = Tables::name( Tables::RUNS );
	$event_table = Tables::name( Tables::RUN_EVENTS );
	foreach ( $blueprint_ids as $cleanup_id ) {
		$run_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$run_table}` WHERE blueprint_id = %s", $cleanup_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $run_ids ?: [] as $run_id ) {
			$wpdb->delete( $event_table, [ 'run_id' => $run_id ] );
		}
		foreach ( Tables::keys() as $table_key ) {
			$table = Tables::name( $table_key );
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( in_array( 'blueprint_id', $columns ?: [], true ) ) {
				$wpdb->delete( $table, [ 'blueprint_id' => $cleanup_id ] );
			}
		}
		$wpdb->delete( Tables::name( Tables::BLUEPRINTS ), [ 'id' => $cleanup_id ] );
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$lock_table}` WHERE resource_key = %s", 'blueprint-' . $cleanup_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
};

try {
	$cleanup();
	$assert( true === SchemaManager::install() && true === SchemaManager::verify(), 'Publication authority infrastructure is unavailable.' );
	$legacy = $legacy_before;
	$legacy[ $source_key ] = [
		'slug' => $source_key,
		'singular' => 'Authority record',
		'plural' => 'Authority records',
		'description' => '',
		'menu_icon' => 'dashicons-screenoptions',
		'public' => false,
		'show_in_rest' => false,
		'has_archive' => false,
		'hierarchical' => false,
		'rewrite_slug' => '',
		'supports' => [ 'title' ],
		'taxonomies' => [],
		'meta_fields' => [ [ 'key' => 'legacy_code', 'label' => 'Legacy code', 'type' => 'text', 'show_in_rest' => true ] ],
	];
	$assert( update_option( CptDefinitions::OPTION, $legacy, false ), 'Legacy fixture could not be written.' );

	$importer = new LegacyImporter();
	$candidate = $importer->candidate( 'cpt', $source_key );
	$assert( is_array( $candidate ) && is_array( $candidate['blueprint'] ?? null ), 'Legacy fixture did not produce a Blueprint candidate.' );
	$blueprint_id = $candidate['blueprint']['id'];
	$lifecycle = new LifecycleService();
	$draft = $lifecycle->save_draft( $candidate['blueprint'] );
	$assert( ! is_wp_error( $draft ), 'Imported Blueprint draft could not be stored.' );

	$migrations = new MigrationStore();
	$comparison = ( new ShadowComparator() )->compare( 'cpt', $source_key, $draft['draft_document'] );
	$assert( 'verified' === ( $comparison['status'] ?? '' ) && 64 === strlen( (string) ( $comparison['compiler_checksum'] ?? '' ) ), 'Independent migration evidence could not be produced.' );
	$evidence = $migrations->save(
		[
			'source_type' => 'cpt',
			'source_key' => $source_key,
			'blueprint_id' => $blueprint_id,
			'source_checksum' => $candidate['source_checksum'],
			'draft_checksum' => $draft['draft_checksum'],
			'status' => 'verified',
			'comparison' => $comparison,
		]
	);
	$assert( ! is_wp_error( $evidence ) && 1 === count( $migrations->for_blueprint( $blueprint_id ) ), 'Migration evidence is not uniquely addressable by Blueprint.' );
	$guard = new MigrationPublicationGuard( $migrations, $importer );
	$assert( is_array( $guard->validate( $draft ) ), 'Fresh verified migration evidence was rejected.' );

	$registration = register_post_type( $source_key, [ 'public' => false, 'show_ui' => false ] );
	$assert( ! is_wp_error( $registration ), 'External CPT fixture could not be registered.' );
	$registered_post_type = true;
	$compiled = ( new Compiler() )->compile( $draft['draft_document'] );
	$assert( $compiled->is_valid(), 'Imported Blueprint did not compile.' );
	$ownership = new StorageOwnershipValidator( new BlueprintStore(), new ArtifactStore(), $guard );
	$codes = array_column( $ownership->blockers( $blueprint_id, $compiled->artifacts() ), 'code' );
	$assert( ! in_array( 'storage_identity_external', $codes, true ), 'Verified exact legacy handoff was blocked.' );

	$drifted_legacy = $legacy;
	$drifted_legacy[ $source_key ]['singular'] = 'Changed authority record';
	$assert( update_option( CptDefinitions::OPTION, $drifted_legacy, false ), 'Legacy source drift could not be staged.' );
	$assert( 'eit_migration_source_changed' === $guard->validate( $draft )->get_error_code(), 'Source checksum drift was not detected.' );
	$codes = array_column( $ownership->blockers( $blueprint_id, $compiled->artifacts() ), 'code' );
	$assert( in_array( 'storage_identity_external', $codes, true ), 'Unverified legacy storage handoff was allowed.' );
	update_option( CptDefinitions::OPTION, $legacy, false );

	$versions = new VersionStore();
	$blueprints = new BlueprintStore();
	$recovery_plan = $lifecycle->prepare( $blueprint_id, 1 );
	$assert( 'prepared' === ( $recovery_plan['status'] ?? '' ), 'Interrupted publication fixture could not be prepared.' );
	$assert( ! is_wp_error( ( new ChangeSetStore() )->transition( $recovery_plan['id'], 'prepared', 'applying' ) ), 'Interrupted publication fixture could not enter applying state.' );
	$version_one = $lifecycle->apply( $recovery_plan['id'], $recovery_plan['confirmation_token'], 1 );
	$recovered_change_set = ( new ChangeSetStore() )->get( $recovery_plan['id'] );
	$claim_owner = ( new StorageClaimStore() )->owner( 'cpt', $source_key );
	$assert(
		! is_wp_error( $version_one ) && 'applied' === ( $recovered_change_set['status'] ?? '' ),
		sprintf( 'The same confirmed applying change set could not resume idempotently (%s / %s).', is_wp_error( $version_one ) ? $version_one->get_error_code() : 'no-error', $recovered_change_set['status'] ?? 'missing' )
	);
	$assert( is_array( $claim_owner ) && $blueprint_id === $claim_owner['blueprint_id'] && 'prepared' === $claim_owner['status'], 'Recovered publication did not retain durable prepared storage ownership.' );
	$assert( $version_one['id'] === $blueprints->get( $blueprint_id )['active_version_id'], 'Idempotent recovery did not activate exactly one immutable version.' );
	$entity = current( array_filter( $compiled->artifacts(), fn( $artifact ) => 'entity_definition' === ( $artifact['kind'] ?? '' ) ) );
	$node_collision = [ [ 'kind' => 'presentation_contract', 'node_id' => $entity['node_id'], 'payload' => [] ] ];
	$codes = array_column( $ownership->blockers( Uuid::v4(), $node_collision ), 'code' );
	$assert( in_array( 'node_identity_owned', $codes, true ), 'Active Node UUID was not protected globally.' );

	$draft_plan = $lifecycle->prepare( $blueprint_id, 1 );
	$assert( 'prepared' === ( $draft_plan['status'] ?? '' ), 'Fresh verified Blueprint did not produce a publishable impact plan.' );
	$changed_document = $draft['draft_document'];
	$changed_document['name'] = 'Locally changed imported draft';
	$assert( ! is_wp_error( $lifecycle->save_draft( $changed_document ) ), 'Draft drift fixture could not be stored.' );
	$draft_result = $lifecycle->apply( $draft_plan['id'], $draft_plan['confirmation_token'], 1 );
	$assert( is_wp_error( $draft_result ) && 'eit_change_set_stale' === $draft_result->get_error_code(), 'Draft drift did not stop apply.' );
	$assert( 'failed' === ( new ChangeSetStore() )->get( $draft_plan['id'] )['status'], 'Draft-stale change set was not invalidated.' );
	$draft = $lifecycle->save_draft( $candidate['blueprint'] );

	$pointer_plan = $lifecycle->prepare( $blueprint_id, 1 );
	$assert( 'prepared' === ( $pointer_plan['status'] ?? '' ), 'Pointer drift plan could not be prepared.' );
	$version_two_document = $draft['draft_document'];
	$version_two_document['version'] = 2;
	$version_two_document['name'] = 'Competing active pointer';
	$version_two_checksum = ( new Canonicalizer() )->checksum( $version_two_document );
	$version_two_document['checksum'] = $version_two_checksum;
	$version_two = $versions->insert( $blueprint_id, $version_two_document, $version_two_checksum );
	$assert( ! is_wp_error( $version_two ) && true === $blueprints->set_active_version( $blueprint_id, $version_two['id'] ), 'Competing active pointer could not be staged.' );
	$pointer_result = $lifecycle->apply( $pointer_plan['id'], $pointer_plan['confirmation_token'], 1 );
	$assert( is_wp_error( $pointer_result ) && 'eit_change_set_stale' === $pointer_result->get_error_code(), 'Active pointer drift did not stop apply.' );
	$assert( 'failed' === ( new ChangeSetStore() )->get( $pointer_plan['id'] )['status'], 'Pointer-stale change set was not invalidated.' );
	$blueprints->set_active_version( $blueprint_id, $version_one['id'] );
	$cas_result = $blueprints->activate_if_current( $blueprint_id, $version_two['id'], $version_one['id'], str_repeat( 'x', 64 ) );
	$assert( is_wp_error( $cas_result ) && 'eit_blueprint_activation_stale' === $cas_result->get_error_code(), 'Activation compare-and-swap accepted a stale draft checksum.' );
	$assert( $version_one['id'] === $blueprints->get( $blueprint_id )['active_version_id'], 'Failed activation compare-and-swap changed the runtime pointer.' );
	$operational_before = $blueprints->get( $blueprint_id );
	$operational_after = $blueprints->save_draft( $operational_before['draft_document'], $operational_before['draft_checksum'] );
	$assert( ! is_wp_error( $operational_after ) && $operational_before['draft_checksum'] === $operational_after['draft_checksum'] && $operational_before['draft_revision'] + 1 === $operational_after['draft_revision'], 'Operational revision ABA fixture could not be staged.' );
	$revision_cas = $blueprints->activate_if_current( $blueprint_id, $version_two['id'], $version_one['id'], $operational_before['draft_checksum'], $operational_before['draft_revision'] );
	$assert( is_wp_error( $revision_cas ) && 'eit_blueprint_activation_stale' === $revision_cas->get_error_code(), 'Activation compare-and-swap accepted a stale operational draft revision.' );
	$assert( $version_one['id'] === $blueprints->get( $blueprint_id )['active_version_id'], 'Operational revision conflict changed the runtime pointer.' );

	$locks = new LockStore();
	$lease_resources = [ 'eit-lease-a-' . $suffix, 'eit-lease-b-' . $suffix ];
	$lease_one = $locks->acquire( $lease_resources[0], 1, LifecycleLeaseGuard::LEASE_TTL );
	$lease_two = $locks->acquire( $lease_resources[1], 1, LifecycleLeaseGuard::LEASE_TTL );
	$assert( ! is_wp_error( $lease_one ) && ! is_wp_error( $lease_two ), 'Lifecycle lease smoke could not acquire its real database locks.' );
	$lease_guard = new LifecycleLeaseGuard( $locks, [ $lease_resources[0] => $lease_one, $lease_resources[1] => $lease_two ] );
	$assert( true === $lease_guard->pulse(), 'Lifecycle lease smoke could not renew its complete real lock set.' );
	$assert( true === $locks->release( $lease_resources[0], $lease_one ), 'Lifecycle lease smoke could not stage ownership loss.' );
	$lease_loss = $lease_guard->pulse();
	$assert( is_wp_error( $lease_loss ) && 'eit_lifecycle_lease_lost' === $lease_loss->get_error_code(), 'Lifecycle lease guard did not fail closed after real lock ownership loss.' );
	$locks->release( $lease_resources[1], $lease_two );

	$source_plan = $lifecycle->prepare( $blueprint_id, 1 );
	$assert( 'prepared' === ( $source_plan['status'] ?? '' ), 'Source drift plan could not be prepared.' );
	update_option( CptDefinitions::OPTION, $drifted_legacy, false );
	$source_result = $lifecycle->apply( $source_plan['id'], $source_plan['confirmation_token'], 1 );
	$assert( is_wp_error( $source_result ) && 'eit_migration_source_changed' === $source_result->get_error_code(), 'Source drift did not stop apply.' );
	$assert( 'failed' === ( new ChangeSetStore() )->get( $source_plan['id'] )['status'], 'Source-stale change set was not invalidated.' );
	$blocked_plan = $lifecycle->prepare( $blueprint_id, 1 );
	$blocked_codes = array_column( $blocked_plan['impact']['blockers'] ?? [], 'code' );
	$assert( 'blocked' === ( $blocked_plan['status'] ?? '' ) && in_array( 'eit_migration_source_changed', $blocked_codes, true ), 'Source drift did not block a new impact plan.' );
	$migration_service = new MigrationService();
	$refresh_plan = $migration_service->prepare( [ [ 'source_type' => 'cpt', 'source_key' => $source_key ] ], 1 );
	$refresh = is_wp_error( $refresh_plan ) ? $refresh_plan : $migration_service->apply( $refresh_plan['confirmation_token'], 1 );
	$assert( ! is_wp_error( $refresh ) && 1 === ( $refresh['imported'] ?? 0 ), 'Active imported Blueprint evidence could not be refreshed safely.' );
	$assert( $version_one['id'] === $blueprints->get( $blueprint_id )['active_version_id'], 'Evidence refresh changed the active runtime pointer.' );
	$refreshed_plan = $lifecycle->prepare( $blueprint_id, 1 );
	$assert( 'prepared' === ( $refreshed_plan['status'] ?? '' ), 'Refreshed evidence did not restore a publishable impact plan.' );

	$collision_blueprint_id = Uuid::v4();
	$collision_document = [
		'api_version' => 'eit.dev/v1',
		'kind' => 'Blueprint',
		'id' => $collision_blueprint_id,
		'slug' => 'collision-' . substr( $collision_blueprint_id, 0, 8 ),
		'name' => 'Corrupted duplicate node fixture',
		'version' => 1,
		'nodes' => [],
		'connections' => [],
	];
	$collision_checksum = ( new Canonicalizer() )->checksum( $collision_document );
	$collision_document['checksum'] = $collision_checksum;
	$collision_draft = $blueprints->save_draft( $collision_document, $collision_checksum );
	$collision_version = $versions->insert( $collision_blueprint_id, $collision_document, $collision_checksum );
	$collision_artifact = $entity;
	$collision_artifact['id'] = hash( 'sha256', $collision_blueprint_id . '|duplicate-node' );
	$assert( ! is_wp_error( $collision_draft ) && ! is_wp_error( $collision_version ), 'Ambiguous active Node fixture could not be stored.' );
	$assert( true === ( new ArtifactStore() )->insert( $collision_blueprint_id, $collision_version['id'], $collision_artifact ), 'Ambiguous active Node artifact could not be stored.' );
	$assert( true === $blueprints->set_active_version( $collision_blueprint_id, $collision_version['id'] ), 'Ambiguous active Node fixture could not be activated.' );
	$assert( null === ( new ArtifactStore() )->active_by_node( $entity['node_id'], 'entity_definition' ), 'Ambiguous active Node resolver did not fail closed.' );

	WP_CLI::success( sprintf( 'Blueprint publication authority verified: %d assertions.', $assertions ) );
} finally {
	$cleanup();
}
