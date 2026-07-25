<?php
/**
 * Verifies the explicitly gated uninstall inventory in an ephemeral WordPress.
 *
 * This script destroys all Toolkit data in the current site. Run it only in an
 * isolated compatibility canary and always as the final scenario.
 */

use EIT\Infrastructure\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
$exists = static function ( $table ) use ( $wpdb ) {
	$match = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	return (string) $table === (string) $match;
};
$suffix = substr( hash( 'sha256', wp_generate_uuid4() ), 0, 8 );
$legacy_slug = 'uninst_legacy_' . $suffix;
$claim_slug = 'uninst_claim_' . $suffix;
$artifact_slug = 'uninst_artifact_' . $suffix;
$cct_tables = array_map(
	static fn( $slug ) => $wpdb->prefix . 'eit_cct_' . $slug,
	[ $legacy_slug, $claim_slug, $artifact_slug ]
);

foreach ( $cct_tables as $table ) {
	$created = $wpdb->query( "CREATE TABLE `{$table}` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$assert( false !== $created && $exists( $table ), 'The uninstall CCT fixture table could not be created.' );
}

update_option( 'eit_cct_definitions', [ $legacy_slug => [ 'slug' => $legacy_slug ] ], false );
$now = current_time( 'mysql', true );
$claim_written = $wpdb->insert(
	Tables::name( Tables::STORAGE_CLAIMS ),
	[
		'identity_hash' => hash( 'sha256', 'entity_definition|cct|' . $claim_slug ),
		'strategy' => 'cct',
		'storage_slug' => $claim_slug,
		'blueprint_id' => wp_generate_uuid4(),
		'change_set_id' => wp_generate_uuid4(),
		'artifact_checksum' => hash( 'sha256', 'uninstall-claim-' . $suffix ),
		'existed_before' => 0,
		'status' => 'prepared',
		'created_at' => $now,
		'updated_at' => $now,
	]
);
$assert( 1 === $claim_written, 'The compiled storage claim fixture could not be written.' );

$artifact_payload = wp_json_encode(
	[
		'strategy' => 'cct',
		'definition' => [ 'slug' => $artifact_slug ],
	]
);
$artifact_written = $wpdb->insert(
	Tables::name( Tables::ARTIFACTS ),
	[
		'id' => hash( 'sha256', 'uninstall-artifact-id-' . $suffix ),
		'blueprint_id' => wp_generate_uuid4(),
		'version_id' => 987654321,
		'node_id' => null,
		'kind' => 'entity_definition',
		'checksum' => hash( 'sha256', 'uninstall-artifact-' . $suffix ),
		'payload' => $artifact_payload,
		'created_at' => $now,
	]
);
$assert( 1 === $artifact_written, 'The compiled artifact fixture could not be written.' );

$posts_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->posts}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$assert( $posts_before > 0, 'The isolated WordPress fixture must contain a core post before uninstall.' );

define( 'WP_UNINSTALL_PLUGIN', true );
define( 'EIT_UNINSTALL_REMOVE_DATA', true );
require dirname( __DIR__ ) . '/uninstall.php';

foreach ( $cct_tables as $table ) {
	$assert( ! $exists( $table ), 'A legacy or compiled Toolkit CCT table survived explicit uninstall.' );
}
$assert( ! $exists( $wpdb->prefix . 'eit_blueprints' ), 'Toolkit infrastructure survived explicit uninstall.' );
$assert( false === get_option( 'eit_cct_definitions', false ), 'Toolkit definitions survived explicit uninstall.' );
$assert( $posts_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->posts}`" ), 'Explicit uninstall removed WordPress posts.' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

WP_CLI::success( sprintf( 'Explicit uninstall inventory verified with %d assertions.', $assertions ) );
