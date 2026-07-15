<?php
/**
 * CCT definition storage and validation.
 */

namespace EIT\CCT;

use EIT\Blueprint\RuntimeDefinitionProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DefinitionManager {

	const OPTION = 'eit_cct_definitions';
	const MAX_FIELDS = 80;
	const RESERVED_FIELDS = [ 'id', 'title', 'status', 'menu_order', 'created_at', 'updated_at' ];

	public static function all( $include_archived = true ) {
		$definitions = array_replace( self::legacy_all(), RuntimeDefinitionProvider::cct_definitions() );

		if ( $include_archived ) {
			return $definitions;
		}

		return array_filter(
			$definitions,
			function ( $definition ) {
				return 'active' === ( $definition['state'] ?? 'active' );
			}
		);
	}

	public static function get( $slug, $include_archived = true ) {
		$slug = self::sanitize_slug( $slug );
		$definitions = self::all( $include_archived );

		return $definitions[ $slug ] ?? null;
	}

	public static function blank() {
		return [
			'slug'        => '',
			'singular'    => '',
			'plural'      => '',
			'description' => '',
			'menu_icon'   => 'dashicons-database',
			'public'      => true,
			'state'       => 'active',
			'fields'      => [],
			'updated_at'  => '',
		];
	}

	public static function blank_field( array $overrides = [] ) {
		return array_merge(
			[
				'key'        => '',
				'label'      => '',
				'type'       => 'text',
				'default'    => '',
				'options'    => '',
				'required'   => false,
				'filterable' => false,
				'active'     => true,
			],
			$overrides
		);
	}

	public static function save( array $raw ) {
		$definitions = self::legacy_all();
		$compiled = RuntimeDefinitionProvider::cct_definitions();
		$original_slug = self::sanitize_slug( $raw['original_slug'] ?? '' );
		$slug = self::sanitize_slug( $raw['slug'] ?? '' );

		if ( '' === $slug ) {
			$slug = self::sanitize_slug( $raw['plural'] ?? $raw['singular'] ?? 'content' );
		}

		if ( '' !== $original_slug && isset( $definitions[ $original_slug ] ) && $slug !== $original_slug ) {
			return new \WP_Error( 'eit_cct_slug_locked', __( 'A published content type slug cannot be renamed without a migration plan.', 'elementor-implementation-toolkit' ) );
		}

		$slug = $original_slug && isset( $definitions[ $original_slug ] ) ? $original_slug : $slug;
		if ( isset( $compiled[ $original_slug ?: $slug ] ) ) {
			return new \WP_Error( 'eit_cct_blueprint_owned', __( 'This content type is compiled by a Blueprint and cannot be edited through the legacy manager.', 'elementor-implementation-toolkit' ) );
		}
		$existing = $definitions[ $slug ] ?? null;
		if ( ! is_array( $existing ) && SchemaManager::table_exists( $slug ) ) {
			return new \WP_Error( 'eit_cct_orphan_table', __( 'Storage already exists for this slug but is not owned by a published definition.', 'elementor-implementation-toolkit' ) );
		}
		if ( is_array( $existing ) && ! isset( $raw['state'] ) ) {
			$raw['state'] = $existing['state'] ?? 'active';
		}

		$validation = self::validate_fields( (array) ( $raw['fields'] ?? [] ), is_array( $existing ) ? $existing : [] );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$definition = self::sanitize_definition( $raw, $slug, is_array( $existing ) ? $existing : [] );
		$schema_result = SchemaManager::sync_definition( $definition );
		if ( is_wp_error( $schema_result ) ) {
			return $schema_result;
		}

		$definitions[ $slug ] = $definition;
		if ( ! update_option( self::OPTION, $definitions, false ) && self::legacy_all() !== $definitions ) {
			return new \WP_Error( 'eit_cct_definition_write_failed', __( 'The content type definition could not be saved.', 'elementor-implementation-toolkit' ) );
		}
		return $slug;
	}

	public static function archive( $slug ) {
		$slug = self::sanitize_slug( $slug );
		if ( isset( RuntimeDefinitionProvider::cct_definitions()[ $slug ] ) ) {
			return new \WP_Error( 'eit_cct_blueprint_owned', __( 'This content type is compiled by a Blueprint and cannot be changed through the legacy manager.', 'elementor-implementation-toolkit' ) );
		}
		$definitions = self::legacy_all();

		if ( ! isset( $definitions[ $slug ] ) ) {
			return false;
		}
		$definitions[ $slug ]['state'] = 'archived';
		$definitions[ $slug ]['updated_at'] = current_time( 'mysql' );
		if ( ! update_option( self::OPTION, $definitions, false ) && self::legacy_all() !== $definitions ) {
			return new \WP_Error( 'eit_cct_archive_failed', __( 'The content type could not be archived.', 'elementor-implementation-toolkit' ) );
		}

		return true;
	}

	public static function restore( $slug ) {
		$slug = self::sanitize_slug( $slug );
		if ( isset( RuntimeDefinitionProvider::cct_definitions()[ $slug ] ) ) {
			return new \WP_Error( 'eit_cct_blueprint_owned', __( 'This content type is compiled by a Blueprint and cannot be changed through the legacy manager.', 'elementor-implementation-toolkit' ) );
		}
		$definitions = self::legacy_all();

		if ( ! isset( $definitions[ $slug ] ) ) {
			return false;
		}
		$definitions[ $slug ]['state'] = 'active';
		$definitions[ $slug ]['updated_at'] = current_time( 'mysql' );
		$result = SchemaManager::sync_definition( $definitions[ $slug ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! update_option( self::OPTION, $definitions, false ) && self::legacy_all() !== $definitions ) {
			return new \WP_Error( 'eit_cct_restore_failed', __( 'The content type storage was verified, but its active state could not be saved.', 'elementor-implementation-toolkit' ) );
		}

		return true;
	}

	public static function delete_permanently( $slug ) {
		$slug = self::sanitize_slug( $slug );
		if ( isset( RuntimeDefinitionProvider::cct_definitions()[ $slug ] ) ) {
			return new \WP_Error( 'eit_cct_blueprint_owned', __( 'This content type is compiled by a Blueprint and cannot be deleted through the legacy manager.', 'elementor-implementation-toolkit' ) );
		}
		$definitions = self::legacy_all();
		if (
			! isset( $definitions[ $slug ] )
			|| 'archived' !== ( $definitions[ $slug ]['state'] ?? 'active' )
		) {
			return false;
		}

		$previous_definitions = $definitions;
		unset( $definitions[ $slug ] );
		if ( ! update_option( self::OPTION, $definitions, false ) && self::legacy_all() !== $definitions ) {
			return new \WP_Error( 'eit_cct_delete_prepare_failed', __( 'The content type could not be prepared for deletion, so its table was preserved.', 'elementor-implementation-toolkit' ) );
		}

		$result = SchemaManager::drop_table( $slug );
		if ( is_wp_error( $result ) ) {
			$restored = update_option( self::OPTION, $previous_definitions, false ) || self::legacy_all() === $previous_definitions;
			if ( ! $restored ) {
				return new \WP_Error( 'eit_cct_delete_recovery_failed', __( 'Table deletion failed and the definition could not be restored automatically.', 'elementor-implementation-toolkit' ) );
			}
			return $result;
		}

		return true;
	}

	private static function legacy_all() {
		$definitions = get_option( self::OPTION, [] );
		return is_array( $definitions ) ? $definitions : [];
	}

	public static function fields( $slug, $active_only = true ) {
		$definition = self::get( $slug );

		if ( ! $definition ) {
			return [];
		}

		$fields = [];
		foreach ( $definition['fields'] ?? [] as $field ) {
			if ( $active_only && empty( $field['active'] ) ) {
				continue;
			}
			$fields[ $field['key'] ] = $field;
		}

		return $fields;
	}

	public static function sanitize_slug( $slug ) {
		$slug = sanitize_key( $slug );
		$slug = preg_replace( '/[^a-z0-9_]/', '_', $slug );
		return substr( trim( $slug, '_' ), 0, 32 );
	}

	public static function sanitize_definition( array $raw, $slug, array $existing = [] ) {
		$fields = [];
		$seen = [];

		foreach ( array_slice( (array) ( $raw['fields'] ?? [] ), 0, self::MAX_FIELDS ) as $raw_field ) {
			if ( ! is_array( $raw_field ) ) {
				continue;
			}

			$key = self::sanitize_field_key( $raw_field['key'] ?? '' );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$fields[] = self::sanitize_field( $raw_field, $key );
		}

		foreach ( $existing['fields'] ?? [] as $existing_field ) {
			$key = self::sanitize_field_key( $existing_field['key'] ?? '' );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$existing_field['active'] = false;
			$fields[] = self::sanitize_field( $existing_field, $key );
		}

		return [
			'slug'        => $slug,
			'singular'    => sanitize_text_field( $raw['singular'] ?? ucfirst( $slug ) ),
			'plural'      => sanitize_text_field( $raw['plural'] ?? ucfirst( $slug ) ),
			'description' => sanitize_textarea_field( $raw['description'] ?? '' ),
			'menu_icon'   => sanitize_html_class( $raw['menu_icon'] ?? 'dashicons-database' ),
			'public'      => ! empty( $raw['public'] ),
			'state'       => 'archived' === ( $raw['state'] ?? '' ) ? 'archived' : 'active',
			'fields'      => $fields,
			'updated_at'  => current_time( 'mysql' ),
		];
	}

	private static function sanitize_field( array $raw_field, $key ) {
		$type = sanitize_key( $raw_field['type'] ?? 'text' );
		$type = FieldTypes::has( $type ) ? $type : 'text';

		return [
			'key'        => $key,
			'label'      => sanitize_text_field( $raw_field['label'] ?? $key ),
			'type'       => $type,
			'default'    => FieldTypes::sanitize( $raw_field['default'] ?? '', [ 'type' => $type, 'options' => $raw_field['options'] ?? '' ] ),
			'options'    => sanitize_textarea_field( $raw_field['options'] ?? '' ),
			'required'   => ! empty( $raw_field['required'] ),
			'filterable' => ! empty( $raw_field['filterable'] ),
			'active'     => ! isset( $raw_field['active'] ) || ! empty( $raw_field['active'] ),
		];
	}

	public static function sanitize_field_key( $key ) {
		$key = sanitize_key( $key );
		$key = preg_replace( '/[^a-z0-9_]/', '_', $key );
		return substr( trim( $key, '_' ), 0, 48 );
	}

	private static function validate_fields( array $raw_fields, array $existing ) {
		if ( count( $raw_fields ) > self::MAX_FIELDS ) {
			return new \WP_Error( 'eit_cct_field_limit', sprintf( __( 'A content type can contain at most %d fields.', 'elementor-implementation-toolkit' ), self::MAX_FIELDS ) );
		}

		$seen = [];
		$existing_fields = [];
		foreach ( $existing['fields'] ?? [] as $field ) {
			$existing_fields[ self::sanitize_field_key( $field['key'] ?? '' ) ] = $field;
		}

		foreach ( $raw_fields as $raw_field ) {
			if ( ! is_array( $raw_field ) ) {
				continue;
			}

			$key = self::sanitize_field_key( $raw_field['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			if ( in_array( $key, self::RESERVED_FIELDS, true ) ) {
				return new \WP_Error( 'eit_cct_reserved_field', sprintf( __( 'The field key "%s" is reserved by the content table.', 'elementor-implementation-toolkit' ), $key ) );
			}
			if ( isset( $seen[ $key ] ) ) {
				return new \WP_Error( 'eit_cct_duplicate_field', sprintf( __( 'The field key "%s" is duplicated.', 'elementor-implementation-toolkit' ), $key ) );
			}

			$original_key = self::sanitize_field_key( $raw_field['original_key'] ?? '' );
			if ( '' !== $original_key && $key !== $original_key ) {
				return new \WP_Error( 'eit_cct_field_key_locked', sprintf( __( 'The published field key "%s" requires a migration plan to rename.', 'elementor-implementation-toolkit' ), $original_key ) );
			}

			$type = sanitize_key( $raw_field['type'] ?? 'text' );
			if ( isset( $existing_fields[ $key ] ) && $type !== ( $existing_fields[ $key ]['type'] ?? 'text' ) ) {
				return new \WP_Error( 'eit_cct_field_type_locked', sprintf( __( 'The published field "%s" requires a migration plan to change type.', 'elementor-implementation-toolkit' ), $key ) );
			}
			$seen[ $key ] = true;
		}

		return true;
	}
}
