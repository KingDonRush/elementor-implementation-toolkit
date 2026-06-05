<?php
/**
 * Catalog of Toolkit-managed fields that can drive filter bindings.
 */

namespace EIT\Support;

use EIT\CPT\CptManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolkitFieldCatalog {

	const SOURCE_META = 'meta';
	const SOURCE_TAXONOMY = 'taxonomy';
	const SOURCE_POST_FIELD = 'post_field';

	public static function select_options() {
		$entries = self::entries();
		$options = [
			'' => __( 'Select a Toolkit field', 'elementor-implementation-toolkit' ),
		];

		foreach ( $entries as $entry ) {
			$options[ $entry['key'] ] = $entry['label'];
		}

		if ( 1 === count( $options ) ) {
			$options[''] = __( 'No public Toolkit CPT fields found', 'elementor-implementation-toolkit' );
		}

		return $options;
	}

	public static function entries() {
		$entries = [];

		foreach ( CptManager::all() as $slug => $definition ) {
			if ( ! self::is_public_definition( $definition ) ) {
				continue;
			}

			$post_type = sanitize_key( $definition['slug'] ?? $slug );
			$post_type_label = self::post_type_label( $definition, $post_type );

			foreach ( $definition['taxonomies'] ?? [] as $taxonomy ) {
				if ( ! self::is_public_taxonomy( $taxonomy ) ) {
					continue;
				}

				$key = sanitize_key( $taxonomy['slug'] ?? '' );

				if ( '' === $key ) {
					continue;
				}

				$entries[] = [
					'key'       => $key,
					'label'     => self::option_label( $post_type_label, __( 'Taxonomy', 'elementor-implementation-toolkit' ), $taxonomy['plural'] ?: $taxonomy['singular'] ?: $key, $key ),
					'source'    => self::SOURCE_TAXONOMY,
					'post_type' => $post_type,
				];
			}

			foreach ( $definition['meta_fields'] ?? [] as $field ) {
				if ( ! self::is_public_meta_field( $field ) ) {
					continue;
				}

				$key = sanitize_key( $field['key'] ?? '' );

				if ( '' === $key ) {
					continue;
				}

				$type = sanitize_key( $field['type'] ?? 'text' );

				$entries[] = [
					'key'       => $key,
					'label'     => self::option_label( $post_type_label, self::meta_type_label( $type ), $field['label'] ?: $key, $key ),
					'source'    => self::SOURCE_META,
					'type'      => $type,
					'post_type' => $post_type,
				];
			}
		}

		return self::merge_entries_by_key( $entries );
	}

	public static function public_meta_fields_for_post_type( $post_type ) {
		$definition = CptManager::get( $post_type );

		if ( ! $definition || ! self::is_public_definition( $definition ) ) {
			return [];
		}

		$fields = [];

		foreach ( $definition['meta_fields'] ?? [] as $field ) {
			if ( ! self::is_public_meta_field( $field ) ) {
				continue;
			}

			$key = sanitize_key( $field['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$fields[ $key ] = $field;
		}

		return $fields;
	}

	private static function merge_entries_by_key( array $entries ) {
		$merged = [];

		foreach ( $entries as $entry ) {
			$key = $entry['key'];

			if ( ! isset( $merged[ $key ] ) ) {
				$entry['post_types'] = [ $entry['post_type'] ];
				$merged[ $key ] = $entry;
				continue;
			}

			$merged[ $key ]['post_types'][] = $entry['post_type'];
			$merged[ $key ]['post_types'] = array_values( array_unique( $merged[ $key ]['post_types'] ) );
		}

		uasort(
			$merged,
			function ( $left, $right ) {
				return strcasecmp( $left['label'], $right['label'] );
			}
		);

		return $merged;
	}

	private static function is_public_definition( array $definition ) {
		return ! empty( $definition['public'] );
	}

	private static function is_public_taxonomy( array $taxonomy ) {
		return ! empty( $taxonomy['public'] ) && ! empty( $taxonomy['show_in_rest'] );
	}

	private static function is_public_meta_field( array $field ) {
		return ! empty( $field['show_in_rest'] );
	}

	private static function post_type_label( array $definition, $fallback ) {
		$label = $definition['singular'] ?: $definition['plural'] ?: $fallback;

		return sanitize_text_field( $label );
	}

	private static function meta_type_label( $type ) {
		$types = CptManager::meta_field_types();

		return $types[ $type ] ?? __( 'Meta', 'elementor-implementation-toolkit' );
	}

	private static function option_label( $post_type_label, $source_label, $field_label, $key ) {
		return sprintf(
			/* translators: 1: post type label, 2: field source/type, 3: field label, 4: field key. */
			__( '%1$s / %2$s / %3$s (%4$s)', 'elementor-implementation-toolkit' ),
			$post_type_label,
			$source_label,
			sanitize_text_field( $field_label ),
			$key
		);
	}
}
