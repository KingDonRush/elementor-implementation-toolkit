<?php
/**
 * Bounded, source-preserving migration driver for CCT columns.
 */

namespace EIT\Blueprint;

use EIT\CCT\SchemaManager as CctSchemaManager;
use EIT\Contracts\StorageMigrationDriverInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctFieldMigrationDriver implements StorageMigrationDriverInterface {
	use CctMigrationDatabaseAccess;
	use MigrationHeartbeatAware;

	const MAX_BATCH = 500;

	private $transformer;

	public function __construct( ?MigrationValueTransformer $transformer = null ) {
		$this->transformer = $transformer ?: new MigrationValueTransformer();
	}

	public function supports( array $operation ) {
		return ! is_wp_error( $this->normalize_operation( $operation ) );
	}

	public function assert_target_available( array $operation, bool $recovering ) {
		$operation = $this->normalize_operation( $operation );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}
		$state = $this->storage_state( $operation );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( isset( $state['columns'][ $state['target'] ] ) && ! $recovering ) {
			return $this->error( 'eit_migration_target_exists', __( 'Target CCT column must be absent before its first preparation.', 'elementor-implementation-toolkit' ), $operation );
		}
		if ( isset( $state['columns'][ $state['target'] ] ) ) {
			$valid = $this->assert_column_signature( $operation, 'target', $state['columns'][ $state['target'] ] );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		$nulls = $this->assert_source_nulls_compatible( $operation, $state );
		if ( is_wp_error( $nulls ) ) {
			return $nulls;
		}

		// A recovering operation is already protected by the migration ledger CAS.
		return true;
	}

	public function assert_target_ready( array $operation ) {
		$operation = $this->normalize_operation( $operation );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}
		$state = $this->ready_state( $operation );
		return is_wp_error( $state ) ? $state : true;
	}

	public function copy_batch( array $operation, int $cursor, int $limit ) {
		$operation = $this->normalize_operation( $operation );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}
		if ( $cursor < 0 || $limit < 1 || $limit > self::MAX_BATCH ) {
			return $this->error( 'eit_migration_batch_invalid', __( 'Migration batch cursor or limit is invalid.', 'elementor-implementation-toolkit' ), $operation );
		}
		$heartbeat = $this->pulse_heartbeat();
		if ( is_wp_error( $heartbeat ) ) {
			return $heartbeat;
		}
		$state = $this->ready_state( $operation );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$prefix = $this->validate_copied_prefix( $operation, $state, $cursor );
		if ( is_wp_error( $prefix ) ) {
			return $prefix;
		}

		$ids = $this->entity_ids( $state['table'], $cursor, $limit );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$rows = $this->row_values( $state['table'], $ids, [ $state['source'], $state['target'] ] );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$copied = 0;
		foreach ( $ids as $id ) {
			if ( ! isset( $rows[ $id ] ) ) {
				return $this->error( 'eit_migration_record_missing', __( 'A CCT record disappeared during migration.', 'elementor-implementation-toolkit' ), $operation, $id );
			}
			$source = $rows[ $id ][ $state['source'] ] ?? null;
			$target = $rows[ $id ][ $state['target'] ] ?? null;
			$transformed = $this->transformer->transform( $source, $operation['transform'] );
			if ( is_wp_error( $transformed ) ) {
				return $this->value_error( $transformed, $operation, $id );
			}
			$equivalent = $this->values_equivalent( $transformed, $target, $operation['target'] );
			if ( is_wp_error( $equivalent ) ) {
				return $this->value_error( $equivalent, $operation, $id );
			}
			if ( $equivalent ) {
				continue;
			}
			if ( ! $this->is_initial_target_value( $target, $operation['target'] ) ) {
				return $this->diverged( $operation, $id );
			}

			/*
			 * The ledger owns this newly prepared column. Rows above the durable cursor
			 * may still hold its schema default; rehearsals must freeze external writers.
			 */
			$written = $this->write_target_value( $state['table'], $state['target'], $id, $target, $transformed );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$stored = $this->row_values( $state['table'], [ $id ], [ $state['target'] ] );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			$actual = $stored[ $id ][ $state['target'] ] ?? null;
			$verified = $this->values_equivalent( $transformed, $actual, $operation['target'] );
			if ( is_wp_error( $verified ) || ! $verified ) {
				return is_wp_error( $verified ) ? $this->value_error( $verified, $operation, $id ) : $this->diverged( $operation, $id );
			}
			$copied += 0 < (int) $written ? 1 : 0;
		}

		return [
			'cursor' => $ids ? (int) end( $ids ) : $cursor,
			'processed' => count( $ids ),
			'copied' => $copied,
			'complete' => count( $ids ) < $limit,
		];
	}

	public function fingerprint( array $operation, string $side ) {
		$operation = $this->normalize_operation( $operation );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}
		if ( ! in_array( $side, [ 'source', 'transformed', 'target' ], true ) ) {
			return $this->error( 'eit_migration_fingerprint_side_invalid', __( 'Migration fingerprint side is invalid.', 'elementor-implementation-toolkit' ), $operation );
		}
		$state = 'target' === $side ? $this->ready_state( $operation ) : $this->storage_state( $operation );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$context = hash_init( 'sha256' );
		$counts = [ 'record_count' => 0, 'value_count' => 0, 'rejected_count' => 0 ];
		$cursor = 0;
		do {
			$heartbeat = $this->pulse_heartbeat();
			if ( is_wp_error( $heartbeat ) ) {
				return $heartbeat;
			}
			$ids = $this->entity_ids( $state['table'], $cursor, self::MAX_BATCH );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$column = 'target' === $side ? $state['target'] : $state['source'];
			$rows = $this->row_values( $state['table'], $ids, [ $column ] );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			foreach ( $ids as $id ) {
				$value = $rows[ $id ][ $column ] ?? null;
				$this->digest_record( $context, $counts, $operation, $side, $id, null !== $value, $value );
			}
			$cursor = $ids ? (int) end( $ids ) : $cursor;
		} while ( count( $ids ) === self::MAX_BATCH );

		return array_merge( $counts, [ 'checksum' => hash_final( $context ) ] );
	}

	private function normalize_operation( array $operation ) {
		$adapter = sanitize_key( $operation['adapter'] ?? '' );
		$strategy = sanitize_key( $operation['strategy'] ?? '' );
		$slug = (string) ( $operation['storage_slug'] ?? '' );
		$source = is_array( $operation['source'] ?? null ) ? $operation['source'] : [];
		$target = is_array( $operation['target'] ?? null ) ? $operation['target'] : [];
		$source_key = (string) ( $source['key'] ?? '' );
		$target_key = (string) ( $target['key'] ?? '' );
		if ( 'cct' !== $adapter || 'cct' !== $strategy ) {
			return new \WP_Error( 'eit_migration_driver_unsupported', __( 'CCT migration driver does not support this operation.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! $this->valid_slug( $slug ) || ! $this->valid_key( $source_key ) || ! $this->valid_key( $target_key ) || $source_key === $target_key ) {
			return new \WP_Error( 'eit_migration_identifier_invalid', __( 'CCT migration operation contains an invalid physical identifier.', 'elementor-implementation-toolkit' ) );
		}
		if ( CctSchemaManager::column_name( $source_key ) === CctSchemaManager::column_name( $target_key ) ) {
			return new \WP_Error( 'eit_migration_identifier_collision', __( 'CCT migration keys resolve to the same physical column.', 'elementor-implementation-toolkit' ) );
		}
		$source['type'] = sanitize_key( $source['type'] ?? '' );
		$source['shape'] = sanitize_key( $source['shape'] ?? '' );
		$target['type'] = sanitize_key( $target['type'] ?? '' );
		$target['shape'] = sanitize_key( $target['shape'] ?? '' );
		$contracts = [ 'source' => $source, 'target' => $target ];
		foreach ( $contracts as &$contract ) {
			$physical = CctPhysicalColumnContract::expected( $contract['key'], $contract );
			$declared = CctPhysicalColumnContract::normalize_expected( is_array( $contract['physical'] ?? null ) ? $contract['physical'] : [] );
			if ( is_wp_error( $physical ) || $physical !== $declared ) {
				return is_wp_error( $physical ) ? $physical : new \WP_Error( 'eit_migration_physical_signature_invalid', __( 'CCT migration physical signature does not match its compiled field semantics.', 'elementor-implementation-toolkit' ) );
			}
			$contract['physical'] = $physical;
		}
		unset( $contract );
		$source = $contracts['source'];
		$target = $contracts['target'];
		$expected = $this->transformer->strategy( $source, $target );
		if ( is_wp_error( $expected ) || $expected !== sanitize_key( $operation['transform'] ?? '' ) || empty( $operation['preserve_source'] ) ) {
			return is_wp_error( $expected ) ? $expected : new \WP_Error( 'eit_migration_transform_mismatch', __( 'CCT migration transform does not match its field semantics.', 'elementor-implementation-toolkit' ) );
		}
		$operation['adapter'] = $adapter;
		$operation['strategy'] = $strategy;
		$operation['storage_slug'] = $slug;
		$operation['source'] = array_merge( $source, [ 'key' => $source_key ] );
		$operation['target'] = array_merge( $target, [ 'key' => $target_key ] );
		$operation['transform'] = $expected;
		return $operation;
	}

	private function storage_state( array $operation ) {
		if ( ! $this->storage_exists( $operation['storage_slug'] ) ) {
			return $this->error( 'eit_migration_storage_missing', __( 'CCT migration storage does not exist.', 'elementor-implementation-toolkit' ), $operation );
		}
		$table = $this->table_name( $operation['storage_slug'] );
		if ( 1 !== preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return $this->error( 'eit_migration_identifier_invalid', __( 'CCT storage resolved to an invalid physical table.', 'elementor-implementation-toolkit' ), $operation );
		}
		$columns = $this->storage_columns( $table );
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}
		$source = CctSchemaManager::column_name( $operation['source']['key'] );
		$target = CctSchemaManager::column_name( $operation['target']['key'] );
		if ( ! isset( $columns[ $source ] ) ) {
			return $this->error( 'eit_migration_source_missing', __( 'CCT migration source column is missing.', 'elementor-implementation-toolkit' ), $operation );
		}
		$valid = $this->assert_column_signature( $operation, 'source', $columns[ $source ] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		return compact( 'table', 'columns', 'source', 'target' );
	}

	private function ready_state( array $operation ) {
		$state = $this->storage_state( $operation );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( ! isset( $state['columns'][ $state['target'] ] ) ) {
			return $this->error( 'eit_migration_target_not_ready', __( 'Prepared CCT target column is missing.', 'elementor-implementation-toolkit' ), $operation );
		}
		$valid = $this->assert_column_signature( $operation, 'target', $state['columns'][ $state['target'] ] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$nulls = $this->assert_source_nulls_compatible( $operation, $state );
		return is_wp_error( $nulls ) ? $nulls : $state;
	}

	private function assert_column_signature( array $operation, $side, array $column ) {
		$expected = $operation[ $side ]['physical'];
		$matches = CctPhysicalColumnContract::matches( $expected, $column );
		return true === $matches
			? true
			: $this->error( 'eit_migration_column_signature_mismatch', __( 'CCT migration column differs from its planned physical signature.', 'elementor-implementation-toolkit' ), $operation );
	}

	private function assert_source_nulls_compatible( array $operation, array $state ) {
		if ( ! empty( $operation['target']['physical']['nullable'] ) ) {
			return true;
		}
		$count = $this->source_null_count( $state['table'], $state['source'] );
		if ( is_wp_error( $count ) ) {
			return $count;
		}
		return 0 === (int) $count
			? true
			: $this->error( 'eit_migration_source_null_incompatible', __( 'CCT migration cannot copy NULL into a NOT NULL target column.', 'elementor-implementation-toolkit' ), $operation );
	}

	private function validate_copied_prefix( array $operation, array $state, $maximum ) {
		if ( 0 >= $maximum ) {
			return true;
		}
		$cursor = 0;
		do {
			$heartbeat = $this->pulse_heartbeat();
			if ( is_wp_error( $heartbeat ) ) {
				return $heartbeat;
			}
			$ids = $this->entity_ids( $state['table'], $cursor, self::MAX_BATCH, $maximum );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$rows = $this->row_values( $state['table'], $ids, [ $state['source'], $state['target'] ] );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			foreach ( $ids as $id ) {
				if ( ! isset( $rows[ $id ] ) ) {
					return $this->error( 'eit_migration_record_missing', __( 'A CCT record disappeared during migration.', 'elementor-implementation-toolkit' ), $operation, $id );
				}
				$source = $rows[ $id ][ $state['source'] ] ?? null;
				$target = $rows[ $id ][ $state['target'] ] ?? null;
				$transformed = $this->transformer->transform( $source, $operation['transform'] );
				if ( is_wp_error( $transformed ) ) {
					return $this->value_error( $transformed, $operation, $id );
				}
				$equivalent = $this->values_equivalent( $transformed, $target, $operation['target'] );
				if ( is_wp_error( $equivalent ) || ! $equivalent ) {
					return is_wp_error( $equivalent ) ? $this->value_error( $equivalent, $operation, $id ) : $this->diverged( $operation, $id );
				}
			}
			$cursor = $ids ? (int) end( $ids ) : $cursor;
		} while ( $ids && count( $ids ) === self::MAX_BATCH && $cursor < $maximum );
		return true;
	}

	private function values_equivalent( $expected, $actual, array $semantics ) {
		$expected = $this->transformer->canonical( $expected, $semantics );
		$actual = $this->transformer->canonical( $actual, $semantics );
		if ( is_wp_error( $expected ) || is_wp_error( $actual ) ) {
			return is_wp_error( $expected ) ? $expected : $actual;
		}
		return $expected === $actual;
	}

	private function is_initial_target_value( $value, array $semantics ) {
		return null === $value || ( 'boolean' === sanitize_key( $semantics['type'] ?? '' ) && in_array( $value, [ 0, '0', false ], true ) );
	}

	private function digest_record( $context, array &$counts, array $operation, $side, $id, $exists, $value ) {
		++$counts['record_count'];
		$counts['value_count'] += $exists ? 1 : 0;
		$canonical = null;
		if ( $exists ) {
			if ( 'transformed' === $side ) {
				$value = $this->transformer->transform( $value, $operation['transform'] );
			}
			$semantics = 'source' === $side ? $operation['source'] : $operation['target'];
			$canonical = is_wp_error( $value ) ? $value : $this->transformer->canonical( $value, $semantics );
			if ( is_wp_error( $canonical ) ) {
				++$counts['rejected_count'];
				$canonical = [ 'rejected' => $canonical->get_error_code() ];
			}
		}
		hash_update( $context, wp_json_encode( [ (int) $id, (bool) $exists, $canonical ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	}

	private function valid_slug( $value ) {
		return $value === sanitize_key( $value ) && strlen( $value ) <= 32 && 1 === preg_match( '/^[a-z0-9_]+$/', $value );
	}

	private function valid_key( $value ) {
		return $value === sanitize_key( $value ) && 1 === preg_match( '/^[a-z_][a-z0-9_-]{0,190}$/', $value );
	}

	private function diverged( array $operation, $id ) {
		return $this->error( 'eit_migration_target_diverged', __( 'CCT migration target differs from the transformed source.', 'elementor-implementation-toolkit' ), $operation, $id );
	}

	private function value_error( \WP_Error $error, array $operation, $id = 0 ) {
		$error->add_data( [ 'operation_id' => (string) ( $operation['id'] ?? '' ), 'record_id' => absint( $id ) ] );
		return $error;
	}

	private function error( $code, $message, array $operation = [], $id = 0 ) {
		return new \WP_Error( $code, $message, [ 'operation_id' => (string) ( $operation['id'] ?? '' ), 'record_id' => absint( $id ) ] );
	}

}
