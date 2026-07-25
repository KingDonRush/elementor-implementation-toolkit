<?php
/**
 * WordPress integration verification for complete publication reconciliation.
 *
 * Run with:
 * wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-reconciliation-integrity.php
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\LifecycleService;
use EIT\Blueprint\RuntimeDefinitionProvider;
use EIT\Blueprint\Uuid;
use EIT\CCT\SchemaManager as CctSchema;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BindingStore;
use EIT\Infrastructure\ChangeSetStore;
use EIT\Infrastructure\JsonCodec;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assertions = 0;
$blueprint_id = Uuid::v4();
$suffix = substr( str_replace( '-', '', $blueprint_id ), 0, 8 );
$storage_slug = 'qa_integrity_' . $suffix;
$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
$cleanup = function () use ( $blueprint_id, $storage_slug ) {
	global $wpdb;

	$run_table = Tables::name( Tables::RUNS );
	$event_table = Tables::name( Tables::RUN_EVENTS );
	$run_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$run_table}` WHERE blueprint_id = %s", $blueprint_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $run_ids ?: [] as $run_id ) {
		$wpdb->delete( $event_table, [ 'run_id' => $run_id ] );
	}
	foreach ( Tables::keys() as $table_key ) {
		$table = Tables::name( $table_key );
		if ( Tables::LOCKS === $table_key ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE resource_key LIKE %s", '%' . $wpdb->esc_like( $blueprint_id ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			continue;
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( in_array( 'blueprint_id', $columns ?: [], true ) ) {
			$wpdb->delete( $table, [ 'blueprint_id' => $blueprint_id ] );
		}
	}
	$wpdb->delete( Tables::name( Tables::BLUEPRINTS ), [ 'id' => $blueprint_id ] );
	RuntimeDefinitionProvider::invalidate();
	CctSchema::drop_table( $storage_slug );
};

try {
	global $wpdb;

	$cleanup();
	$assert( true === SchemaManager::install() && true === SchemaManager::verify(), 'Schema v8 binding authority is unavailable.' );
	$entity_id = Uuid::v5( $blueprint_id, 'entity' );
	$group_id = Uuid::v5( $blueprint_id, 'fields' );
	$field_id = Uuid::v5( $blueprint_id, 'field:title' );
	$field = ( new FieldContractFactory( new FieldPrimitiveRegistry() ) )->make(
		$field_id,
		'Title',
		'short_text',
		[ 'storage' => [ 'key' => 'title', 'aliases' => [ 'legacy_title' ] ] ]
	);
	$document = [
		'api_version' => BlueprintValidator::API_VERSION,
		'kind' => BlueprintValidator::KIND,
		'id' => $blueprint_id,
		'slug' => 'qa-integrity-' . $suffix,
		'name' => 'QA reconciliation integrity',
		'version' => 1,
		'nodes' => [
			[ 'id' => $entity_id, 'type' => 'entity', 'lane' => 'data', 'name' => 'Record', 'config' => [ 'slug' => $storage_slug, 'mode' => 'structured', 'high_volume' => true, 'public' => false ] ],
			[ 'id' => $group_id, 'type' => 'field_group', 'lane' => 'data', 'name' => 'Fields', 'config' => [ 'fields' => [ $field ] ] ],
		],
		'connections' => [
			[ 'id' => Uuid::v5( $blueprint_id, 'entity-fields' ), 'type' => 'entity_fields', 'from' => $entity_id, 'to' => $group_id ],
		],
	];
	$lifecycle = new LifecycleService();
	$draft = $lifecycle->save_draft( $document );
	$plan = is_wp_error( $draft ) ? $draft : $lifecycle->prepare( $blueprint_id, 1 );
	$version = is_wp_error( $plan ) ? $plan : $lifecycle->apply( $plan['id'], $plan['confirmation_token'], 1 );
	$assert( ! is_wp_error( $version ) && 'applied' === ( new ChangeSetStore() )->get( $plan['id'] )['status'], 'Integrity fixture could not be published.' );

	$expected_binding = $plan['compiled_artifacts']['bindings'][0];
	$bindings = new BindingStore();
	$stored_binding = $bindings->get_checked( $version['id'], $field_id );
	$assert( ! is_wp_error( $stored_binding ) && $entity_id === $stored_binding['payload']['entity_id'] && array_key_exists( 'migration', $stored_binding['payload'] ), 'Complete binding payload was not persisted.' );
	$assert( true === $bindings->insert_many( $blueprint_id, $version['id'], [ $expected_binding ] ), 'Complete binding retry was not idempotent.' );
	$drifted_binding = $expected_binding;
	$drifted_binding['migration'] = [ 'transform' => 'foreign' ];
	$collision = $bindings->insert_many( $blueprint_id, $version['id'], [ $drifted_binding ] );
	$assert( is_wp_error( $collision ) && 'eit_binding_identity_collision' === $collision->get_error_code(), 'Binding retry accepted a different migration payload.' );

	$binding_table = Tables::name( Tables::BINDINGS );
	$wpdb->delete( $binding_table, [ 'version_id' => $version['id'], 'field_id' => $field_id ] );
	$missing = $lifecycle->reconcile( $plan['id'] );
	$assert( is_wp_error( $missing ) && 'eit_reconciliation_mismatch' === $missing->get_error_code() && [ 'bindings' ] === $missing->get_error_data()['components'], 'Removed binding did not block reconciliation explicitly.' );
	$assert( 'applied' === ( new ChangeSetStore() )->get( $plan['id'] )['status'], 'Removed binding advanced the change set to reconciled.' );
	$assert( true === $bindings->insert_many( $blueprint_id, $version['id'], [ $expected_binding ] ), 'Removed binding could not be restored.' );

	$wpdb->update( $binding_table, [ 'payload' => '{invalid-json' ], [ 'version_id' => $version['id'], 'field_id' => $field_id ] );
	$invalid_json = $lifecycle->reconcile( $plan['id'] );
	$assert( is_wp_error( $invalid_json ) && 'eit_binding_runtime_record_invalid' === $invalid_json->get_error_code(), 'Invalid binding JSON did not fail closed.' );
	$assert( 'applied' === ( new ChangeSetStore() )->get( $plan['id'] )['status'], 'Invalid binding JSON advanced reconciliation.' );

	$expected_payload = JsonCodec::encode( $expected_binding );
	$expected_aliases = JsonCodec::encode( $expected_binding['aliases'] );
	$wpdb->update( $binding_table, [ 'payload' => $expected_payload, 'aliases' => $expected_aliases ], [ 'version_id' => $version['id'], 'field_id' => $field_id ] );
	$drifted_payload = $expected_binding;
	$drifted_payload['migration'] = [ 'transform' => 'tampered' ];
	$wpdb->update( $binding_table, [ 'payload' => JsonCodec::encode( $drifted_payload ) ], [ 'version_id' => $version['id'], 'field_id' => $field_id ] );
	$payload_mismatch = $lifecycle->reconcile( $plan['id'] );
	$assert( is_wp_error( $payload_mismatch ) && [ 'bindings' ] === $payload_mismatch->get_error_data()['components'], 'Valid but altered binding payload was not compared completely.' );
	$wpdb->update( $binding_table, [ 'payload' => $expected_payload ], [ 'version_id' => $version['id'], 'field_id' => $field_id ] );

	$artifact_table = Tables::name( Tables::ARTIFACTS );
	$artifact = ( new ArtifactStore() )->for_version_checked( $version['id'] )[0];
	$wpdb->update( $artifact_table, [ 'payload' => '{invalid-json' ], [ 'id' => $artifact['id'] ] );
	$invalid_artifact = $lifecycle->reconcile( $plan['id'] );
	$assert( is_wp_error( $invalid_artifact ) && 'eit_artifact_runtime_record_invalid' === $invalid_artifact->get_error_code(), 'Invalid artifact JSON did not fail closed.' );
	$assert( 'applied' === ( new ChangeSetStore() )->get( $plan['id'] )['status'], 'Invalid artifact JSON advanced reconciliation.' );
	$wpdb->update( $artifact_table, [ 'payload' => JsonCodec::encode( $artifact['payload'] ) ], [ 'id' => $artifact['id'] ] );

	$reconciled = $lifecycle->reconcile( $plan['id'] );
	$assert( ! is_wp_error( $reconciled ) && 'reconciled' === $reconciled['status'], 'Restored exact publication snapshot did not reconcile.' );
	$proofs = $wpdb->get_results( $wpdb->prepare( 'SELECT status,counts,checksums FROM `' . Tables::name( Tables::RECONCILIATIONS ) . '` WHERE change_set_id = %s ORDER BY reconciled_at,id', $plan['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$verified_proof = current( array_filter( $proofs, fn( $proof ) => 'verified' === ( $proof['status'] ?? '' ) ) );
	$counts = JsonCodec::decode( $verified_proof['counts'] ?? null, [] );
	$checksums = JsonCodec::decode( $verified_proof['checksums'] ?? null, [] );
	$proof_statuses = array_column( $proofs, 'status' );
	$proof_status_counts = array_count_values( $proof_statuses );
	$assert( 2 === ( $proof_status_counts['mismatch'] ?? 0 ) && 1 === ( $proof_status_counts['verified'] ?? 0 ), 'Reconciliation proof history did not preserve mismatches and final verification: ' . wp_json_encode( $proof_statuses ) );
	$assert( [ 'expected' => 1, 'actual' => 1 ] === $counts['bindings'] && $checksums['bindings']['expected'] === $checksums['bindings']['actual'], 'Final evidence omitted complete binding count or checksum.' );

	WP_CLI::success( sprintf( 'Complete publication reconciliation verified with %d assertions.', $assertions ) );
} catch ( Throwable $error ) {
	WP_CLI::error( $error->getMessage() );
} finally {
	$cleanup();
}
