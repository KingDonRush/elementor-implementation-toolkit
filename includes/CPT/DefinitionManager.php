<?php
/**
 * Validated custom post type definition storage.
 */

namespace EIT\CPT;

use EIT\Blueprint\RuntimeDefinitionProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DefinitionManager {

	const OPTION = 'eit_cpt_definitions';
	const MAX_TAXONOMIES = 12;
	const MAX_META_FIELDS = 80;
	const RESERVED_META_KEYS = [ 'id', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type', 'guid', 'menu_order', '_thumbnail_id', '_edit_lock', '_edit_last', '_wp_page_template' ];

	public static function all() {
		return array_replace( self::legacy_all(), RuntimeDefinitionProvider::cpt_definitions() );
	}

	public static function get( $slug ) {
		return self::all()[ self::sanitize_post_type_slug( $slug ) ] ?? null;
	}

	public static function blank() {
		return [
			'slug'         => '',
			'singular'     => '',
			'plural'       => '',
			'description'  => '',
			'menu_icon'    => 'dashicons-screenoptions',
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => true,
			'hierarchical' => false,
			'rewrite_slug' => '',
			'supports'     => [ 'title', 'thumbnail', 'excerpt', 'custom-fields' ],
			'taxonomies'   => [],
			'meta_fields'  => [],
			'updated_at'   => '',
		];
	}

	public static function blank_taxonomy( array $overrides = [] ) {
		return array_merge(
			[
				'slug'         => '',
				'singular'     => '',
				'plural'       => '',
				'hierarchical' => true,
				'public'       => true,
				'show_in_rest' => true,
			],
			$overrides
		);
	}

	public static function blank_meta_field( array $overrides = [] ) {
		return array_merge(
			[
				'key'          => '',
				'label'        => '',
				'type'         => 'text',
				'default'      => '',
				'options'      => '',
				'required'     => false,
				'show_in_rest' => true,
			],
			$overrides
		);
	}

	public static function save( array $raw ) {
		$definitions = self::legacy_all();
		$compiled = RuntimeDefinitionProvider::cpt_definitions();
		$original_slug = self::sanitize_post_type_slug( $raw['original_slug'] ?? '' );
		$slug = self::sanitize_post_type_slug( $raw['slug'] ?? '' );

		if ( '' === $slug ) {
			$slug = self::sanitize_post_type_slug( $raw['plural'] ?? $raw['singular'] ?? 'eit_item' );
		}

		if ( $original_slug && isset( $definitions[ $original_slug ] ) && $slug !== $original_slug ) {
			return new \WP_Error( 'eit_cpt_slug_locked', __( 'A published post type slug cannot be renamed without a migration plan.', 'elementor-implementation-toolkit' ) );
		}

		$slug = $original_slug && isset( $definitions[ $original_slug ] ) ? $original_slug : $slug;
		if ( isset( $compiled[ $original_slug ?: $slug ] ) ) {
			return new \WP_Error( 'eit_cpt_blueprint_owned', __( 'This post type is compiled by a Blueprint and cannot be edited through the legacy manager.', 'elementor-implementation-toolkit' ) );
		}
		if ( self::is_reserved_post_type_slug( $slug ) || ( post_type_exists( $slug ) && ! isset( $definitions[ $slug ] ) ) ) {
			return new \WP_Error( 'eit_cpt_slug_unavailable', __( 'This post type slug is reserved or already registered.', 'elementor-implementation-toolkit' ) );
		}

		$existing = isset( $definitions[ $slug ] ) && is_array( $definitions[ $slug ] ) ? $definitions[ $slug ] : [];
		$validation = self::validate_children( $raw, $existing );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$definitions[ $slug ] = self::sanitize_definition( $raw, $slug );
		if ( ! update_option( self::OPTION, $definitions, false ) && self::legacy_all() !== $definitions ) {
			return new \WP_Error( 'eit_cpt_write_failed', __( 'The post type definition could not be saved.', 'elementor-implementation-toolkit' ) );
		}

		flush_rewrite_rules( false );
		return $slug;
	}

	public static function delete( $slug ) {
		$slug = self::sanitize_post_type_slug( $slug );
		if ( isset( RuntimeDefinitionProvider::cpt_definitions()[ $slug ] ) ) {
			return new \WP_Error( 'eit_cpt_blueprint_owned', __( 'This post type is compiled by a Blueprint and cannot be deleted through the legacy manager.', 'elementor-implementation-toolkit' ) );
		}
		$definitions = self::legacy_all();
		if ( ! isset( $definitions[ $slug ] ) ) {
			return false;
		}

		unset( $definitions[ $slug ] );
		update_option( self::OPTION, $definitions, false );
		flush_rewrite_rules( false );
		return true;
	}

	private static function legacy_all() {
		$definitions = get_option( self::OPTION, [] );
		return is_array( $definitions ) ? $definitions : [];
	}

	public static function supports() {
		return [
			'title'           => __( 'Title', 'elementor-implementation-toolkit' ),
			'editor'          => __( 'WordPress editor', 'elementor-implementation-toolkit' ),
			'thumbnail'       => __( 'Featured image', 'elementor-implementation-toolkit' ),
			'excerpt'         => __( 'Excerpt', 'elementor-implementation-toolkit' ),
			'custom-fields'   => __( 'Custom fields', 'elementor-implementation-toolkit' ),
			'revisions'       => __( 'Revisions', 'elementor-implementation-toolkit' ),
			'page-attributes' => __( 'Page attributes', 'elementor-implementation-toolkit' ),
		];
	}

	public static function meta_field_types() {
		return [
			'text' => __( 'Text', 'elementor-implementation-toolkit' ), 'textarea' => __( 'Textarea', 'elementor-implementation-toolkit' ),
			'number' => __( 'Number', 'elementor-implementation-toolkit' ), 'url' => __( 'URL', 'elementor-implementation-toolkit' ),
			'email' => __( 'Email', 'elementor-implementation-toolkit' ), 'date' => __( 'Date', 'elementor-implementation-toolkit' ),
			'time' => __( 'Time', 'elementor-implementation-toolkit' ), 'datetime' => __( 'Date and time', 'elementor-implementation-toolkit' ),
			'checkbox' => __( 'Checkbox', 'elementor-implementation-toolkit' ), 'select' => __( 'Select', 'elementor-implementation-toolkit' ),
			'radio' => __( 'Radio', 'elementor-implementation-toolkit' ), 'color' => __( 'Color', 'elementor-implementation-toolkit' ),
			'image' => __( 'Image', 'elementor-implementation-toolkit' ), 'gallery' => __( 'Gallery', 'elementor-implementation-toolkit' ),
		];
	}

	public static function sanitize_meta_value( $value, array $field ) {
		if ( null === $value ) {
			return '';
		}

		$type = sanitize_key( $field['type'] ?? 'text' );
		if ( 'number' === $type ) {
			return is_numeric( $value ) ? (string) (float) $value : '';
		}
		if ( 'url' === $type ) {
			return esc_url_raw( $value );
		}
		if ( 'image' === $type ) {
			return self::sanitize_media_reference( $value );
		}
		if ( 'email' === $type ) {
			return sanitize_email( $value );
		}
		if ( 'date' === $type ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : '';
		}
		if ( 'time' === $type ) {
			return preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? (string) $value : '';
		}
		if ( 'datetime' === $type ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? (string) $value : '';
		}
		if ( 'checkbox' === $type ) {
			return self::truthy( $value ) ? '1' : '0';
		}
		if ( 'gallery' === $type ) {
			return self::sanitize_gallery_references( $value );
		}
		if ( 'textarea' === $type ) {
			return sanitize_textarea_field( $value );
		}
		if ( 'color' === $type ) {
			return sanitize_hex_color( $value ) ?: '';
		}
		if ( in_array( $type, [ 'select', 'radio' ], true ) ) {
			return sanitize_key( $value );
		}

		return sanitize_text_field( $value );
	}

	public static function parse_options( $raw ) {
		$options = [];
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$parts = array_map( 'trim', explode( '|', trim( $line ) ) );
			$value = sanitize_key( $parts[0] ?? '' );
			if ( '' !== $value ) {
				$options[] = [ 'value' => $value, 'label' => sanitize_text_field( $parts[1] ?? $parts[0] ) ];
			}
		}
		return $options;
	}

	public static function sanitize_post_type_slug( $value ) {
		return substr( sanitize_key( $value ), 0, 20 );
	}

	public static function sanitize_taxonomy_slug( $value ) {
		return substr( sanitize_key( $value ), 0, 32 );
	}

	private static function sanitize_definition( array $raw, $slug ) {
		$supports = array_values( array_intersect( array_keys( self::supports() ), array_map( 'sanitize_key', (array) ( $raw['supports'] ?? [] ) ) ) );
		if ( empty( $supports ) ) {
			$supports = [ 'title' ];
		}

		return [
			'slug' => $slug, 'singular' => sanitize_text_field( $raw['singular'] ?? '' ), 'plural' => sanitize_text_field( $raw['plural'] ?? '' ),
			'description' => sanitize_textarea_field( $raw['description'] ?? '' ), 'menu_icon' => sanitize_text_field( $raw['menu_icon'] ?? 'dashicons-screenoptions' ),
			'public' => self::truthy( $raw['public'] ?? false ), 'show_in_rest' => self::truthy( $raw['show_in_rest'] ?? false ),
			'has_archive' => self::truthy( $raw['has_archive'] ?? false ), 'hierarchical' => self::truthy( $raw['hierarchical'] ?? false ),
			'rewrite_slug' => sanitize_title( $raw['rewrite_slug'] ?? '' ), 'supports' => $supports,
			'taxonomies' => self::sanitize_taxonomies( $raw['taxonomies'] ?? [] ), 'meta_fields' => self::sanitize_meta_fields( $raw['meta_fields'] ?? [] ),
			'updated_at' => current_time( 'mysql' ),
		];
	}

	private static function sanitize_taxonomies( $taxonomies ) {
		$normalized = [];
		foreach ( array_slice( is_array( $taxonomies ) ? $taxonomies : [], 0, self::MAX_TAXONOMIES ) as $taxonomy ) {
			$slug = self::sanitize_taxonomy_slug( is_array( $taxonomy ) ? ( $taxonomy['slug'] ?? '' ) : '' );
			if ( '' === $slug ) {
				continue;
			}
			$normalized[] = self::blank_taxonomy( [ 'slug' => $slug, 'singular' => sanitize_text_field( $taxonomy['singular'] ?? '' ), 'plural' => sanitize_text_field( $taxonomy['plural'] ?? '' ), 'hierarchical' => self::truthy( $taxonomy['hierarchical'] ?? false ), 'public' => self::truthy( $taxonomy['public'] ?? false ), 'show_in_rest' => self::truthy( $taxonomy['show_in_rest'] ?? false ) ] );
		}
		return $normalized;
	}

	private static function sanitize_meta_fields( $fields ) {
		$normalized = [];
		$types = array_keys( self::meta_field_types() );
		foreach ( array_slice( is_array( $fields ) ? $fields : [], 0, self::MAX_META_FIELDS ) as $field ) {
			$key = sanitize_key( is_array( $field ) ? ( $field['key'] ?? '' ) : '' );
			if ( '' === $key ) {
				continue;
			}
			$type = sanitize_key( $field['type'] ?? 'text' );
			$type = in_array( $type, $types, true ) ? $type : 'text';
			$normalized[] = self::blank_meta_field( [ 'key' => $key, 'label' => sanitize_text_field( $field['label'] ?? '' ), 'type' => $type, 'default' => self::sanitize_meta_value( $field['default'] ?? '', [ 'type' => $type ] ), 'options' => self::normalize_options( $field ), 'required' => self::truthy( $field['required'] ?? false ), 'show_in_rest' => self::truthy( $field['show_in_rest'] ?? false ) ] );
		}
		return $normalized;
	}

	private static function validate_children( array $raw, array $existing ) {
		if ( count( (array) ( $raw['taxonomies'] ?? [] ) ) > self::MAX_TAXONOMIES ) {
			return new \WP_Error( 'eit_cpt_taxonomy_limit', sprintf( __( 'A post type can contain at most %d taxonomies.', 'elementor-implementation-toolkit' ), self::MAX_TAXONOMIES ) );
		}

		if ( count( (array) ( $raw['meta_fields'] ?? [] ) ) > self::MAX_META_FIELDS ) {
			return new \WP_Error( 'eit_cpt_field_limit', sprintf( __( 'A post type can contain at most %d fields.', 'elementor-implementation-toolkit' ), self::MAX_META_FIELDS ) );
		}

		$existing_taxonomies = [];
		foreach ( $existing['taxonomies'] ?? [] as $taxonomy ) {
			$existing_taxonomies[ self::sanitize_taxonomy_slug( $taxonomy['slug'] ?? '' ) ] = true;
		}
		$seen_taxonomies = [];
		foreach ( (array) ( $raw['taxonomies'] ?? [] ) as $taxonomy ) {
			$slug = self::sanitize_taxonomy_slug( is_array( $taxonomy ) ? ( $taxonomy['slug'] ?? '' ) : '' );
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( $seen_taxonomies[ $slug ] ) ) {
				return new \WP_Error( 'eit_cpt_duplicate_taxonomy', sprintf( __( 'The taxonomy slug "%s" is duplicated.', 'elementor-implementation-toolkit' ), $slug ) );
			}
			if ( self::is_reserved_taxonomy_slug( $slug ) || ( taxonomy_exists( $slug ) && ! isset( $existing_taxonomies[ $slug ] ) ) ) {
				return new \WP_Error( 'eit_cpt_taxonomy_unavailable', sprintf( __( 'The taxonomy slug "%s" is reserved or already registered.', 'elementor-implementation-toolkit' ), $slug ) );
			}
			$original = self::sanitize_taxonomy_slug( $taxonomy['original_slug'] ?? '' );
			if ( $original && $original !== $slug ) {
				return new \WP_Error( 'eit_cpt_taxonomy_slug_locked', sprintf( __( 'The taxonomy slug "%s" requires a migration plan to rename.', 'elementor-implementation-toolkit' ), $original ) );
			}
			$seen_taxonomies[ $slug ] = true;
		}

		$existing_fields = [];
		foreach ( $existing['meta_fields'] ?? [] as $field ) {
			$existing_fields[ sanitize_key( $field['key'] ?? '' ) ] = $field;
		}
		$seen_fields = [];
		foreach ( (array) ( $raw['meta_fields'] ?? [] ) as $field ) {
			$key = sanitize_key( is_array( $field ) ? ( $field['key'] ?? '' ) : '' );
			if ( '' === $key ) {
				continue;
			}
			if ( '_' === substr( $key, 0, 1 ) || in_array( $key, self::RESERVED_META_KEYS, true ) ) {
				return new \WP_Error( 'eit_cpt_reserved_field', sprintf( __( 'The field key "%s" is reserved by WordPress.', 'elementor-implementation-toolkit' ), $key ) );
			}
			if ( isset( $seen_fields[ $key ] ) ) {
				return new \WP_Error( 'eit_cpt_duplicate_field', sprintf( __( 'The field key "%s" is duplicated.', 'elementor-implementation-toolkit' ), $key ) );
			}
			$original = sanitize_key( $field['original_key'] ?? '' );
			if ( $original && $original !== $key ) {
				return new \WP_Error( 'eit_cpt_field_key_locked', sprintf( __( 'The field key "%s" requires a migration plan to rename.', 'elementor-implementation-toolkit' ), $original ) );
			}
			$type = sanitize_key( $field['type'] ?? 'text' );
			if ( isset( $existing_fields[ $key ] ) && $type !== ( $existing_fields[ $key ]['type'] ?? 'text' ) ) {
				return new \WP_Error( 'eit_cpt_field_type_locked', sprintf( __( 'The field "%s" requires a migration plan to change type.', 'elementor-implementation-toolkit' ), $key ) );
			}
			$seen_fields[ $key ] = true;
		}

		return true;
	}

	private static function normalize_options( array $field ) {
		$lines = [];
		foreach ( array_slice( (array) ( $field['options_items'] ?? [] ), 0, 100 ) as $item ) {
			$value = sanitize_key( is_array( $item ) ? ( $item['value'] ?? $item['label'] ?? '' ) : '' );
			$label = sanitize_text_field( is_array( $item ) ? ( $item['label'] ?? '' ) : '' );
			if ( $value ) {
				$lines[] = $value . '|' . ( $label ?: $value );
			}
		}
		return $lines ? implode( "\n", $lines ) : implode( "\n", array_slice( preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( $field['options'] ?? '' ) ), 0, 100 ) );
	}

	private static function sanitize_media_reference( $value ) {
		$value = trim( (string) $value );
		return ctype_digit( $value ) ? (string) absint( $value ) : esc_url_raw( $value );
	}

	private static function sanitize_gallery_references( $value ) {
		$references = array_filter( array_map( [ self::class, 'sanitize_media_reference' ], preg_split( '/\r\n|\r|\n|,/', (string) $value ) ) );
		return implode( ',', array_slice( $references, 0, 80 ) );
	}

	private static function is_reserved_post_type_slug( $slug ) {
		return in_array( $slug, [ 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ], true );
	}

	private static function is_reserved_taxonomy_slug( $slug ) {
		return in_array( $slug, [ 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ], true );
	}

	private static function truthy( $value ) {
		return in_array( $value, [ true, 1, '1', 'yes', 'on', 'true' ], true );
	}
}
