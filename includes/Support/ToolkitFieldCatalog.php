<?php
/**
 * Catalog of Toolkit-managed fields that can drive filter bindings.
 */

namespace EIT\Support;

use EIT\CCT\DefinitionManager;
use EIT\CPT\CptManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolkitFieldCatalog {

	const SOURCE_META = 'meta';
	const SOURCE_TAXONOMY = 'taxonomy';
	const SOURCE_POST_FIELD = 'post_field';
	const SOURCE_CCT = 'cct';

	public static function select_options() {
		$entries = self::entries();
		$options = [
			'' => __( 'Select a Toolkit field', 'elementor-implementation-toolkit' ),
		];

		foreach ( $entries as $entry ) {
			$options[ $entry['key'] ] = $entry['label'];
		}

		if ( 1 === count( $options ) ) {
			$options[''] = __( 'No public Toolkit fields found', 'elementor-implementation-toolkit' );
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

		foreach ( DefinitionManager::all( false ) as $slug => $definition ) {
			if ( empty( $definition['public'] ) ) {
				continue;
			}

			$type_label = sanitize_text_field( $definition['singular'] ?: $definition['plural'] ?: $slug );
			foreach ( $definition['fields'] ?? [] as $field ) {
				if ( empty( $field['active'] ) || empty( $field['filterable'] ) ) {
					continue;
				}

				$key = sanitize_key( $field['key'] ?? '' );
				if ( '' === $key ) {
					continue;
				}

				$entries[] = [
					'key'       => $key,
					'label'     => self::option_label( $type_label, __( 'CCT', 'elementor-implementation-toolkit' ), $field['label'] ?: $key, $key ),
					'source'    => self::SOURCE_CCT,
					'type'      => sanitize_key( $field['type'] ?? 'text' ),
					'post_type' => 'cct:' . sanitize_key( $slug ),
				];
			}
		}

		$entries = apply_filters( 'eit_toolkit_field_catalog_entries', $entries );

		return self::merge_entries_by_key( self::normalize_entries( $entries ) );
	}

	public static function public_meta_fields_for_post_type( $post_type ) {
		$definition = CptManager::get( $post_type );

		$fields = [];

		if ( $definition && self::is_public_definition( $definition ) ) {
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
		}

		$fields = apply_filters( 'eit_toolkit_public_meta_fields_for_post_type', $fields, $post_type );

		return self::normalize_meta_fields( $fields );
	}

	private static function normalize_entries( $entries ) {
		$entries = is_array( $entries ) ? $entries : [];
		$normalized = [];
		$sources = [ self::SOURCE_META, self::SOURCE_TAXONOMY, self::SOURCE_POST_FIELD, self::SOURCE_CCT ];

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$key = sanitize_key( $entry['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$source = sanitize_key( $entry['source'] ?? self::SOURCE_META );

			$normalized[] = [
				'key'       => $key,
				'label'     => sanitize_text_field( $entry['label'] ?? $key ),
				'source'    => in_array( $source, $sources, true ) ? $source : self::SOURCE_META,
				'type'      => sanitize_key( $entry['type'] ?? 'text' ),
				'post_type' => sanitize_text_field( $entry['post_type'] ?? 'external' ),
			];
		}

		return $normalized;
	}

	private static function normalize_meta_fields( $fields ) {
		$fields = is_array( $fields ) ? $fields : [];
		$normalized = [];

		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key = sanitize_key( $field['key'] ?? $key );

			if ( '' === $key ) {
				continue;
			}

			$normalized[ $key ] = [
				'key'          => $key,
				'label'        => sanitize_text_field( $field['label'] ?? $key ),
				'type'         => sanitize_key( $field['type'] ?? 'text' ),
				'default'      => sanitize_text_field( $field['default'] ?? '' ),
				'show_in_rest' => ! empty( $field['show_in_rest'] ),
			];
		}

		return $normalized;
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
