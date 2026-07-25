<?php
/**
 * Stable Field ID to adapter storage bindings with legacy aliases.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BindingStore {

	public function insert_many( $blueprint_id, $version_id, array $bindings ) {
		global $wpdb;

		foreach ( $bindings as $binding ) {
			$binding = $this->normalize_binding( $binding );
			if ( is_wp_error( $binding ) ) {
				return $binding;
			}
			$aliases = JsonCodec::encode( $binding['aliases'] );
			$payload = JsonCodec::encode( $binding );
			if ( is_wp_error( $aliases ) || is_wp_error( $payload ) ) {
				return is_wp_error( $aliases ) ? $aliases : $payload;
			}

			$existing = $this->get_checked( $version_id, $binding['field_id'] );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			if ( $existing ) {
				$existing_payload = JsonCodec::encode( $existing['payload'] ?? null );
				if (
					is_wp_error( $existing_payload )
					|| (string) $blueprint_id !== (string) ( $existing['blueprint_id'] ?? '' )
					|| (int) $version_id !== (int) ( $existing['version_id'] ?? 0 )
					|| ! hash_equals( $payload, $existing_payload )
				) {
					return new \WP_Error( 'eit_binding_identity_collision', __( 'Published Field ID collided with a different complete binding contract.', 'elementor-implementation-toolkit' ) );
				}
				continue;
			}

			$result = $wpdb->insert(
				Tables::name( Tables::BINDINGS ),
				[
					'blueprint_id' => (string) $blueprint_id,
					'version_id' => absint( $version_id ),
					'field_id' => $binding['field_id'],
					'adapter' => $binding['adapter'],
					'storage_key' => $binding['storage_key'],
					'aliases' => $aliases,
					'payload' => $payload,
					'created_at' => current_time( 'mysql', true ),
				]
			);
			if ( false === $result ) {
				return new \WP_Error( 'eit_binding_write_failed', __( 'Field binding could not be persisted.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] );
			}
		}
		return true;
	}

	public function get( $version_id, $field_id ) {
		$result = $this->read_one( $version_id, $field_id, false );
		return is_wp_error( $result ) ? null : $result;
	}

	public function get_checked( $version_id, $field_id ) {
		return $this->read_one( $version_id, $field_id, true );
	}

	public function for_version( $version_id ) {
		$result = $this->read_version( $version_id, false );
		return is_wp_error( $result ) ? [] : $result;
	}

	public function for_version_checked( $version_id ) {
		return $this->read_version( $version_id, true );
	}

	private function read_one( $version_id, $field_id, $strict ) {
		global $wpdb;

		$table = Tables::name( Tables::BINDINGS );
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE version_id = %d AND field_id = %s", absint( $version_id ), $field_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== $wpdb->last_error ) {
			return $this->read_error( $wpdb->last_error );
		}
		return $row ? $this->hydrate( $row, $strict ) : null;
	}

	private function read_version( $version_id, $strict ) {
		global $wpdb;

		$table = Tables::name( Tables::BINDINGS );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE version_id = %d ORDER BY field_id", absint( $version_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== $wpdb->last_error ) {
			return $this->read_error( $wpdb->last_error );
		}
		$bindings = [];
		foreach ( $rows ?: [] as $row ) {
			$binding = $this->hydrate( $row, $strict );
			if ( is_wp_error( $binding ) ) {
				return $binding;
			}
			$bindings[] = $binding;
		}
		return $bindings;
	}

	private function hydrate( array $row, $strict ) {
		$aliases = JsonCodec::decode( $row['aliases'] ?? null, $strict ? null : [] );
		$payload = JsonCodec::decode( $row['payload'] ?? null, $strict ? null : [] );
		if ( $strict && ( ! is_array( $aliases ) || ! is_array( $payload ) ) ) {
			return $this->invalid_runtime( 'json' );
		}
		$row['id'] = (int) ( $row['id'] ?? 0 );
		$row['version_id'] = (int) ( $row['version_id'] ?? 0 );
		$row['aliases'] = is_array( $aliases ) ? $this->normalize_aliases( $aliases ) : [];
		if ( ! is_array( $payload ) ) {
			$row['payload'] = [];
			return $row;
		}

		$normalized = $this->normalize_binding( $payload );
		if ( is_wp_error( $normalized ) ) {
			return $strict ? $this->invalid_runtime( 'payload' ) : $row;
		}
		if (
			$strict
			&& (
				(string) ( $row['field_id'] ?? '' ) !== $normalized['field_id']
				|| (string) ( $row['adapter'] ?? '' ) !== $normalized['adapter']
				|| (string) ( $row['storage_key'] ?? '' ) !== $normalized['storage_key']
				|| $row['aliases'] !== $normalized['aliases']
			)
		) {
			return $this->invalid_runtime( 'indexed_identity' );
		}
		$row = array_merge( $normalized, $row );
		$row['payload'] = $normalized;
		return $row;
	}

	private function normalize_binding( $binding ) {
		if ( ! is_array( $binding ) ) {
			return $this->invalid_binding();
		}
		foreach ( [ 'field_id', 'entity_id', 'adapter', 'storage_key', 'aliases', 'migration' ] as $key ) {
			if ( ! array_key_exists( $key, $binding ) ) {
				return $this->invalid_binding();
			}
		}
		foreach ( [ 'id', 'blueprint_id', 'version_id', 'created_at', 'payload' ] as $reserved ) {
			if ( array_key_exists( $reserved, $binding ) ) {
				return $this->invalid_binding();
			}
		}
		if (
			! is_scalar( $binding['field_id'] )
			|| ! is_scalar( $binding['entity_id'] )
			|| ! is_scalar( $binding['adapter'] )
			|| ! is_scalar( $binding['storage_key'] )
			|| '' === (string) $binding['field_id']
			|| '' === (string) $binding['entity_id']
			|| '' === (string) $binding['adapter']
			|| '' === (string) $binding['storage_key']
			|| (string) $binding['adapter'] !== sanitize_key( $binding['adapter'] )
			|| (string) $binding['storage_key'] !== sanitize_key( $binding['storage_key'] )
			|| ! is_array( $binding['aliases'] )
			|| ( null !== $binding['migration'] && ! is_array( $binding['migration'] ) )
		) {
			return $this->invalid_binding();
		}
		foreach ( $binding['aliases'] as $alias ) {
			if ( ! is_scalar( $alias ) ) {
				return $this->invalid_binding();
			}
		}
		$binding['field_id'] = (string) $binding['field_id'];
		$binding['entity_id'] = (string) $binding['entity_id'];
		$binding['adapter'] = (string) $binding['adapter'];
		$binding['storage_key'] = (string) $binding['storage_key'];
		$binding['aliases'] = $this->normalize_aliases( $binding['aliases'] );
		return $this->sort_recursive( $binding );
	}

	private function normalize_aliases( array $aliases ) {
		$aliases = array_values( array_unique( array_map( 'strval', $aliases ) ) );
		sort( $aliases, SORT_STRING );
		return $aliases;
	}

	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ $this, 'sort_recursive' ], $value );
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->sort_recursive( $child );
		}
		return $value;
	}

	private function read_error( $database_error ) {
		return new \WP_Error( 'eit_binding_runtime_read_failed', __( 'Published Field bindings could not be read safely.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $database_error ) ] );
	}

	private function invalid_binding() {
		return new \WP_Error( 'eit_binding_contract_invalid', __( 'Field binding is missing a complete stable storage contract.', 'elementor-implementation-toolkit' ) );
	}

	private function invalid_runtime( $reason ) {
		return new \WP_Error( 'eit_binding_runtime_record_invalid', __( 'Published Field binding authority is invalid.', 'elementor-implementation-toolkit' ), [ 'reason' => $reason ] );
	}
}
