<?php
/**
 * Compiles Entity contracts for the built-in CPT and CCT runtimes.
 */

namespace EIT\Blueprint;

use EIT\CCT\SchemaManager;
use EIT\Contracts\StorageAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoreStorageAdapter implements StorageAdapterInterface {

	private $strategy;

	public function __construct( $strategy ) {
		if ( ! in_array( $strategy, [ 'cpt', 'cct' ], true ) ) {
			throw new \InvalidArgumentException( 'Core storage strategy must be CPT or CCT.' );
		}
		$this->strategy = $strategy;
	}

	public function get_id() {
		return $this->strategy;
	}

	public function get_version() {
		return '1.0.0';
	}

	public function get_capabilities() {
		return 'cpt' === $this->strategy
			? [ 'public_routes', 'editorial', 'revisions', 'structured_fields' ]
			: [ 'indexed_queries', 'high_volume', 'structured_fields' ];
	}

	public function compile( array $entity, array $fields, array $context = [] ) {
		return 'cpt' === $this->strategy
			? $this->compile_cpt( $entity, $fields, $context )
			: $this->compile_cct( $entity, $fields, $context );
	}

	public function prepare( array $artifact, array $context = [] ) {
		if ( 'cct' !== $this->strategy ) {
			return true;
		}
		$definition = $artifact['payload']['definition'] ?? null;
		return is_array( $definition )
			? SchemaManager::sync_definition( $definition )
			: new \WP_Error( 'eit_cct_artifact_invalid', __( 'Compiled CCT storage definition is invalid.', 'elementor-implementation-toolkit' ) );
	}

	public function health_check() {
		return [ 'ok' => true, 'version' => $this->get_version(), 'strategy' => $this->strategy ];
	}

	private function compile_cpt( array $entity, array $fields, array $context ) {
		$config = $entity['config'] ?? [];
		$slug = substr( sanitize_key( $config['slug'] ?? $context['slug'] ?? $entity['name'] ?? 'eit_item' ), 0, 20 );
		$mode = $config['mode'] ?? 'structured';
		$supports = $this->cpt_supports( $config, $mode );

		$meta_fields = [];
		$taxonomies = [];
		foreach ( $fields as $field ) {
			if ( 'taxonomy' === $field['type'] ) {
				$taxonomies[] = $this->taxonomy_definition( $field );
				continue;
			}
			if ( in_array( $field['type'], [ 'relation', 'repeatable_group' ], true ) ) {
				continue;
			}
			$meta_fields[] = [
				'field_id' => $field['id'],
				'key' => $field['storage']['key'],
				'label' => $field['name'],
				'type' => $this->legacy_type( $field['type'], 'cpt' ),
				'default' => $field['validation']['default'] ?? '',
				'options' => $this->options_text( $field ),
				'required' => ! empty( $field['validation']['required'] ),
				'show_in_rest' => ! empty( $field['exposure']['public'] ),
				'aliases' => array_values( $field['storage']['aliases'] ?? [] ),
			];
		}

		return [
			'strategy' => 'cpt',
			'definition' => [
				'slug' => $slug,
				'singular' => sanitize_text_field( $config['singular'] ?? $entity['name'] ),
				'plural' => sanitize_text_field( $config['plural'] ?? $entity['name'] ),
				'description' => sanitize_textarea_field( $config['description'] ?? '' ),
				'menu_icon' => sanitize_text_field( $config['menu_icon'] ?? 'dashicons-screenoptions' ),
				'public' => ! empty( $config['public'] ),
				'show_in_rest' => array_key_exists( 'show_in_rest', $config ) ? ! empty( $config['show_in_rest'] ) : true,
				'has_archive' => ! empty( $config['routed'] ),
				'hierarchical' => ! empty( $config['hierarchical'] ),
				'rewrite_slug' => sanitize_title( $config['route_slug'] ?? $slug ),
				'supports' => array_values( array_unique( $supports ) ),
				'taxonomies' => $taxonomies,
				'meta_fields' => $meta_fields,
				'blueprint_managed' => true,
				'entity_mode' => $mode,
				'blueprint_id' => $context['blueprint_id'] ?? '',
				'entity_id' => $entity['id'],
			],
		];
	}

	private function compile_cct( array $entity, array $fields, array $context ) {
		$config = $entity['config'] ?? [];
		$slug = substr( sanitize_key( $config['slug'] ?? $context['slug'] ?? $entity['name'] ?? 'content' ), 0, 32 );
		$compiled_fields = [];
		foreach ( $fields as $field ) {
			if ( in_array( $field['type'], [ 'relation', 'repeatable_group' ], true ) ) {
				continue;
			}
			$compiled_fields[] = [
				'field_id' => $field['id'],
				'key' => $field['storage']['key'],
				'label' => $field['name'],
				'type' => $this->legacy_type( $field['type'], 'cct' ),
				'default' => $field['validation']['default'] ?? '',
				'options' => $this->options_text( $field ),
				'required' => ! empty( $field['validation']['required'] ),
				'filterable' => ! empty( $field['indexing']['filter'] ),
				'sortable' => ! empty( $field['indexing']['sort'] ),
				'active' => true,
				'aliases' => array_values( $field['storage']['aliases'] ?? [] ),
			];
		}

		return [
			'strategy' => 'cct',
			'definition' => [
				'slug' => $slug,
				'singular' => sanitize_text_field( $config['singular'] ?? $entity['name'] ),
				'plural' => sanitize_text_field( $config['plural'] ?? $entity['name'] ),
				'description' => sanitize_textarea_field( $config['description'] ?? '' ),
				'menu_icon' => sanitize_text_field( $config['menu_icon'] ?? 'dashicons-database' ),
				'public' => ! empty( $config['public'] ),
				'state' => 'archived' === ( $config['state'] ?? '' ) ? 'archived' : 'active',
				'fields' => $compiled_fields,
				'blueprint_managed' => true,
				'blueprint_id' => $context['blueprint_id'] ?? '',
				'entity_id' => $entity['id'],
			],
		];
	}

	private function taxonomy_definition( array $field ) {
		$config = $field['taxonomy'] ?? [];
		return [
			'field_id' => $field['id'],
			'slug' => substr( sanitize_key( $config['slug'] ?? $field['storage']['key'] ), 0, 32 ),
			'singular' => sanitize_text_field( $config['singular'] ?? $field['name'] ),
			'plural' => sanitize_text_field( $config['plural'] ?? $field['name'] ),
			'hierarchical' => ! empty( $config['hierarchical'] ),
			'public' => array_key_exists( 'public', $config ) ? ! empty( $config['public'] ) : ! empty( $field['exposure']['public'] ),
			'show_in_rest' => array_key_exists( 'show_in_rest', $config ) ? ! empty( $config['show_in_rest'] ) : true,
		];
	}

	private function cpt_supports( array $config, $mode ) {
		$allowed = [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions', 'page-attributes' ];
		if ( array_key_exists( 'supports', $config ) && is_array( $config['supports'] ) ) {
			$supports = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $config['supports'] ) ) );
			return $supports ?: [ 'title' ];
		}
		$supports = [ 'title' ];
		if ( in_array( $mode, [ 'editorial', 'hybrid' ], true ) ) {
			$supports[] = 'editor';
		}
		if ( ! empty( $config['versioned'] ) ) {
			$supports[] = 'revisions';
		}
		return array_values( array_unique( $supports ) );
	}

	private function legacy_type( $type, $adapter ) {
		$shared = [
			'short_text' => 'text', 'long_text' => 'textarea', 'rich_text' => 'textarea',
			'integer' => 'number', 'decimal' => 'number', 'money' => 'number', 'percentage' => 'number', 'calculated' => 'number',
			'boolean' => 'cpt' === $adapter ? 'checkbox' : 'boolean', 'single_choice' => 'select', 'multiple_choice' => 'cpt' === $adapter ? 'select' : 'multiselect',
			'date' => 'date', 'time' => 'time', 'datetime' => 'datetime', 'image' => 'image', 'gallery' => 'gallery',
			'email' => 'email', 'phone' => 'text', 'url' => 'url', 'file' => 'image',
			'color' => 'color',
		];
		return $shared[ $type ] ?? ( 'cpt' === $adapter ? 'textarea' : 'textarea' );
	}

	private function options_text( array $field ) {
		$options = $field['validation']['options'] ?? [];
		$lines = [];
		foreach ( is_array( $options ) ? $options : [] as $option ) {
			$option = is_array( $option ) ? $option : [ 'value' => $option, 'label' => $option ];
			$lines[] = sanitize_key( $option['value'] ?? '' ) . '|' . sanitize_text_field( $option['label'] ?? $option['value'] ?? '' );
		}
		return implode( "\n", array_filter( $lines ) );
	}
}
