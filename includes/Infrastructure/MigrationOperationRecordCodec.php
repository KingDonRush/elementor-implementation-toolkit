<?php
/**
 * Canonicalizes and hydrates durable field-migration records.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationOperationRecordCodec {

	public static function normalize_operation( $operation ) {
		if ( ! is_array( $operation ) ) {
			return self::invalid_operation();
		}
		$operation = self::sort_recursive( $operation );
		$id = strtolower( (string) ( $operation['id'] ?? '' ) );
		$field_id = (string) ( $operation['field_id'] ?? '' );
		$adapter = sanitize_key( $operation['adapter'] ?? '' );
		$source = self::physical_identity_hash( $operation, 'source' );
		$target = self::physical_identity_hash( $operation, 'target' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $id ) || ! Uuid::is_valid( $field_id ) || ! in_array( $adapter, [ 'cpt', 'cct' ], true ) || '' === $source || '' === $target || hash_equals( $source, $target ) || true !== ( $operation['preserve_source'] ?? null ) ) {
			return self::invalid_operation();
		}
		$encoded = JsonCodec::encode( $operation );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		return [
			'id' => $id,
			'field_id' => $field_id,
			'adapter' => $adapter,
			'operation_checksum' => self::operation_checksum( $operation ),
			'source_identity_hash' => $source,
			'target_identity_hash' => $target,
			'operation' => $encoded,
		];
	}

	public static function normalize_proof( array $proof ) {
		$normalized = [];
		foreach ( [ 'source_count', 'transformed_count', 'target_count', 'rejected_count' ] as $key ) {
			$normalized[ $key ] = self::count_value( $proof[ $key ] ?? null );
			if ( null === $normalized[ $key ] ) {
				return new \WP_Error( 'eit_migration_proof_invalid', __( 'Migration proof counts are invalid.', 'elementor-implementation-toolkit' ) );
			}
		}
		foreach ( [ 'source_checksum', 'target_checksum' ] as $key ) {
			$normalized[ $key ] = strtolower( (string) ( $proof[ $key ] ?? '' ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $normalized[ $key ] ) ) {
				return new \WP_Error( 'eit_migration_proof_invalid', __( 'Migration proof checksums are invalid.', 'elementor-implementation-toolkit' ) );
			}
		}
		return $normalized;
	}

	public static function hydrate( array $row ) {
		$operation = JsonCodec::decode( $row['operation'] ?? null, [] );
		$normalized = self::normalize_operation( $operation );
		if ( is_wp_error( $normalized ) || ! self::columns_match( $row, $normalized ) ) {
			return self::integrity_error( $row );
		}
		$row['operation'] = $operation;
		if ( array_key_exists( 'cursor', $row ) ) {
			$row['cursor'] = JsonCodec::decode( $row['cursor'], null );
		}
		foreach ( [ 'state_revision', 'copied_count', 'rejected_count', 'attempts' ] as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		foreach ( [ 'source_count', 'transformed_count', 'target_count' ] as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$row[ $key ] = null === $row[ $key ] ? null : (int) $row[ $key ];
			}
		}
		return $row;
	}

	public static function hydrate_many( array $rows ) {
		$records = [];
		foreach ( $rows as $row ) {
			$record = is_array( $row ) ? self::hydrate( $row ) : self::integrity_error( [] );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			$records[] = $record;
		}
		return $records;
	}

	public static function operation_checksum( array $operation ) {
		return hash( 'sha256', wp_json_encode( self::sort_recursive( $operation ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function identity_hash( array $operation, $side ) {
		return self::physical_identity_hash( $operation, $side );
	}

	public static function count_value( $value ) {
		return ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= 0 ? (int) $value : null;
	}

	public static function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ self::class, 'sort_recursive' ], $value );
		}
		ksort( $value );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::sort_recursive( $child );
		}
		return $value;
	}

	private static function physical_identity_hash( array $operation, $side ) {
		$adapter = sanitize_key( $operation['adapter'] ?? '' );
		$strategy = sanitize_key( $operation['strategy'] ?? '' );
		$storage_slug = sanitize_key( $operation['storage_slug'] ?? '' );
		$key = sanitize_key( $operation[ $side ]['key'] ?? '' );
		if ( ! in_array( $adapter, [ 'cpt', 'cct' ], true ) || '' === $strategy || '' === $storage_slug || '' === $key ) {
			return '';
		}
		return hash( 'sha256', implode( '|', [ 'field_storage', $adapter, $strategy, $storage_slug, $key ] ) );
	}

	private static function columns_match( array $row, array $normalized ) {
		foreach ( [ 'id', 'field_id', 'adapter', 'operation_checksum', 'source_identity_hash', 'target_identity_hash' ] as $key ) {
			if ( (string) ( $row[ $key ] ?? '' ) !== (string) $normalized[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	private static function integrity_error( array $row ) {
		$id = (string) ( $row['id'] ?? '' );
		$data = preg_match( '/^[a-f0-9]{64}$/', $id ) ? [ 'operation_id' => $id ] : [];
		return new \WP_Error( 'eit_migration_record_integrity_failed', __( 'Migration operation storage is incomplete or divergent.', 'elementor-implementation-toolkit' ), $data );
	}

	private static function invalid_operation() {
		return new \WP_Error( 'eit_migration_operation_invalid', __( 'Migration operation plan is invalid or unsafe.', 'elementor-implementation-toolkit' ) );
	}
}
