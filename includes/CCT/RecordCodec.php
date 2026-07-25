<?php
/**
 * Hydrates CCT rows and normalizes their shared record semantics.
 */

namespace EIT\CCT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RecordCodec {

	public function hydrate( array $row, array $definition ) {
		$item = [
			'id' => absint( $row['id'] ?? 0 ),
			'title' => (string) ( $row['title'] ?? '' ),
			'status' => (string) ( $row['status'] ?? '' ),
			'author_id' => absint( $row['author_id'] ?? 0 ),
			'menu_order' => intval( $row['menu_order'] ?? 0 ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ),
		];
		foreach ( $definition['fields'] ?? [] as $field ) {
			$key = $field['key'];
			$item[ $key ] = FieldTypes::decode( $row[ SchemaManager::column_name( $key ) ] ?? null, $field );
		}
		return $item;
	}

	public function sanitize_status( $status ) {
		$status = sanitize_key( $status );
		return in_array( $status, [ 'publish', 'draft', 'review', 'archived' ], true ) ? $status : 'draft';
	}

	public function normalize_statuses( $statuses ) {
		$statuses = array_map( 'sanitize_key', is_array( $statuses ) ? $statuses : [ $statuses ] );
		$statuses = array_values( array_intersect( $statuses, [ 'publish', 'draft', 'review', 'archived' ] ) );
		return $statuses ?: [ 'publish' ];
	}

	public function missing_required_value( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! $this->missing_required_value( $item ) ) {
					return false;
				}
			}
			return true;
		}
		return null === $value || '' === trim( (string) $value );
	}
}
