<?php
/**
 * WordPress runtime adapter for Toolkit-managed custom post types.
 */

namespace EIT\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CptManager {

	const OPTION = DefinitionManager::OPTION;

	private $updating_status = false;
	private $rest_required_hooks = [];

	public function init_hooks() {
		add_action( 'init', [ $this, 'register_definitions' ], 9 );
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_meta_box' ] );
		add_action( 'admin_notices', [ $this, 'render_required_notice' ] );
	}

	public static function all() { return DefinitionManager::all(); }
	public static function get( $slug ) { return DefinitionManager::get( $slug ); }
	public static function blank() { return DefinitionManager::blank(); }
	public static function blank_taxonomy( array $overrides = [] ) { return DefinitionManager::blank_taxonomy( $overrides ); }
	public static function blank_meta_field( array $overrides = [] ) { return DefinitionManager::blank_meta_field( $overrides ); }
	public static function save_definition( array $raw ) { return DefinitionManager::save( $raw ); }
	public static function delete_definition( $slug ) { return DefinitionManager::delete( $slug ); }
	public static function supports() { return DefinitionManager::supports(); }
	public static function meta_field_types() { return DefinitionManager::meta_field_types(); }

	public function register_definitions() {
		foreach ( DefinitionManager::all() as $slug => $definition ) {
			$this->register_post_type( $slug, $definition );
			$this->register_taxonomies( $slug, $definition['taxonomies'] ?? [] );
			$this->register_meta_fields( $slug, $definition['meta_fields'] ?? [] );
			$this->register_rest_required_hook( $slug );
		}
	}

	public function register_meta_boxes() {
		foreach ( DefinitionManager::all() as $slug => $definition ) {
			if ( empty( $definition['blueprint_managed'] ) && ! empty( $definition['meta_fields'] ) ) {
				add_meta_box( 'eit-managed-fields', __( 'Implementation Toolkit Fields', 'elementor-implementation-toolkit' ), [ $this, 'render_meta_box' ], $slug, 'normal', 'default' );
			}
		}
	}

	public function render_meta_box( $post ) {
		$definition = DefinitionManager::get( $post->post_type );
		if ( ! $definition || empty( $definition['meta_fields'] ) ) {
			return;
		}

		wp_nonce_field( 'eit_save_meta_box_' . $post->ID, 'eit_meta_box_nonce' );
		echo '<div class="eit-managed-fields">';
		foreach ( $definition['meta_fields'] as $field ) {
			$value = get_post_meta( $post->ID, $field['key'], true );
			if ( '' === $value && '' !== (string) ( $field['default'] ?? '' ) ) {
				$value = $field['default'];
			}
			$this->render_meta_field_input( $field, $value );
		}
		echo '</div>';
	}

	public function save_meta_box( $post_id ) {
		if ( $this->updating_status || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! isset( $_POST['eit_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eit_meta_box_nonce'] ) ), 'eit_save_meta_box_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$definition = DefinitionManager::get( get_post_type( $post_id ) );
		if ( ! $definition || empty( $definition['meta_fields'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every submitted value is sanitized by its published field contract below.
		$submitted = isset( $_POST['eit_meta'] ) && is_array( $_POST['eit_meta'] ) ? wp_unslash( $_POST['eit_meta'] ) : [];
		$missing = [];
		foreach ( $definition['meta_fields'] as $field ) {
			$key = $field['key'];
			$value = 'checkbox' === $field['type'] ? ( array_key_exists( $key, $submitted ) ? '1' : '0' ) : DefinitionManager::sanitize_meta_value( $submitted[ $key ] ?? null, $field );
			if ( ! empty( $field['required'] ) && $this->is_missing( $value ) ) {
				$missing[] = $field['label'] ?: $key;
			}
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		if ( $missing ) {
			set_transient( $this->notice_key( $post_id ), $missing, 300 );
			if ( 'publish' === get_post_status( $post_id ) ) {
				$this->updating_status = true;
				wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
				$this->updating_status = false;
			}
		}
	}

	public function render_required_notice() {
		$post_id = absint( $_GET['post'] ?? 0 );
		if ( ! $post_id ) {
			return;
		}
		$missing = get_transient( $this->notice_key( $post_id ) );
		if ( ! is_array( $missing ) || ! $missing ) {
			return;
		}
		delete_transient( $this->notice_key( $post_id ) );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sprintf( __( 'The item remains a draft. Complete required fields: %s.', 'elementor-implementation-toolkit' ), implode( ', ', $missing ) ) ) );
	}

	private function register_post_type( $slug, array $definition ) {
		$singular = $definition['singular'] ?: ucfirst( str_replace( '_', ' ', $slug ) );
		$plural = $definition['plural'] ?: $singular . 's';
		register_post_type( $slug, [
			'labels' => [ 'name' => $plural, 'singular_name' => $singular, 'add_new_item' => sprintf( __( 'Add New %s', 'elementor-implementation-toolkit' ), $singular ), 'edit_item' => sprintf( __( 'Edit %s', 'elementor-implementation-toolkit' ), $singular ), 'new_item' => sprintf( __( 'New %s', 'elementor-implementation-toolkit' ), $singular ), 'view_item' => sprintf( __( 'View %s', 'elementor-implementation-toolkit' ), $singular ), 'search_items' => sprintf( __( 'Search %s', 'elementor-implementation-toolkit' ), $plural ), 'not_found' => sprintf( __( 'No %s found', 'elementor-implementation-toolkit' ), strtolower( $plural ) ), 'not_found_in_trash' => sprintf( __( 'No %s found in Trash', 'elementor-implementation-toolkit' ), strtolower( $plural ) ) ],
			'description' => $definition['description'] ?? '', 'public' => ! empty( $definition['public'] ), 'show_ui' => true, 'show_in_menu' => true,
			'show_in_rest' => ! empty( $definition['show_in_rest'] ), 'has_archive' => ! empty( $definition['has_archive'] ), 'hierarchical' => ! empty( $definition['hierarchical'] ),
			'menu_icon' => $definition['menu_icon'] ?: 'dashicons-screenoptions', 'rewrite' => [ 'slug' => $definition['rewrite_slug'] ?: $slug ], 'supports' => $definition['supports'] ?? [ 'title' ],
		] );
	}

	private function register_taxonomies( $post_type, array $taxonomies ) {
		foreach ( $taxonomies as $taxonomy ) {
			$slug = DefinitionManager::sanitize_taxonomy_slug( $taxonomy['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$singular = $taxonomy['singular'] ?: ucfirst( str_replace( '_', ' ', $slug ) );
			$plural = $taxonomy['plural'] ?: $singular . 's';
			register_taxonomy( $slug, [ $post_type ], [ 'labels' => [ 'name' => $plural, 'singular_name' => $singular, 'search_items' => sprintf( __( 'Search %s', 'elementor-implementation-toolkit' ), $plural ), 'all_items' => sprintf( __( 'All %s', 'elementor-implementation-toolkit' ), $plural ), 'edit_item' => sprintf( __( 'Edit %s', 'elementor-implementation-toolkit' ), $singular ), 'add_new_item' => sprintf( __( 'Add New %s', 'elementor-implementation-toolkit' ), $singular ) ], 'public' => ! empty( $taxonomy['public'] ), 'hierarchical' => ! empty( $taxonomy['hierarchical'] ), 'show_ui' => true, 'show_admin_column' => true, 'show_in_rest' => ! empty( $taxonomy['show_in_rest'] ), 'rewrite' => [ 'slug' => $slug ] ] );
		}
	}

	private function register_meta_fields( $post_type, array $fields ) {
		foreach ( $fields as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			register_post_meta( $post_type, $key, [ 'type' => $this->rest_type( $field ), 'single' => true, 'show_in_rest' => ! empty( $field['show_in_rest'] ), 'sanitize_callback' => function ( $value ) use ( $field ) { return DefinitionManager::sanitize_meta_value( $value, $field ); }, 'auth_callback' => function () { return current_user_can( 'edit_posts' ); } ] );
		}
	}

	private function register_rest_required_hook( $post_type ) {
		if ( isset( $this->rest_required_hooks[ $post_type ] ) ) {
			return;
		}

		add_filter(
			'rest_pre_insert_' . $post_type,
			function ( $prepared_post, $request ) use ( $post_type ) {
				return $this->validate_rest_required_fields( $post_type, $prepared_post, $request );
			},
			10,
			2
		);
		$this->rest_required_hooks[ $post_type ] = true;
	}

	private function validate_rest_required_fields( $post_type, $prepared_post, $request ) {
		if ( ! is_object( $prepared_post ) || 'publish' !== ( $prepared_post->post_status ?? '' ) ) {
			return $prepared_post;
		}

		$definition = DefinitionManager::get( $post_type );
		if ( ! $definition ) {
			return $prepared_post;
		}

		$submitted = $request instanceof \WP_REST_Request ? $request->get_param( 'meta' ) : [];
		$submitted = is_array( $submitted ) ? $submitted : [];
		$request_id = $request instanceof \WP_REST_Request ? $request->get_param( 'id' ) : 0;
		$post_id = absint( $prepared_post->ID ?? $request_id );
		$missing = [];

		foreach ( $definition['meta_fields'] ?? [] as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}

			$key = sanitize_key( $field['key'] ?? '' );
			if ( array_key_exists( $key, $submitted ) ) {
				$value = DefinitionManager::sanitize_meta_value( $submitted[ $key ], $field );
			} elseif ( $post_id && metadata_exists( 'post', $post_id, $key ) ) {
				$value = DefinitionManager::sanitize_meta_value( get_post_meta( $post_id, $key, true ), $field );
			} else {
				$value = null;
			}

			if ( $this->is_missing( $value ) ) {
				$missing[ $key ] = $field['label'] ?: $key;
			}
		}

		if ( ! $missing ) {
			return $prepared_post;
		}

		return new \WP_Error(
			'eit_cpt_required_fields',
			sprintf(
				__( 'Complete required fields before publishing: %s.', 'elementor-implementation-toolkit' ),
				implode( ', ', array_values( $missing ) )
			),
			[
				'status'         => 400,
				'missing_fields' => array_keys( $missing ),
			]
		);
	}

	private function render_meta_field_input( array $field, $value ) {
		$key = $field['key'];
		$id = 'eit-meta-' . $key;
		$name = 'eit_meta[' . $key . ']';
		$is_required = ! empty( $field['required'] );
		$is_media = in_array( $field['type'], [ 'image', 'gallery' ], true );
		echo '<p class="eit-managed-field eit-managed-field--' . esc_attr( $field['type'] ) . '"';
		if ( $is_media ) {
			echo ' data-eit-media-field data-eit-media-multiple="' . esc_attr( 'gallery' === $field['type'] ? '1' : '0' ) . '"';
		}
		echo '><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $field['label'] ?: $key ) . '</strong></label>';
		if ( in_array( $field['type'], [ 'image', 'gallery' ], true ) ) {
			echo '<span class="eit-media-control"><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="widefat" type="text" value="' . esc_attr( $value ) . '"';
			$this->render_required_attributes( $is_required );
			echo ' /><button type="button" class="button" data-eit-select-media>' . esc_html__( 'Select media', 'elementor-implementation-toolkit' ) . '</button><button type="button" class="button-link" data-eit-clear-media>' . esc_html__( 'Clear', 'elementor-implementation-toolkit' ) . '</button></span>';
		} elseif ( 'textarea' === $field['type'] ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="widefat" rows="4"';
			$this->render_required_attributes( $is_required );
			echo '>' . esc_textarea( $value ) . '</textarea>';
		} elseif ( 'select' === $field['type'] ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="widefat"';
			$this->render_required_attributes( $is_required );
			echo '>';
			foreach ( DefinitionManager::parse_options( $field['options'] ?? '' ) as $option ) { echo '<option value="' . esc_attr( $option['value'] ) . '" ' . selected( (string) $value, (string) $option['value'], false ) . '>' . esc_html( $option['label'] ) . '</option>'; }
			echo '</select>';
		} elseif ( 'radio' === $field['type'] ) {
			foreach ( DefinitionManager::parse_options( $field['options'] ?? '' ) as $option ) {
				echo '<label class="eit-checkbox-inline"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option['value'] ) . '" ' . checked( (string) $value, (string) $option['value'], false );
				$this->render_required_attributes( $is_required );
				echo ' /> ' . esc_html( $option['label'] ) . '</label><br />';
			}
		} elseif ( 'checkbox' === $field['type'] ) {
			echo '<label class="eit-checkbox-inline"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( (string) $value, '1', false ) . ' /> ' . esc_html__( 'Enabled', 'elementor-implementation-toolkit' ) . '</label>';
		} else {
			$type = in_array( $field['type'], [ 'number', 'url', 'email', 'date', 'time', 'color' ], true ) ? $field['type'] : ( 'datetime' === $field['type'] ? 'datetime-local' : 'text' );
			echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="widefat" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"';
			$this->render_required_attributes( $is_required );
			echo ' />';
		}
		echo '</p>';
	}

	private function render_required_attributes( $is_required ) {
		if ( $is_required ) {
			echo ' required="required" aria-required="true"';
		}
	}

	private function rest_type( array $field ) {
		return 'number' === ( $field['type'] ?? '' ) ? 'number' : ( 'checkbox' === ( $field['type'] ?? '' ) ? 'boolean' : 'string' );
	}

	private function is_missing( $value ) {
		return null === $value || '' === trim( (string) $value );
	}

	private function notice_key( $post_id ) {
		return 'eit_cpt_required_' . absint( get_current_user_id() ) . '_' . absint( $post_id );
	}
}
