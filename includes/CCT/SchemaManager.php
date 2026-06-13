<?php
/**
 * Database schema lifecycle for table-backed CCT definitions.
 */

namespace EIT\CCT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SchemaManager {

	const VERSION = '1';
	const VERSION_OPTION = 'eit_cct_schema_version';

	public static function maybe_upgrade() {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install_all();
	}

	public static function install_all() {
		foreach ( DefinitionManager::all() as $definition ) {
			self::sync_definition( $definition );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	public static function sync_definition( array $definition ) {
		global $wpdb;

		$slug = DefinitionManager::sanitize_slug( $definition['slug'] ?? '' );
		if ( '' === $slug ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table_name( $slug );
		$charset = $wpdb->get_charset_collate();
		$columns = [
			'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
			'title text NOT NULL',
			'status varchar(20) NOT NULL DEFAULT \'publish\'',
			'menu_order int(11) NOT NULL DEFAULT 0',
			'created_at datetime NOT NULL',
			'updated_at datetime NOT NULL',
		];

		foreach ( $definition['fields'] ?? [] as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$columns[] = self::column_name( $key ) . ' ' . FieldTypes::sql_type( $field['type'] ?? 'text' );
		}

		$columns[] = 'PRIMARY KEY  (id)';
		$columns[] = 'KEY status (status)';
		$columns[] = 'KEY menu_order (menu_order)';
		$columns[] = 'KEY updated_at (updated_at)';

		$sql = "CREATE TABLE {$table} (\n" . implode( ",\n", $columns ) . "\n) {$charset};";
		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	public static function drop_table( $slug ) {
		global $wpdb;

		$slug = DefinitionManager::sanitize_slug( $slug );
		if ( '' === $slug ) {
			return;
		}

		$table = self::table_name( $slug );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function table_name( $slug ) {
		global $wpdb;
		$slug = DefinitionManager::sanitize_slug( $slug );
		return $wpdb->prefix . 'eit_cct_' . $slug;
	}

	public static function column_name( $key ) {
		$key = sanitize_key( $key );
		return 'f_' . substr( preg_replace( '/[^a-z0-9_]/', '_', $key ), 0, 48 );
	}
}
