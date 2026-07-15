<?php
/**
 * Installs and verifies Blueprint infrastructure without migrating content.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SchemaManager {

	const VERSION = '2';
	const VERSION_OPTION = 'eit_blueprint_schema_version';

	public static function maybe_upgrade() {
		return self::VERSION === get_option( self::VERSION_OPTION ) ? true : self::install();
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$previous_suppression = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';

		try {
			foreach ( self::schemas() as $sql ) {
				dbDelta( $sql );
				if ( '' !== $wpdb->last_error ) {
					return self::error( 'eit_blueprint_schema_failed', $wpdb->last_error );
				}
			}

			$verified = self::verify();
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			if ( ! update_option( self::VERSION_OPTION, self::VERSION, false ) && self::VERSION !== get_option( self::VERSION_OPTION ) ) {
				return self::error( 'eit_blueprint_schema_version_failed', 'Could not persist infrastructure schema version.' );
			}
			return true;
		} finally {
			$wpdb->suppress_errors( $previous_suppression );
		}
	}

	public static function verify() {
		global $wpdb;

		foreach ( self::expected_columns() as $table_key => $expected ) {
			$table = Tables::name( $table_key );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $table !== $found ) {
				return self::error( 'eit_blueprint_table_missing', 'Missing table: ' . $table_key );
			}
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$missing = array_diff( $expected, $columns ?: [] );
			if ( $missing ) {
				return self::error( 'eit_blueprint_columns_missing', $table_key . ': ' . implode( ', ', $missing ) );
			}
		}
		return true;
	}

	private static function schemas() {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$table = function ( $key ) {
			return Tables::name( $key );
		};

		return [
			"CREATE TABLE {$table( Tables::BLUEPRINTS )} (
				id char(36) NOT NULL,
				slug varchar(191) NOT NULL,
				name varchar(191) NOT NULL,
				draft_revision bigint(20) unsigned NOT NULL DEFAULT 0,
				draft_checksum char(64) DEFAULT NULL,
				draft_document longtext DEFAULT NULL,
				active_version_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY active_version_id (active_version_id)
			) {$collate};",
			"CREATE TABLE {$table( Tables::VERSIONS )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id char(36) NOT NULL,
				version int(10) unsigned NOT NULL,
				checksum char(64) NOT NULL,
				schema_version varchar(32) NOT NULL,
				document longtext NOT NULL,
				published_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY blueprint_version (blueprint_id,version),
				UNIQUE KEY blueprint_checksum (blueprint_id,checksum)
			) {$collate};",
			"CREATE TABLE {$table( Tables::ARTIFACTS )} (
				id char(64) NOT NULL,
				blueprint_id char(36) NOT NULL,
				version_id bigint(20) unsigned NOT NULL,
				node_id char(36) DEFAULT NULL,
				kind varchar(64) NOT NULL,
				checksum char(64) NOT NULL,
				payload longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY version_kind (version_id,kind),
				KEY blueprint_node (blueprint_id,node_id)
			) {$collate};",
			"CREATE TABLE {$table( Tables::BINDINGS )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id char(36) NOT NULL,
				version_id bigint(20) unsigned NOT NULL,
				field_id char(36) NOT NULL,
				adapter varchar(64) NOT NULL,
				storage_key varchar(191) NOT NULL,
				aliases longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY version_field (version_id,field_id),
				KEY blueprint_field (blueprint_id,field_id)
			) {$collate};",
			"CREATE TABLE {$table( Tables::CHANGE_SETS )} (
				id char(36) NOT NULL,
				blueprint_id char(36) NOT NULL,
				from_version_id bigint(20) unsigned DEFAULT NULL,
				draft_checksum char(64) NOT NULL,
				status varchar(24) NOT NULL,
				impact longtext NOT NULL,
				compiled_artifacts longtext NOT NULL,
				confirmation_hash char(64) NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				applied_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY blueprint_status (blueprint_id,status)
			) {$collate};",
			"CREATE TABLE {$table( Tables::LOCKS )} (
				resource_key varchar(191) NOT NULL,
				token_hash char(64) NOT NULL,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (resource_key),
				KEY expires_at (expires_at)
			) {$collate};",
			"CREATE TABLE {$table( Tables::RUNS )} (
				id char(36) NOT NULL,
				blueprint_id char(36) NOT NULL,
				change_set_id char(36) DEFAULT NULL,
				operation varchar(32) NOT NULL,
				status varchar(24) NOT NULL,
				request_id char(36) NOT NULL,
				context longtext DEFAULT NULL,
				error_code varchar(96) DEFAULT NULL,
				error_message text DEFAULT NULL,
				started_at datetime NOT NULL,
				finished_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY blueprint_started (blueprint_id,started_at),
				KEY change_set_id (change_set_id)
			) {$collate};",
			"CREATE TABLE {$table( Tables::RECONCILIATIONS )} (
				id char(36) NOT NULL,
				blueprint_id char(36) NOT NULL,
				change_set_id char(36) NOT NULL,
				status varchar(24) NOT NULL,
				counts longtext NOT NULL,
				checksums longtext NOT NULL,
				reconciled_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY change_set_id (change_set_id)
			) {$collate};",
			"CREATE TABLE {$table( Tables::ROLLBACKS )} (
				id char(36) NOT NULL,
				blueprint_id char(36) NOT NULL,
				from_version_id bigint(20) unsigned NOT NULL,
				to_version_id bigint(20) unsigned NOT NULL,
				reason text NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY blueprint_created (blueprint_id,created_at)
			) {$collate};",
			"CREATE TABLE {$table( Tables::RELATIONS )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id char(36) NOT NULL,
				relation_id char(36) NOT NULL,
				source_id varchar(191) NOT NULL,
				target_id varchar(191) NOT NULL,
				position int(10) unsigned NOT NULL DEFAULT 0,
				payload longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY relation_pair (blueprint_id,relation_id,source_id,target_id),
				KEY relation_source (blueprint_id,relation_id,source_id),
				KEY relation_target (blueprint_id,relation_id,target_id)
			) {$collate};",
			"CREATE TABLE {$table( Tables::MULTIVALUES )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id char(36) NOT NULL,
				field_id char(36) NOT NULL,
				owner_id varchar(191) NOT NULL,
				row_id char(36) NOT NULL,
				position int(10) unsigned NOT NULL DEFAULT 0,
				value longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY field_row (blueprint_id,field_id,owner_id,row_id),
				KEY field_owner (blueprint_id,field_id,owner_id,position)
			) {$collate};",
			"CREATE TABLE {$table( Tables::ENTRY_SUBMISSIONS )} (
				id char(36) NOT NULL,
				blueprint_id char(36) NOT NULL,
				surface_id char(36) NOT NULL,
				actor_key char(64) NOT NULL,
				idempotency_hash char(64) NOT NULL,
				payload_checksum char(64) NOT NULL,
				operation varchar(32) NOT NULL,
				item_id varchar(191) DEFAULT NULL,
				status varchar(24) NOT NULL,
				response longtext DEFAULT NULL,
				error_code varchar(96) DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotent_actor (surface_id,actor_key,idempotency_hash),
				KEY surface_created (surface_id,created_at)
			) {$collate};",
			"CREATE TABLE {$table( Tables::ACTION_JOBS )} (
				id char(36) NOT NULL,
				blueprint_id char(36) NOT NULL,
				surface_id char(36) NOT NULL,
				submission_id char(36) NOT NULL,
				action_id char(36) NOT NULL,
				action_type varchar(64) NOT NULL,
				event varchar(32) NOT NULL,
				status varchar(24) NOT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				context longtext NOT NULL,
				result longtext DEFAULT NULL,
				error_code varchar(96) DEFAULT NULL,
				error_message text DEFAULT NULL,
				available_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY submission_action (submission_id,action_id,event),
				KEY retry_queue (status,available_at)
			) {$collate};",
		];
	}

	private static function expected_columns() {
		return [
			Tables::BLUEPRINTS => [ 'id', 'draft_document', 'active_version_id' ],
			Tables::VERSIONS => [ 'id', 'blueprint_id', 'version', 'checksum', 'document' ],
			Tables::ARTIFACTS => [ 'id', 'version_id', 'kind', 'payload' ],
			Tables::BINDINGS => [ 'id', 'field_id', 'storage_key', 'aliases' ],
			Tables::CHANGE_SETS => [ 'id', 'status', 'impact', 'confirmation_hash' ],
			Tables::LOCKS => [ 'resource_key', 'token_hash', 'expires_at' ],
			Tables::RUNS => [ 'id', 'operation', 'status', 'request_id' ],
			Tables::RECONCILIATIONS => [ 'id', 'change_set_id', 'counts', 'checksums' ],
			Tables::ROLLBACKS => [ 'id', 'from_version_id', 'to_version_id', 'reason' ],
			Tables::RELATIONS => [ 'id', 'relation_id', 'source_id', 'target_id' ],
			Tables::MULTIVALUES => [ 'id', 'field_id', 'owner_id', 'row_id', 'value' ],
			Tables::ENTRY_SUBMISSIONS => [ 'id', 'surface_id', 'actor_key', 'idempotency_hash', 'payload_checksum', 'status', 'response' ],
			Tables::ACTION_JOBS => [ 'id', 'submission_id', 'action_id', 'action_type', 'status', 'attempts', 'context', 'available_at' ],
		];
	}

	private static function error( $code, $detail ) {
		return new \WP_Error(
			$code,
			__( 'WordPress could not install the Toolkit Blueprint infrastructure.', 'elementor-implementation-toolkit' ),
			[ 'database_error' => sanitize_text_field( $detail ) ]
		);
	}
}
