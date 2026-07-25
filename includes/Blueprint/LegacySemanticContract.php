<?php
/**
 * Canonical migration contract shared by independent legacy and candidate probes.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacySemanticContract {

	public function from_definition( $strategy, array $definition ) {
		return 'cpt' === sanitize_key( $strategy )
			? $this->cpt_contract( $definition )
			: $this->cct_contract( $definition );
	}

	public function from_preset( array $preset ) {
		$filters = [];
		foreach ( $preset['filters'] ?? [] as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}
			$filters[] = [
				'type' => sanitize_key( $filter['type'] ?? '' ),
				'key' => sanitize_key( $filter['resolved_key'] ?? $filter['key'] ?? $filter['query_var'] ?? '' ),
				'enabled' => ! empty( $filter['enabled'] ),
			];
		}
		return $this->sort_recursive(
			[
				'name' => sanitize_text_field( $preset['name'] ?? '' ),
				'target_selector' => sanitize_text_field( $preset['target_selector'] ?? '' ),
				'item_selector' => sanitize_text_field( $preset['item_selector'] ?? '' ),
				'filters' => $filters,
			]
		);
	}

	public function checksum( array $contract ) {
		return hash( 'sha256', wp_json_encode( $this->sort_recursive( $contract ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private function cpt_contract( array $definition ) {
		$supports = array_values( array_unique( array_map( 'sanitize_key', (array) ( $definition['supports'] ?? [] ) ) ) );
		sort( $supports );
		$taxonomies = [];
		foreach ( $definition['taxonomies'] ?? [] as $taxonomy ) {
			if ( ! is_array( $taxonomy ) || '' === sanitize_key( $taxonomy['slug'] ?? '' ) ) {
				continue;
			}
			$taxonomies[] = [
				'slug' => sanitize_key( $taxonomy['slug'] ),
				'singular' => sanitize_text_field( $taxonomy['singular'] ?? '' ),
				'plural' => sanitize_text_field( $taxonomy['plural'] ?? '' ),
				'hierarchical' => ! empty( $taxonomy['hierarchical'] ),
				'public' => ! empty( $taxonomy['public'] ),
				'show_in_rest' => ! empty( $taxonomy['show_in_rest'] ),
			];
		}
		$fields = [];
		foreach ( $definition['meta_fields'] ?? [] as $field ) {
			if ( ! is_array( $field ) || '' === sanitize_key( $field['key'] ?? '' ) ) {
				continue;
			}
			$fields[] = $this->field_contract( $field, true );
		}
		$this->sort_by_key( $taxonomies, 'slug' );
		$this->sort_by_key( $fields, 'key' );

		return $this->sort_recursive(
			[
				'slug' => sanitize_key( $definition['slug'] ?? '' ),
				'singular' => sanitize_text_field( $definition['singular'] ?? '' ),
				'plural' => sanitize_text_field( $definition['plural'] ?? '' ),
				'description' => sanitize_textarea_field( $definition['description'] ?? '' ),
				'menu_icon' => sanitize_text_field( $definition['menu_icon'] ?? 'dashicons-screenoptions' ),
				'public' => ! empty( $definition['public'] ),
				'show_in_rest' => ! empty( $definition['show_in_rest'] ),
				'has_archive' => ! empty( $definition['has_archive'] ),
				'hierarchical' => ! empty( $definition['hierarchical'] ),
				'rewrite_slug' => sanitize_title( $definition['rewrite_slug'] ?? '' ),
				'supports' => $supports,
				'taxonomies' => $taxonomies,
				'fields' => $fields,
			]
		);
	}

	private function cct_contract( array $definition ) {
		$fields = [];
		foreach ( $definition['fields'] ?? [] as $field ) {
			if ( ! is_array( $field ) || '' === sanitize_key( $field['key'] ?? '' ) || empty( $field['active'] ) ) {
				continue;
			}
			$fields[] = $this->field_contract( $field, false );
		}
		$this->sort_by_key( $fields, 'key' );
		return $this->sort_recursive(
			[
				'slug' => sanitize_key( $definition['slug'] ?? '' ),
				'singular' => sanitize_text_field( $definition['singular'] ?? '' ),
				'plural' => sanitize_text_field( $definition['plural'] ?? '' ),
				'description' => sanitize_textarea_field( $definition['description'] ?? '' ),
				'menu_icon' => sanitize_text_field( $definition['menu_icon'] ?? 'dashicons-database' ),
				'public' => ! empty( $definition['public'] ),
				'state' => 'archived' === ( $definition['state'] ?? '' ) ? 'archived' : 'active',
				'fields' => $fields,
			]
		);
	}

	private function field_contract( array $field, $cpt ) {
		$contract = [
			'key' => sanitize_key( $field['key'] ?? '' ),
			'label' => sanitize_text_field( $field['label'] ?? '' ),
			'type' => sanitize_key( $field['type'] ?? 'text' ),
			'default' => $field['default'] ?? '',
			'options' => $this->options( $field['options'] ?? '' ),
			'required' => ! empty( $field['required'] ),
		];
		$contract[ $cpt ? 'show_in_rest' : 'filterable' ] = ! empty( $field[ $cpt ? 'show_in_rest' : 'filterable' ] );
		return $contract;
	}

	private function options( $raw ) {
		$options = [];
		foreach ( is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw ) as $option ) {
			if ( is_array( $option ) ) {
				$value = sanitize_key( $option['value'] ?? '' );
				$label = sanitize_text_field( $option['label'] ?? $value );
			} else {
				$parts = array_map( 'trim', explode( '|', (string) $option, 2 ) );
				$value = sanitize_key( $parts[0] ?? '' );
				$label = sanitize_text_field( $parts[1] ?? $value );
			}
			if ( '' !== $value ) {
				$options[] = [ 'value' => $value, 'label' => $label ?: $value ];
			}
		}
		return $options;
	}

	private function sort_by_key( array &$records, $key ) {
		usort( $records, fn( $left, $right ) => strcmp( (string) ( $left[ $key ] ?? '' ), (string) ( $right[ $key ] ?? '' ) ) );
	}

	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ $this, 'sort_recursive' ], $value );
		}
		ksort( $value );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->sort_recursive( $child );
		}
		return $value;
	}
}
