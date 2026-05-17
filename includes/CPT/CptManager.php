<?php
/**
 * Lightweight custom post type manager.
 */

namespace EIT\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CptManager {

	const OPTION = 'eit_cpt_definitions';
	const MAX_TAXONOMIES = 12;
	const MAX_META_FIELDS = 40;

	public function init_hooks() {
		add_action( 'init', [ $this, 'register_definitions' ], 9 );
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_meta_box' ] );
	}

	public static function all() {
		$definitions = get_option( self::OPTION, [] );

		return is_array( $definitions ) ? $definitions : [];
	}

	public static function get( $slug ) {
		$slug = self::sanitize_post_type_slug( $slug );
		$definitions = self::all();

		return $definitions[ $slug ] ?? null;
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
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
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

	public static function save_definition( array $raw ) {
		$definitions = self::all();
		$slug = self::sanitize_post_type_slug( $raw['slug'] ?? '' );

		if ( '' === $slug ) {
			$slug = self::sanitize_post_type_slug( $raw['plural'] ?? $raw['singular'] ?? 'eit_item' );
		}

		if ( self::is_reserved_post_type_slug( $slug ) || ( post_type_exists( $slug ) && ! isset( $definitions[ $slug ] ) ) ) {
			$slug = self::sanitize_post_type_slug( 'eit_' . $slug );
		}

		$definition = self::sanitize_definition( $raw, $slug );
		$definitions[ $slug ] = $definition;

		update_option( self::OPTION, $definitions, false );
		flush_rewrite_rules( false );

		return $slug;
	}

	public static function delete_definition( $slug ) {
		$slug = self::sanitize_post_type_slug( $slug );
		$definitions = self::all();

		if ( isset( $definitions[ $slug ] ) ) {
			unset( $definitions[ $slug ] );
			update_option( self::OPTION, $definitions, false );
			flush_rewrite_rules( false );
		}
	}

	public static function supports() {
		return [
			'title'           => __( 'Title', 'elementor-implementation-toolkit' ),
			'editor'          => __( 'Editor', 'elementor-implementation-toolkit' ),
			'thumbnail'       => __( 'Featured image', 'elementor-implementation-toolkit' ),
			'excerpt'         => __( 'Excerpt', 'elementor-implementation-toolkit' ),
			'custom-fields'   => __( 'Custom fields', 'elementor-implementation-toolkit' ),
			'revisions'       => __( 'Revisions', 'elementor-implementation-toolkit' ),
			'page-attributes' => __( 'Page attributes', 'elementor-implementation-toolkit' ),
		];
	}

	public static function meta_field_types() {
		return [
			'text'     => __( 'Text', 'elementor-implementation-toolkit' ),
			'textarea' => __( 'Textarea', 'elementor-implementation-toolkit' ),
			'number'   => __( 'Number', 'elementor-implementation-toolkit' ),
			'url'      => __( 'URL', 'elementor-implementation-toolkit' ),
			'date'     => __( 'Date', 'elementor-implementation-toolkit' ),
			'checkbox' => __( 'Checkbox', 'elementor-implementation-toolkit' ),
			'select'   => __( 'Select', 'elementor-implementation-toolkit' ),
			'color'    => __( 'Color', 'elementor-implementation-toolkit' ),
		];
	}

	public function register_definitions() {
		foreach ( self::all() as $slug => $definition ) {
			$this->register_post_type( $slug, $definition );
			$this->register_taxonomies( $slug, $definition['taxonomies'] ?? [] );
			$this->register_meta_fields( $slug, $definition['meta_fields'] ?? [] );
		}
	}

	public function register_meta_boxes() {
		foreach ( self::all() as $slug => $definition ) {
			if ( empty( $definition['meta_fields'] ) ) {
				continue;
			}

			add_meta_box(
				'eit-managed-fields',
				__( 'Implementation Toolkit Fields', 'elementor-implementation-toolkit' ),
				[ $this, 'render_meta_box' ],
				$slug,
				'normal',
				'default'
			);
		}
	}

	public function render_meta_box( $post ) {
		$definition = self::get( $post->post_type );

		if ( ! $definition || empty( $definition['meta_fields'] ) ) {
			return;
		}

		wp_nonce_field( 'eit_save_meta_box_' . $post->ID, 'eit_meta_box_nonce' );

		echo '<div class="eit-managed-fields">';

		foreach ( $definition['meta_fields'] as $field ) {
			$key = $field['key'];
			$value = get_post_meta( $post->ID, $key, true );

			if ( '' === $value && '' !== (string) ( $field['default'] ?? '' ) ) {
				$value = $field['default'];
			}

			$this->render_meta_field_input( $field, $value );
		}

		echo '</div>';
	}

	public function save_meta_box( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['eit_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eit_meta_box_nonce'] ) ), 'eit_save_meta_box_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		$definition = self::get( $post_type );

		if ( ! $definition || empty( $definition['meta_fields'] ) ) {
			return;
		}

		$submitted = isset( $_POST['eit_meta'] ) && is_array( $_POST['eit_meta'] ) ? wp_unslash( $_POST['eit_meta'] ) : [];

		foreach ( $definition['meta_fields'] as $field ) {
			$key = $field['key'];
			$value = $submitted[ $key ] ?? null;

			if ( 'checkbox' === $field['type'] ) {
				update_post_meta( $post_id, $key, null !== $value ? '1' : '0' );
				continue;
			}

			$value = self::sanitize_meta_value( $value, $field );

			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	private function register_post_type( $slug, array $definition ) {
		$singular = $definition['singular'] ?: ucfirst( str_replace( '_', ' ', $slug ) );
		$plural = $definition['plural'] ?: $singular . 's';

		register_post_type(
			$slug,
			[
				'labels'       => [
					'name'               => $plural,
					'singular_name'      => $singular,
					'add_new_item'       => sprintf( __( 'Add New %s', 'elementor-implementation-toolkit' ), $singular ),
					'edit_item'          => sprintf( __( 'Edit %s', 'elementor-implementation-toolkit' ), $singular ),
					'new_item'           => sprintf( __( 'New %s', 'elementor-implementation-toolkit' ), $singular ),
					'view_item'          => sprintf( __( 'View %s', 'elementor-implementation-toolkit' ), $singular ),
					'search_items'       => sprintf( __( 'Search %s', 'elementor-implementation-toolkit' ), $plural ),
					'not_found'          => sprintf( __( 'No %s found', 'elementor-implementation-toolkit' ), strtolower( $plural ) ),
					'not_found_in_trash' => sprintf( __( 'No %s found in Trash', 'elementor-implementation-toolkit' ), strtolower( $plural ) ),
				],
				'description'  => $definition['description'] ?? '',
				'public'       => ! empty( $definition['public'] ),
				'show_ui'      => true,
				'show_in_menu' => true,
				'show_in_rest' => ! empty( $definition['show_in_rest'] ),
				'has_archive'  => ! empty( $definition['has_archive'] ),
				'hierarchical' => ! empty( $definition['hierarchical'] ),
				'menu_icon'    => $definition['menu_icon'] ?: 'dashicons-screenoptions',
				'rewrite'      => [
					'slug' => $definition['rewrite_slug'] ?: $slug,
				],
				'supports'     => $definition['supports'] ?? [ 'title', 'editor' ],
			]
		);
	}

	private function register_taxonomies( $post_type, array $taxonomies ) {
		foreach ( $taxonomies as $taxonomy ) {
			$slug = self::sanitize_taxonomy_slug( $taxonomy['slug'] ?? '' );

			if ( '' === $slug ) {
				continue;
			}

			$singular = $taxonomy['singular'] ?: ucfirst( str_replace( '_', ' ', $slug ) );
			$plural = $taxonomy['plural'] ?: $singular . 's';

			register_taxonomy(
				$slug,
				[ $post_type ],
				[
					'labels'       => [
						'name'          => $plural,
						'singular_name' => $singular,
						'search_items'  => sprintf( __( 'Search %s', 'elementor-implementation-toolkit' ), $plural ),
						'all_items'     => sprintf( __( 'All %s', 'elementor-implementation-toolkit' ), $plural ),
						'edit_item'     => sprintf( __( 'Edit %s', 'elementor-implementation-toolkit' ), $singular ),
						'add_new_item'  => sprintf( __( 'Add New %s', 'elementor-implementation-toolkit' ), $singular ),
					],
					'public'       => ! empty( $taxonomy['public'] ),
					'hierarchical' => ! empty( $taxonomy['hierarchical'] ),
					'show_ui'      => true,
					'show_admin_column' => true,
					'show_in_rest' => ! empty( $taxonomy['show_in_rest'] ),
					'rewrite'      => [
						'slug' => $slug,
					],
				]
			);
		}
	}

	private function register_meta_fields( $post_type, array $fields ) {
		foreach ( $fields as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			register_post_meta(
				$post_type,
				$key,
				[
					'type'              => $this->rest_type_for_field( $field ),
					'single'            => true,
					'show_in_rest'      => ! empty( $field['show_in_rest'] ),
					'sanitize_callback' => function ( $value ) use ( $field ) {
						return self::sanitize_meta_value( $value, $field );
					},
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
				]
			);
		}
	}

	private function render_meta_field_input( array $field, $value ) {
		$key = $field['key'];
		$id = 'eit-meta-' . $key;
		$name = 'eit_meta[' . $key . ']';

		echo '<p class="eit-managed-field eit-managed-field--' . esc_attr( $field['type'] ) . '">';
		echo '<label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $field['label'] ?: $key ) . '</strong></label>';

		if ( 'textarea' === $field['type'] ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="widefat" rows="4">' . esc_textarea( $value ) . '</textarea>';
		} elseif ( 'select' === $field['type'] ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="widefat">';
			foreach ( self::parse_options( $field['options'] ?? '' ) as $option ) {
				echo '<option value="' . esc_attr( $option['value'] ) . '" ' . selected( (string) $value, (string) $option['value'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'checkbox' === $field['type'] ) {
			echo '<label class="eit-checkbox-inline"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( (string) $value, '1', false ) . ' /> ' . esc_html__( 'Enabled', 'elementor-implementation-toolkit' ) . '</label>';
		} else {
			$type = in_array( $field['type'], [ 'number', 'url', 'date', 'color' ], true ) ? $field['type'] : 'text';
			echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="widefat" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" />';
		}

		echo '</p>';
	}

	private function rest_type_for_field( array $field ) {
		if ( 'number' === ( $field['type'] ?? '' ) ) {
			return 'number';
		}

		if ( 'checkbox' === ( $field['type'] ?? '' ) ) {
			return 'boolean';
		}

		return 'string';
	}

	private static function sanitize_definition( array $raw, $slug ) {
		$supports = array_keys( self::supports() );
		$selected_supports = array_values( array_intersect( $supports, array_map( 'sanitize_key', (array) ( $raw['supports'] ?? [] ) ) ) );

		if ( empty( $selected_supports ) ) {
			$selected_supports = [ 'title', 'editor' ];
		}

		return [
			'slug'         => $slug,
			'singular'     => sanitize_text_field( $raw['singular'] ?? '' ),
			'plural'       => sanitize_text_field( $raw['plural'] ?? '' ),
			'description'  => sanitize_textarea_field( $raw['description'] ?? '' ),
			'menu_icon'    => sanitize_text_field( $raw['menu_icon'] ?? 'dashicons-screenoptions' ),
			'public'       => self::truthy( $raw['public'] ?? false ),
			'show_in_rest' => self::truthy( $raw['show_in_rest'] ?? false ),
			'has_archive'  => self::truthy( $raw['has_archive'] ?? false ),
			'hierarchical' => self::truthy( $raw['hierarchical'] ?? false ),
			'rewrite_slug' => sanitize_title( $raw['rewrite_slug'] ?? '' ),
			'supports'     => $selected_supports,
			'taxonomies'   => self::sanitize_taxonomies( $raw['taxonomies'] ?? [] ),
			'meta_fields'  => self::sanitize_meta_fields( $raw['meta_fields'] ?? [] ),
			'updated_at'   => current_time( 'mysql' ),
		];
	}

	private static function sanitize_taxonomies( $taxonomies ) {
		$taxonomies = is_array( $taxonomies ) ? array_slice( $taxonomies, 0, self::MAX_TAXONOMIES ) : [];
		$normalized = [];

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_array( $taxonomy ) ) {
				continue;
			}

			$slug = self::sanitize_taxonomy_slug( $taxonomy['slug'] ?? '' );

			if ( '' === $slug ) {
				continue;
			}

			$normalized[] = self::blank_taxonomy(
				[
					'slug'         => $slug,
					'singular'     => sanitize_text_field( $taxonomy['singular'] ?? '' ),
					'plural'       => sanitize_text_field( $taxonomy['plural'] ?? '' ),
					'hierarchical' => self::truthy( $taxonomy['hierarchical'] ?? false ),
					'public'       => self::truthy( $taxonomy['public'] ?? false ),
					'show_in_rest' => self::truthy( $taxonomy['show_in_rest'] ?? false ),
				]
			);
		}

		return $normalized;
	}

	private static function sanitize_meta_fields( $fields ) {
		$fields = is_array( $fields ) ? array_slice( $fields, 0, self::MAX_META_FIELDS ) : [];
		$types = array_keys( self::meta_field_types() );
		$normalized = [];

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key = sanitize_key( $field['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$type = sanitize_key( $field['type'] ?? 'text' );

			if ( ! in_array( $type, $types, true ) ) {
				$type = 'text';
			}

			$normalized[] = self::blank_meta_field(
				[
					'key'          => $key,
					'label'        => sanitize_text_field( $field['label'] ?? '' ),
					'type'         => $type,
					'default'      => self::sanitize_meta_value( $field['default'] ?? '', [ 'type' => $type ] ),
					'options'      => self::normalize_options_payload( $field ),
					'required'     => self::truthy( $field['required'] ?? false ),
					'show_in_rest' => self::truthy( $field['show_in_rest'] ?? false ),
				]
			);
		}

		return $normalized;
	}

	private static function sanitize_meta_value( $value, array $field ) {
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

		if ( 'date' === $type ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : '';
		}

		if ( 'checkbox' === $type ) {
			return self::truthy( $value ) ? '1' : '0';
		}

		if ( 'textarea' === $type ) {
			return sanitize_textarea_field( $value );
		}

		if ( 'color' === $type ) {
			return sanitize_hex_color( $value ) ?: '';
		}

		return sanitize_text_field( $value );
	}

	private static function parse_options( $raw ) {
		$options = [];
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			$value = sanitize_key( $parts[0] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			$options[] = [
				'value' => $value,
				'label' => sanitize_text_field( $parts[1] ?? $parts[0] ),
			];
		}

		return $options;
	}

	private static function normalize_options_payload( array $field ) {
		$options = self::compile_options_lines( $field['options_items'] ?? [], 100 );

		if ( '' !== $options ) {
			return $options;
		}

		return self::limit_lines( sanitize_textarea_field( $field['options'] ?? '' ), 100 );
	}

	private static function compile_options_lines( $items, $limit ) {
		$items = is_array( $items ) ? array_slice( $items, 0, max( 1, absint( $limit ) ) ) : [];
		$lines = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$value = sanitize_key( $item['value'] ?? '' );
			$label = sanitize_text_field( $item['label'] ?? '' );

			if ( '' === $value && '' !== $label ) {
				$value = sanitize_key( $label );
			}

			if ( '' === $value ) {
				continue;
			}

			$lines[] = $value . '|' . ( '' !== $label ? $label : $value );
		}

		return implode( "\n", $lines );
	}

	private static function sanitize_post_type_slug( $value ) {
		return substr( sanitize_key( $value ), 0, 20 );
	}

	private static function is_reserved_post_type_slug( $slug ) {
		return in_array(
			$slug,
			[
				'post',
				'page',
				'attachment',
				'revision',
				'nav_menu_item',
				'custom_css',
				'customize_changeset',
				'oembed_cache',
				'user_request',
				'wp_block',
				'wp_template',
				'wp_template_part',
				'wp_global_styles',
				'wp_navigation',
			],
			true
		);
	}

	private static function sanitize_taxonomy_slug( $value ) {
		return substr( sanitize_key( $value ), 0, 32 );
	}

	private static function truthy( $value ) {
		return in_array( $value, [ true, 1, '1', 'yes', 'on', 'true' ], true );
	}

	private static function limit_lines( $text, $limit ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$lines = array_slice( $lines, 0, max( 1, absint( $limit ) ) );

		return implode( "\n", $lines );
	}
}
