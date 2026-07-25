<?php
/**
 * Conservative uninstall: preserve data unless an explicit developer gate is enabled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'eit_sweep_entry_pending_uploads' );
wp_clear_scheduled_hook( 'eit_cleanup_entry_pending_upload' );

if ( ! defined( 'EIT_UNINSTALL_REMOVE_DATA' ) || true !== EIT_UNINSTALL_REMOVE_DATA ) {
	return;
}

global $wpdb;

$normalize_cct_slug = static function ( $slug ) {
	$slug = substr( preg_replace( '/[^a-z0-9_]/', '_', sanitize_key( (string) $slug ) ), 0, 32 );
	return preg_match( '/^[a-z0-9_]{1,32}$/', $slug ) ? $slug : '';
};
$table_exists = static function ( $table ) use ( $wpdb ) {
	$match = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	return (string) $table === (string) $match;
};
$cct_slugs = [];
$definitions = get_option( 'eit_cct_definitions', [] );
foreach ( is_array( $definitions ) ? array_keys( $definitions ) : [] as $slug ) {
	$slug = $normalize_cct_slug( $slug );
	if ( '' !== $slug ) {
		$cct_slugs[ $slug ] = true;
	}
}

$claims_table = $wpdb->prefix . 'eit_storage_claims';
if ( $table_exists( $claims_table ) ) {
	$claimed_slugs = $wpdb->get_col( $wpdb->prepare( "SELECT storage_slug FROM `{$claims_table}` WHERE strategy = %s", 'cct' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $claimed_slugs ?: [] as $slug ) {
		$slug = $normalize_cct_slug( $slug );
		if ( '' !== $slug ) {
			$cct_slugs[ $slug ] = true;
		}
	}
}

$artifacts_table = $wpdb->prefix . 'eit_artifacts';
if ( $table_exists( $artifacts_table ) ) {
	$payloads = $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM `{$artifacts_table}` WHERE kind = %s", 'entity_definition' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $payloads ?: [] as $encoded ) {
		$payload = json_decode( (string) $encoded, true );
		if ( ! is_array( $payload ) || 'cct' !== sanitize_key( $payload['strategy'] ?? '' ) ) {
			continue;
		}
		$slug = $normalize_cct_slug( $payload['definition']['slug'] ?? '' );
		if ( '' !== $slug ) {
			$cct_slugs[ $slug ] = true;
		}
	}
}

ksort( $cct_slugs, SORT_STRING );
foreach ( array_keys( $cct_slugs ) as $slug ) {
	$table = $wpdb->prefix . 'eit_cct_' . $slug;
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
}

$tables = [
	'blueprints', 'blueprint_versions', 'artifacts', 'bindings', 'change_sets',
	'storage_claims', 'migration_operations', 'locks', 'runs', 'reconciliations', 'rollbacks', 'relation_values',
	'multivalue_values', 'entry_submissions', 'pending_uploads', 'entry_action_jobs', 'migrations',
	'run_events', 'qa_scenarios',
];
foreach ( $tables as $suffix ) {
	$table = $wpdb->prefix . 'eit_' . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
}

$private_upload_directory = (string) get_option( 'eit_private_upload_directory_path', '' );
$private_upload_directory = $private_upload_directory ? realpath( $private_upload_directory ) : '';
$private_upload_directory = false === $private_upload_directory ? '' : $private_upload_directory;

$inside = static function ( $path, $parent ) {
	$path = str_replace( '\\', '/', rtrim( (string) realpath( $path ), '/\\' ) );
	$parent = str_replace( '\\', '/', rtrim( (string) realpath( $parent ), '/\\' ) );
	return '' !== $path && '' !== $parent && ( $path === $parent || str_starts_with( $path . '/', $parent . '/' ) );
};
$uploads = wp_upload_dir();
$document_root = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ?? '' ) );
$marker = $private_upload_directory ? $private_upload_directory . DIRECTORY_SEPARATOR . '.eit-owned' : '';
$owned = $marker && is_file( $marker ) && hash_equals( 'eit-private-upload-storage-v1', trim( (string) file_get_contents( $marker ) ) );
$public = $private_upload_directory && ( $inside( $private_upload_directory, ABSPATH ) || ( $document_root && $inside( $private_upload_directory, $document_root ) ) || ( empty( $uploads['error'] ) && $inside( $private_upload_directory, $uploads['basedir'] ) ) );
$entries = $private_upload_directory ? array_values( array_diff( scandir( $private_upload_directory ) ?: [], [ '.', '..' ] ) ) : [];
$known_entries = ! array_filter(
	$entries,
	static function ( $entry ) use ( $private_upload_directory ) {
		$path = $private_upload_directory . DIRECTORY_SEPARATOR . $entry;
		return '.eit-owned' !== $entry && ( ! preg_match( '/^[a-f0-9]{32}(\.[a-z0-9]+)?$/', $entry ) || ( ! is_file( $path ) && ! is_link( $path ) ) );
	}
);
if ( $owned && ! $public && $known_entries ) {
	foreach ( $entries as $entry ) {
		$path = $private_upload_directory . DIRECTORY_SEPARATOR . $entry;
		if ( is_file( $path ) || is_link( $path ) ) {
			wp_delete_file( $path );
		}
	}
	if ( ! array_diff( scandir( $private_upload_directory ) ?: [], [ '.', '..' ] ) ) {
		rmdir( $private_upload_directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Marker- and filename-gated local uninstall cleanup.
	}
}

foreach ( [ 'eit_cpt_definitions', 'eit_cct_definitions', 'eit_filter_presets', 'eit_cct_schema_version', 'eit_blueprint_schema_version', 'eit_private_upload_directory_path' ] as $option ) {
	delete_option( $option );
}

$like_generation = $wpdb->esc_like( 'eit_collection_generation_' ) . '%';
$like_transient = $wpdb->esc_like( '_transient_eit_collection_' ) . '%';
$like_timeout = $wpdb->esc_like( '_transient_timeout_eit_collection_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like_generation, $like_transient, $like_timeout ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

wp_cache_flush();
