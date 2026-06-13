<?php
/**
 * CCT definition storage and validation.
 */

namespace EIT\CCT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DefinitionManager {

	const OPTION = 'eit_cct_definitions';
	const MAX_FIELDS = 80;

	public static function all( $include_archived = true ) {
		$definitions = get_option( self::OPTION, [] );
		$definitions = is_array( $definitions ) ? $definitions : [];

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
		$definitions = self::all();
		$original_slug = self::sanitize_slug( $raw['original_slug'] ?? '' );
		$slug = self::sanitize_slug( $raw['slug'] ?? '' );

		if ( '' === $slug ) {
			$slug = self::sanitize_slug( $raw['plural'] ?? $raw['singular'] ?? 'content' );
		}

		if ( '' !== $original_slug && $slug !== $original_slug && isset( $definitions[ $original_slug ] ) ) {
			$slug = $original_slug;
		}

		$existing = $definitions[ $slug ] ?? null;
		if ( is_array( $existing ) && ! isset( $raw['state'] ) ) {
			$raw['state'] = $existing['state'] ?? 'active';
		}

		$definition = self::sanitize_definition( $raw, $slug, is_array( $existing ) ? $existing : [] );
		$definitions[ $slug ] = $definition;
		update_option( self::OPTION, $definitions, false );

		SchemaManager::sync_definition( $definition );

		return $slug;
	}

	public static function archive( $slug ) {
		$slug = self::sanitize_slug( $slug );
		$definitions = self::all();

		if ( ! isset( $definitions[ $slug ] ) ) {
			return false;
		}

		$definitions[ $slug ]['state'] = 'archived';
		$definitions[ $slug ]['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION, $definitions, false );

		return true;
	}

	public static function restore( $slug ) {
		$slug = self::sanitize_slug( $slug );
		$definitions = self::all();

		if ( ! isset( $definitions[ $slug ] ) ) {
			return false;
		}

		$definitions[ $slug ]['state'] = 'active';
		$definitions[ $slug ]['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION, $definitions, false );
		SchemaManager::sync_definition( $definitions[ $slug ] );

		return true;
	}

	public static function delete_permanently( $slug ) {
		$slug = self::sanitize_slug( $slug );
		$definitions = self::all();

		if (
			! isset( $definitions[ $slug ] )
			|| 'archived' !== ( $definitions[ $slug ]['state'] ?? 'active' )
		) {
			return false;
		}

		SchemaManager::drop_table( $slug );
		unset( $definitions[ $slug ] );
		update_option( self::OPTION, $definitions, false );

		return true;
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

	private static function sanitize_field_key( $key ) {
		$key = sanitize_key( $key );
		$key = preg_replace( '/[^a-z0-9_]/', '_', $key );
		$key = substr( trim( $key, '_' ), 0, 48 );

		$reserved = [ 'id', 'title', 'status', 'menu_order', 'created_at', 'updated_at' ];
		return in_array( $key, $reserved, true ) ? 'custom_' . $key : $key;
	}
}
