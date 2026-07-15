<?php
/**
 * Verified database schema lifecycle for table-backed CCT definitions.
 */

namespace EIT\CCT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SchemaManager {

	const VERSION = '3';
	const VERSION_OPTION = 'eit_cct_schema_version';

	public static function maybe_upgrade() {
		return self::VERSION === get_option( self::VERSION_OPTION ) ? true : self::install_all();
	}

	public static function install_all() {
		foreach ( DefinitionManager::all() as $definition ) {
			$result = self::sync_definition( $definition );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! update_option( self::VERSION_OPTION, self::VERSION, false ) && self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			return new \WP_Error( 'eit_cct_schema_version_write_failed', __( 'Content table storage was installed, but its schema version could not be recorded.', 'elementor-implementation-toolkit' ) );
		}
		return true;
	}

	public static function sync_definition( array $definition ) {
		global $wpdb;

		$slug = DefinitionManager::sanitize_slug( $definition['slug'] ?? '' );
		if ( '' === $slug ) {
			return new \WP_Error( 'eit_cct_invalid_schema_slug', __( 'A valid content type slug is required before creating storage.', 'elementor-implementation-toolkit' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table_name( $slug );
		$columns = self::column_definitions( $definition );
		$indexes = self::index_definitions( $definition );
		$sql = "CREATE TABLE {$table} (\n" . implode( ",\n", array_merge( $columns, $indexes ) ) . "\n) " . $wpdb->get_charset_collate() . ';';
		$previous_suppression = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';

		try {
			dbDelta( $sql );
			if ( '' !== $wpdb->last_error ) {
				return self::database_error( 'eit_cct_schema_failed', $wpdb->last_error );
			}

			return self::verify_schema( $table, $definition );
		} finally {
			$wpdb->suppress_errors( $previous_suppression );
		}
	}

	public static function drop_table( $slug ) {
		global $wpdb;

		$slug = DefinitionManager::sanitize_slug( $slug );
		if ( '' === $slug ) {
			return new \WP_Error( 'eit_cct_invalid_drop_slug', __( 'A valid content type slug is required.', 'elementor-implementation-toolkit' ) );
		}

		$table = self::table_name( $slug );
		$wpdb->last_error = '';
		$result = $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $result || '' !== $wpdb->last_error ) {
			return self::database_error( 'eit_cct_drop_failed', $wpdb->last_error );
		}

		return true;
	}

	public static function table_exists( $slug ) {
		global $wpdb;

		$table = self::table_name( $slug );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	public static function table_name( $slug ) {
		global $wpdb;
		return $wpdb->prefix . 'eit_cct_' . DefinitionManager::sanitize_slug( $slug );
	}

	public static function column_name( $key ) {
		$key = sanitize_key( $key );
		return 'f_' . substr( preg_replace( '/[^a-z0-9_]/', '_', $key ), 0, 48 );
	}

	private static function column_definitions( array $definition ) {
		$columns = [
			'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
			'title text NOT NULL',
			'status varchar(20) NOT NULL DEFAULT \'publish\'',
			'author_id bigint(20) unsigned NOT NULL DEFAULT 0',
			'menu_order int(11) NOT NULL DEFAULT 0',
			'created_at datetime NOT NULL',
			'updated_at datetime NOT NULL',
		];

		foreach ( $definition['fields'] ?? [] as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );
			if ( '' !== $key ) {
				$columns[] = self::column_name( $key ) . ' ' . FieldTypes::sql_type( $field['type'] ?? 'text' );
			}
		}

		return $columns;
	}

	private static function index_definitions( array $definition ) {
		$indexes = [
			'PRIMARY KEY  (id)',
			'KEY status (status)',
			'KEY author_status (author_id,status)',
			'KEY menu_order (menu_order)',
			'KEY updated_at (updated_at)',
		];

		foreach ( $definition['fields'] ?? [] as $field ) {
			$type = sanitize_key( $field['type'] ?? 'text' );
			if ( ( empty( $field['filterable'] ) && empty( $field['sortable'] ) ) || empty( $field['active'] ) || ! FieldTypes::is_indexable( $type ) ) {
				continue;
			}

			$column = self::column_name( $field['key'] ?? '' );
			$index = self::index_name( $column );
			$indexes[] = "KEY {$index} (" . FieldTypes::index_column_sql( $column, $type ) . ')';
		}

		return $indexes;
	}

	private static function verify_schema( $table, array $definition ) {
		global $wpdb;

		$found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $found_table ) {
			return new \WP_Error( 'eit_cct_table_missing', __( 'The content table was not created.', 'elementor-implementation-toolkit' ) );
		}

		$actual_columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$expected_columns = [ 'id', 'title', 'status', 'author_id', 'menu_order', 'created_at', 'updated_at' ];
		foreach ( $definition['fields'] ?? [] as $field ) {
			$expected_columns[] = self::column_name( $field['key'] ?? '' );
		}

		$missing_columns = array_diff( $expected_columns, $actual_columns ?: [] );
		if ( $missing_columns ) {
			return new \WP_Error(
				'eit_cct_columns_missing',
				sprintf(
					/* translators: %s: comma-separated database column names. */
					__( 'Storage validation failed for columns: %s.', 'elementor-implementation-toolkit' ),
					implode( ', ', $missing_columns )
				)
			);
		}

		$actual_indexes = $wpdb->get_col( "SHOW INDEX FROM `{$table}`", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( self::expected_index_names( $definition ) as $index ) {
			if ( ! in_array( $index, $actual_indexes ?: [], true ) ) {
				return new \WP_Error(
					'eit_cct_index_missing',
					sprintf(
						/* translators: %s: database index name. */
						__( 'Storage validation failed for index %s.', 'elementor-implementation-toolkit' ),
						$index
					)
				);
			}
		}

		return true;
	}

	private static function expected_index_names( array $definition ) {
		$names = [ 'PRIMARY', 'status', 'author_status', 'menu_order', 'updated_at' ];
		foreach ( $definition['fields'] ?? [] as $field ) {
			if ( ( ! empty( $field['filterable'] ) || ! empty( $field['sortable'] ) ) && ! empty( $field['active'] ) && FieldTypes::is_indexable( $field['type'] ?? '' ) ) {
				$names[] = self::index_name( self::column_name( $field['key'] ?? '' ) );
			}
		}

		return $names;
	}

	private static function index_name( $column ) {
		return substr( 'eit_' . sanitize_key( $column ), 0, 60 );
	}

	private static function database_error( $code, $detail ) {
		$message = __( 'WordPress could not reconcile the content table schema.', 'elementor-implementation-toolkit' );
		return new \WP_Error( $code, $message, [ 'database_error' => sanitize_text_field( $detail ) ] );
	}
}
