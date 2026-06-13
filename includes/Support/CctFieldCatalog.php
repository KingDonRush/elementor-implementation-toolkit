<?php
/**
 * Elementor-facing catalog for CCT definitions and fields.
 */

namespace EIT\Support;

use EIT\CCT\DefinitionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctFieldCatalog {

	public static function type_options() {
		$options = [ '' => __( 'Select a content type', 'elementor-implementation-toolkit' ) ];

		foreach ( DefinitionManager::all( false ) as $slug => $definition ) {
			$options[ $slug ] = $definition['plural'] ?: ucfirst( $slug );
		}

		return $options;
	}

	public static function field_options( array $types = [] ) {
		$options = [
			'id'    => __( 'System / ID', 'elementor-implementation-toolkit' ),
			'title' => __( 'System / Title', 'elementor-implementation-toolkit' ),
		];

		foreach ( DefinitionManager::all( false ) as $slug => $definition ) {
			foreach ( $definition['fields'] ?? [] as $field ) {
				if ( empty( $field['active'] ) || ( $types && ! in_array( $field['type'], $types, true ) ) ) {
					continue;
				}

				$key = sanitize_key( $field['key'] ?? '' );
				if ( '' === $key ) {
					continue;
				}

				$options[ $key ] = sprintf(
					/* translators: 1: content type label, 2: field label. */
					__( '%1$s / %2$s', 'elementor-implementation-toolkit' ),
					$definition['singular'] ?: $slug,
					$field['label'] ?: $key
				);
			}
		}

		return $options;
	}

	public static function order_options() {
		$options = [
			'menu_order' => __( 'Manual order', 'elementor-implementation-toolkit' ),
			'title'      => __( 'Title', 'elementor-implementation-toolkit' ),
			'id'         => __( 'ID', 'elementor-implementation-toolkit' ),
			'created_at' => __( 'Created date', 'elementor-implementation-toolkit' ),
			'updated_at' => __( 'Updated date', 'elementor-implementation-toolkit' ),
		];

		foreach ( DefinitionManager::all( false ) as $definition ) {
			foreach ( $definition['fields'] ?? [] as $field ) {
				if ( empty( $field['active'] ) ) {
					continue;
				}
				$options[ $field['key'] ] = $field['label'] ?: $field['key'];
			}
		}

		return $options;
	}
}
