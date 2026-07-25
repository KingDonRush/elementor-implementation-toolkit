<?php
/**
 * Proves first-activation rollback restores an unchanged raw legacy authority.
 */

use EIT\Admin\BlueprintAdminPresenter;
use EIT\Blueprint\LegacyImporter;
use EIT\Blueprint\LifecycleService;
use EIT\Blueprint\MigrationPublicationGuard;
use EIT\Blueprint\RuntimeDefinitionProvider;
use EIT\Blueprint\ShadowComparator;
use EIT\Blueprint\StorageMutationGuard;
use EIT\Blueprint\Uuid;
use EIT\CPT\DefinitionManager as CptDefinitions;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\ChangeSetStore;
use EIT\Infrastructure\LockStore;
use EIT\Infrastructure\MigrationStore;
use EIT\Infrastructure\RuntimeCache;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\Tables;
use EIT\Infrastructure\VersionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$assertions = 0;
$blueprint_id = '';
$version_id = 0;
$post_id = 0;
$registered = false;
$source_key = 'eit_rb_' . substr( str_replace( '-', '', Uuid::v4() ), 0, 8 );
$legacy_before = get_option( CptDefinitions::OPTION, [] );
$legacy_before = is_array( $legacy_before ) ? $legacy_before : [];

$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};

$cleanup = function () use ( &$blueprint_id, &$version_id, &$post_id, &$registered, $source_key, $legacy_before, $wpdb ) {
	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
	if ( $registered && post_type_exists( $source_key ) ) {
		unregister_post_type( $source_key );
	}
	update_option( CptDefinitions::OPTION, $legacy_before, false );
	if ( $blueprint_id ) {
		$run_table = Tables::name( Tables::RUNS );
		$event_table = Tables::name( Tables::RUN_EVENTS );
		$run_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$run_table}` WHERE blueprint_id = %s", $blueprint_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $run_ids ?: [] as $run_id ) {
			$wpdb->delete( $event_table, [ 'run_id' => $run_id ] );
		}
		foreach ( Tables::keys() as $table_key ) {
			$table = Tables::name( $table_key );
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( in_array( 'blueprint_id', $columns ?: [], true ) ) {
				$wpdb->delete( $table, [ 'blueprint_id' => $blueprint_id ] );
			}
		}
		$wpdb->delete( Tables::name( Tables::BLUEPRINTS ), [ 'id' => $blueprint_id ] );
	}
	$lock_table = Tables::name( Tables::LOCKS );
	$writer_prefix = $wpdb->esc_like( StorageMutationGuard::writer_prefix( 'cpt', $source_key ) ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$lock_table}` WHERE resource_key = %s OR resource_key LIKE %s", StorageMutationGuard::resource_key( 'cpt', $source_key ), $writer_prefix ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $blueprint_id ) {
		$wpdb->delete( $lock_table, [ 'resource_key' => 'blueprint-' . $blueprint_id ] );
	}
	if ( $version_id && $blueprint_id ) {
		( new RuntimeCache() )->invalidate( $blueprint_id, $version_id );
	}
	RuntimeDefinitionProvider::invalidate();
};

try {
	$cleanup();
	$assert( true === SchemaManager::install() && true === SchemaManager::verify(), 'Legacy rollback infrastructure is unavailable.' );
	$definition = [
		'slug' => $source_key,
		'singular' => 'Legacy rollback record',
		'plural' => 'Legacy rollback records',
		'description' => 'Raw legacy authority fixture.',
		'menu_icon' => 'dashicons-screenoptions',
		'public' => false,
		'show_in_rest' => false,
		'has_archive' => false,
		'hierarchical' => false,
		'rewrite_slug' => '',
		'supports' => [ 'title', 'custom-fields' ],
		'taxonomies' => [],
		'meta_fields' => [ [ 'key' => 'legacy_code', 'label' => 'Legacy code', 'type' => 'text', 'show_in_rest' => true ] ],
	];
	$legacy = $legacy_before;
	$legacy[ $source_key ] = $definition;
	$assert( update_option( CptDefinitions::OPTION, $legacy, false ), 'Raw legacy option could not be staged.' );
	$registration = register_post_type( $source_key, [ 'public' => false, 'show_ui' => false, 'supports' => [ 'title', 'custom-fields' ] ] );
	$assert( ! is_wp_error( $registration ), 'Legacy CPT could not be registered for the disposable smoke.' );
	$registered = true;
	$post_id = wp_insert_post( [ 'post_type' => $source_key, 'post_status' => 'publish', 'post_title' => 'Preserved legacy item', 'meta_input' => [ 'legacy_code' => 'RAW-42' ] ], true );
	$assert( ! is_wp_error( $post_id ) && 0 < $post_id, 'Legacy content fixture could not be created.' );

	$importer = new LegacyImporter();
	$candidate = $importer->candidate( 'cpt', $source_key );
	$assert( is_array( $candidate ) && 64 === strlen( (string) ( $candidate['source_checksum'] ?? '' ) ), 'Legacy source checksum is unavailable.' );
	$source_checksum = $candidate['source_checksum'];
	$blueprint_id = $candidate['blueprint']['id'];
	$lifecycle = new LifecycleService();
	$draft = $lifecycle->save_draft( $candidate['blueprint'] );
	$assert( ! is_wp_error( $draft ), 'Imported legacy Blueprint draft could not be stored.' );
	$comparison = ( new ShadowComparator() )->compare( 'cpt', $source_key, $draft['draft_document'] );
	$assert( 'verified' === ( $comparison['status'] ?? '' ), 'Independent shadow comparison did not verify the legacy fixture.' );
	$evidence = ( new MigrationStore() )->save(
		[
			'source_type' => 'cpt',
			'source_key' => $source_key,
			'blueprint_id' => $blueprint_id,
			'source_checksum' => $source_checksum,
			'draft_checksum' => $draft['draft_checksum'],
			'status' => 'verified',
			'comparison' => $comparison,
		]
	);
	$assert( ! is_wp_error( $evidence ) && is_array( ( new MigrationPublicationGuard() )->validate( $draft ) ), 'Exactly one fresh migration evidence record was not accepted.' );

	$plan = $lifecycle->prepare( $blueprint_id, 1 );
	$assert( 'prepared' === ( $plan['status'] ?? '' ) && null === ( $plan['from_version_id'] ?? null ), 'First activation plan is not anchored to legacy authority.' );
	$frozen_authority = $plan['compiled_artifacts']['legacy_authority'] ?? null;
	$assert( is_array( $frozen_authority ) && 64 === strlen( (string) ( $frozen_authority['authority_checksum'] ?? '' ) ) && hash_equals( $source_checksum, $frozen_authority['source_checksum'] ?? '' ), 'Prepared change set did not freeze its verified raw legacy authority.' );
	$version = $lifecycle->apply( $plan['id'], $plan['confirmation_token'], 1 );
	$assert( ! is_wp_error( $version ), 'Imported Blueprint first activation failed.' );
	$version_id = (int) $version['id'];
	$reconciled = $lifecycle->reconcile( $plan['id'] );
	$assert( ! is_wp_error( $reconciled ) && 'reconciled' === ( ( new ChangeSetStore() )->get( $plan['id'] )['status'] ?? '' ), 'First activation could not be reconciled.' );
	$artifacts = ( new ArtifactStore() )->for_version( $version_id );
	$assert( $artifacts && is_array( ( new RuntimeCache() )->get( $blueprint_id, $version_id ) ), 'Active compiled artifacts were not cached.' );
	RuntimeDefinitionProvider::invalidate();
	$assert( ! empty( CptDefinitions::get( $source_key )['blueprint_managed'] ), 'Compiled Blueprint authority did not become active.' );
	$targets = ( new BlueprintAdminPresenter() )->system( $blueprint_id )['rollback_targets'] ?? [];
	$assert( in_array( 'legacy', array_column( $targets, 'target' ), true ), 'Verified first activation did not expose the semantic Legacy source target.' );
	$drifted = $legacy;
	$drifted[ $source_key ]['singular'] = 'Drifted rollback record';
	update_option( CptDefinitions::OPTION, $drifted, false );
	$targets = ( new BlueprintAdminPresenter() )->system( $blueprint_id )['rollback_targets'] ?? [];
	$assert( ! in_array( 'legacy', array_column( $targets, 'target' ), true ), 'Stale source evidence left a ghost Legacy source control.' );
	update_option( CptDefinitions::OPTION, $legacy, false );
	$newer_document = $version['document'];
	$newer_document['name'] = 'A newer draft must survive rollback authority checks';
	$newer_draft = $lifecycle->save_draft( $newer_document );
	$assert( ! is_wp_error( $newer_draft ) && ! hash_equals( $version['checksum'], $newer_draft['draft_checksum'] ), 'A distinct post-publication draft could not be staged.' );
	$rescanned = ( new MigrationStore() )->save(
		[
			'source_type' => 'cpt',
			'source_key' => $source_key,
			'blueprint_id' => $blueprint_id,
			'source_checksum' => str_repeat( 'f', 64 ),
			'draft_checksum' => $newer_draft['draft_checksum'],
			'status' => 'inspected',
			'comparison' => [ 'status' => 'mismatch', 'compiler_checksum' => str_repeat( 'e', 64 ) ],
		]
	);
	$assert( ! is_wp_error( $rescanned ), 'Mutable migration evidence could not be replaced for the historical-authority probe.' );
	$targets = ( new BlueprintAdminPresenter() )->system( $blueprint_id )['rollback_targets'] ?? [];
	$assert( in_array( 'legacy', array_column( $targets, 'target' ), true ), 'Later draft/evidence state replaced the immutable first-activation rollback proof.' );

	$writer_gate = new StorageMutationGuard();
	$writer = $writer_gate->enter( 'cpt', $source_key );
	$assert( ! is_wp_error( $writer ), 'Disposable legacy writer lease could not be established.' );
	$blocked = $lifecycle->rollback( $blueprint_id, 'legacy', 'Writer drain probe', 1 );
	$assert( is_wp_error( $blocked ) && 'eit_migration_storage_locked' === $blocked->get_error_code(), 'Legacy rollback ignored an active storage writer.' );
	$assert( $version_id === ( new BlueprintStore() )->get( $blueprint_id )['active_version_id'], 'Blocked rollback changed runtime authority.' );
	$assert( true === $writer_gate->leave( $writer ), 'Disposable writer lease could not be released.' );

	$rollback = $lifecycle->rollback( $blueprint_id, 'legacy', 'Return to verified legacy source', 1 );
	$stored = ( new BlueprintStore() )->get( $blueprint_id );
	$lineage = ( new ChangeSetStore() )->get( $plan['id'] );
	$assert( ! is_wp_error( $rollback ) && null === $stored['active_version_id'] && 'rolled_back' === ( $lineage['status'] ?? '' ), 'Legacy rollback did not atomically release Blueprint authority.' );
	$assert( hash_equals( $newer_draft['draft_checksum'], $stored['draft_checksum'] ), 'Legacy rollback replaced the newer operational draft.' );
	$rollback_table = Tables::name( Tables::ROLLBACKS );
	$audit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$rollback_table}` WHERE blueprint_id = %s ORDER BY created_at DESC LIMIT 1", $blueprint_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$assert( $audit && $version_id === (int) $audit['from_version_id'] && 0 === (int) $audit['to_version_id'], 'Rollback audit did not record the semantic legacy target as version zero.' );
	$assert( hash_equals( $source_checksum, $importer->candidate( 'cpt', $source_key )['source_checksum'] ) && $definition === get_option( CptDefinitions::OPTION, [] )[ $source_key ], 'Raw legacy option changed during activation or rollback.' );
	RuntimeDefinitionProvider::invalidate();
	$assert( ! isset( RuntimeDefinitionProvider::cpt_definitions()[ $source_key ] ) && $definition === CptDefinitions::get( $source_key ), 'Runtime did not fall back to raw legacy authority.' );
	$assert( [] === ( ( new BlueprintAdminPresenter() )->system( $blueprint_id )['rollback_targets'] ?? [] ), 'Inactive historical Blueprint exposed an inoperable rollback action.' );
	$assert( null === ( new RuntimeCache() )->get( $blueprint_id, $version_id ), 'Rolled-back Blueprint runtime cache remained active.' );
	$assert( count( $artifacts ) === count( ( new ArtifactStore() )->for_version( $version_id ) ) && 1 === count( ( new VersionStore() )->for_blueprint( $blueprint_id ) ), 'Rollback deleted immutable versions or artifacts.' );
	$assert( get_post( $post_id ) && 'RAW-42' === get_post_meta( $post_id, 'legacy_code', true ), 'Rollback changed legacy content data.' );
	$delete = ( new BlueprintStore() )->delete_unpublished( $blueprint_id );
	$assert( is_wp_error( $delete ) && 'eit_blueprint_delete_historical' === $delete->get_error_code(), 'Historical Blueprint became deletable after returning to legacy.' );
	$assert( false === ( new LockStore() )->is_active( StorageMutationGuard::resource_key( 'cpt', $source_key ) ) && [] === ( new LockStore() )->active_with_prefix( StorageMutationGuard::writer_prefix( 'cpt', $source_key ) ), 'Legacy rollback leaked storage locks.' );

	WP_CLI::success( sprintf( 'First-activation legacy rollback verified: %d assertions.', $assertions ) );
} finally {
	$cleanup();
}
