<?php
/**
 * Allocates opaque target storage when published Field semantics change.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DraftMigrationNormalizer {

	public function normalize( array $draft, array $active ) {
		$published = $this->field_index( $active );
		if ( ! is_array( $draft['nodes'] ?? null ) ) {
			return $draft;
		}
		foreach ( $draft['nodes'] as &$node ) {
			if ( 'field_group' !== ( $node['type'] ?? '' ) || ! is_array( $node['config']['fields'] ?? null ) ) {
				continue;
			}
			foreach ( $node['config']['fields'] as &$field ) {
				$field_id = (string) ( $field['id'] ?? '' );
				if ( ! isset( $published[ $field_id ] ) ) {
					continue;
				}
				$field = $this->normalize_field( $field, $published[ $field_id ] );
			}
			unset( $field );
		}
		unset( $node );
		return $draft;
	}

	private function normalize_field( array $draft, array $active ) {
		$source_key = sanitize_key( $active['storage']['key'] ?? '' );
		$target_key = sanitize_key( $draft['storage']['key'] ?? '' );
		$same_semantics = $this->semantics( $draft ) === $this->semantics( $active );
		$migration = is_array( $draft['storage']['migration'] ?? null ) ? $draft['storage']['migration'] : [];

		if ( $same_semantics && $source_key === $target_key ) {
			unset( $draft['storage']['migration'] );
			return $draft;
		}
		if ( $same_semantics && ! empty( $migration['generated'] ) && $source_key === sanitize_key( $migration['source_key'] ?? '' ) ) {
			$draft['storage']['key'] = $source_key;
			$draft['storage']['aliases'] = array_values( array_diff( (array) ( $draft['storage']['aliases'] ?? [] ), [ $source_key ] ) );
			unset( $draft['storage']['migration'] );
			return $draft;
		}
		if ( $same_semantics ) {
			return $draft;
		}

		if ( '' === $source_key || in_array( $draft['type'] ?? '', [ 'taxonomy', 'relation', 'repeatable_group', 'calculated' ], true ) || in_array( $active['type'] ?? '', [ 'taxonomy', 'relation', 'repeatable_group', 'calculated' ], true ) ) {
			return $draft;
		}
		if ( '' === $target_key || $source_key === $target_key ) {
			$target_key = $this->target_key( $draft, $source_key );
			$draft['storage']['key'] = $target_key;
		}
		$aliases = array_map( 'sanitize_key', (array) ( $draft['storage']['aliases'] ?? [] ) );
		$draft['storage']['aliases'] = array_values( array_unique( array_filter( array_merge( $aliases, [ $source_key ] ) ) ) );
		$draft['storage']['migration'] = [
			'generated' => true,
			'source_key' => $source_key,
			'source_type' => sanitize_key( $active['type'] ?? '' ),
			'target_type' => sanitize_key( $draft['type'] ?? '' ),
		];
		return $draft;
	}

	private function target_key( array $field, $source_key ) {
		$identity = implode( '|', [ (string) ( $field['id'] ?? '' ), $source_key, wp_json_encode( $this->semantics( $field ) ) ] );
		return 'eit_m_' . substr( hash( 'sha256', $identity ), 0, 24 );
	}

	private function semantics( array $field ) {
		return [ 'type' => sanitize_key( $field['type'] ?? '' ), 'shape' => sanitize_key( $field['shape'] ?? '' ) ];
	}

	private function field_index( array $document ) {
		$fields = [];
		foreach ( $document['nodes'] ?? [] as $node ) {
			if ( 'field_group' !== ( $node['type'] ?? '' ) ) {
				continue;
			}
			foreach ( $node['config']['fields'] ?? [] as $field ) {
				if ( ! empty( $field['id'] ) ) {
					$fields[ (string) $field['id'] ] = $field;
				}
			}
		}
		return $fields;
	}
}
