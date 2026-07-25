<?php
/**
 * Bounded, source-preserving migration driver for CPT post meta.
 */

namespace EIT\Blueprint;

use EIT\Contracts\StorageMigrationDriverInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CptFieldMigrationDriver implements StorageMigrationDriverInterface {
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

		$cursor = 0;
		do {
			$heartbeat = $this->pulse_heartbeat();
			if ( is_wp_error( $heartbeat ) ) {
				return $heartbeat;
			}
			$ids = $this->entity_ids( $operation['storage_slug'], $cursor, self::MAX_BATCH );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$source = $this->meta_values( $ids, $operation['source']['key'] );
			$target = $this->meta_values( $ids, $operation['target']['key'] );
			if ( is_wp_error( $source ) || is_wp_error( $target ) ) {
				return is_wp_error( $source ) ? $source : $target;
			}
			foreach ( $ids as $id ) {
				$checked = $this->assert_single_values( $operation, $id, $source[ $id ] ?? [], $target[ $id ] ?? [] );
				if ( is_wp_error( $checked ) ) {
					return $checked;
				}
				if ( empty( $target[ $id ] ) ) {
					continue;
				}
				if ( ! $recovering ) {
					return $this->error( 'eit_migration_target_exists', __( 'Target post meta must be absent before its first preparation.', 'elementor-implementation-toolkit' ), $operation, $id );
				}
				$equivalent = $this->equivalent( $source[ $id ][0] ?? null, ! empty( $source[ $id ] ), $target[ $id ][0], $operation, $id );
				if ( is_wp_error( $equivalent ) || ! $equivalent ) {
					return is_wp_error( $equivalent ) ? $equivalent : $this->diverged( $operation, $id );
				}
			}
			$cursor = $ids ? (int) end( $ids ) : $cursor;
		} while ( count( $ids ) === self::MAX_BATCH );

		return true;
	}

	public function assert_target_ready( array $operation ) {
		return $this->assert_target_available( $operation, true );
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

		$ids = $this->entity_ids( $operation['storage_slug'], $cursor, $limit );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$source = $this->meta_values( $ids, $operation['source']['key'] );
		$target = $this->meta_values( $ids, $operation['target']['key'] );
		if ( is_wp_error( $source ) || is_wp_error( $target ) ) {
			return is_wp_error( $source ) ? $source : $target;
		}
		$copied = 0;
		foreach ( $ids as $id ) {
			$source_values = $source[ $id ] ?? [];
			$target_values = $target[ $id ] ?? [];
			$checked = $this->assert_single_values( $operation, $id, $source_values, $target_values );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			if ( ! $source_values ) {
				if ( $target_values ) {
					return $this->diverged( $operation, $id );
				}
				continue;
			}

			$transformed = $this->transformer->transform( $source_values[0], $operation['transform'] );
			if ( is_wp_error( $transformed ) ) {
				return $this->value_error( $transformed, $operation, $id );
			}
			if ( $target_values ) {
				$equivalent = $this->equivalent( $source_values[0], true, $target_values[0], $operation, $id );
				if ( is_wp_error( $equivalent ) || ! $equivalent ) {
					return is_wp_error( $equivalent ) ? $equivalent : $this->diverged( $operation, $id );
				}
				$this->invalidate_meta_cache( $id );
				continue;
			}

			$inserted = $this->insert_target_value( $id, $operation['target']['key'], $transformed );
			if ( is_wp_error( $inserted ) ) {
				return $inserted;
			}
			$this->invalidate_meta_cache( $id );
			$stored = $this->meta_values( [ $id ], $operation['target']['key'] );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			$stored = $stored[ $id ] ?? [];
			if ( 1 !== count( $stored ) ) {
				return $this->error( 'eit_migration_target_write_failed', __( 'Target post meta could not be written exactly once.', 'elementor-implementation-toolkit' ), $operation, $id );
			}
			$equivalent = $this->equivalent( $source_values[0], true, $stored[0], $operation, $id );
			if ( is_wp_error( $equivalent ) || ! $equivalent ) {
				return is_wp_error( $equivalent ) ? $equivalent : $this->diverged( $operation, $id );
			}
			$copied += 0 < (int) $inserted ? 1 : 0;
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

		$context = hash_init( 'sha256' );
		$counts = [ 'record_count' => 0, 'value_count' => 0, 'rejected_count' => 0 ];
		$cursor = 0;
		do {
			$heartbeat = $this->pulse_heartbeat();
			if ( is_wp_error( $heartbeat ) ) {
				return $heartbeat;
			}
			$ids = $this->entity_ids( $operation['storage_slug'], $cursor, self::MAX_BATCH );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$key = 'target' === $side ? $operation['target']['key'] : $operation['source']['key'];
			$values = $this->meta_values( $ids, $key );
			if ( is_wp_error( $values ) ) {
				return $values;
			}
			foreach ( $ids as $id ) {
				$record_values = $values[ $id ] ?? [];
				if ( 1 < count( $record_values ) ) {
					return $this->duplicate( $operation, $id );
				}
				$exists = 1 === count( $record_values );
				$value = $exists ? $record_values[0] : null;
				$this->digest_record( $context, $counts, $operation, $side, $id, $exists, $value );
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
		if ( 'cpt' !== $adapter || 'cpt' !== $strategy ) {
			return new \WP_Error( 'eit_migration_driver_unsupported', __( 'CPT migration driver does not support this operation.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! $this->valid_slug( $slug ) || ! $this->valid_key( $source_key ) || ! $this->valid_key( $target_key ) || $source_key === $target_key ) {
			return new \WP_Error( 'eit_migration_identifier_invalid', __( 'CPT migration operation contains an invalid physical identifier.', 'elementor-implementation-toolkit' ) );
		}
		$source['type'] = sanitize_key( $source['type'] ?? '' );
		$source['shape'] = sanitize_key( $source['shape'] ?? '' );
		$target['type'] = sanitize_key( $target['type'] ?? '' );
		$target['shape'] = sanitize_key( $target['shape'] ?? '' );
		$expected = $this->transformer->strategy( $source, $target );
		if ( is_wp_error( $expected ) || $expected !== sanitize_key( $operation['transform'] ?? '' ) || empty( $operation['preserve_source'] ) ) {
			return is_wp_error( $expected ) ? $expected : new \WP_Error( 'eit_migration_transform_mismatch', __( 'CPT migration transform does not match its field semantics.', 'elementor-implementation-toolkit' ) );
		}
		$operation['adapter'] = $adapter;
		$operation['strategy'] = $strategy;
		$operation['storage_slug'] = $slug;
		$operation['source'] = array_merge( $source, [ 'key' => $source_key ] );
		$operation['target'] = array_merge( $target, [ 'key' => $target_key ] );
		$operation['transform'] = $expected;
		return $operation;
	}

	private function valid_slug( $value ) {
		return $value === sanitize_key( $value ) && strlen( $value ) <= 20 && 1 === preg_match( '/^[a-z0-9_-]+$/', $value );
	}

	private function valid_key( $value ) {
		return $value === sanitize_key( $value ) && 1 === preg_match( '/^[a-z_][a-z0-9_-]{0,190}$/', $value );
	}

	private function assert_single_values( array $operation, $id, array $source, array $target ) {
		return 1 < count( $source ) || 1 < count( $target ) ? $this->duplicate( $operation, $id ) : true;
	}

	private function equivalent( $source, $source_exists, $target, array $operation, $id = 0 ) {
		if ( ! $source_exists ) {
			return false;
		}
		$transformed = $this->transformer->transform( $source, $operation['transform'] );
		if ( is_wp_error( $transformed ) ) {
			return $this->value_error( $transformed, $operation, $id );
		}
		$expected = $this->transformer->canonical( $transformed, $operation['target'] );
		$actual = $this->transformer->canonical( $target, $operation['target'] );
		if ( is_wp_error( $expected ) || is_wp_error( $actual ) ) {
			return $this->value_error( is_wp_error( $expected ) ? $expected : $actual, $operation, $id );
		}
		return $expected === $actual;
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

	private function duplicate( array $operation, $id ) {
		return $this->error( 'eit_migration_meta_duplicate', __( 'CPT migration found duplicate physical meta values for one post.', 'elementor-implementation-toolkit' ), $operation, $id );
	}

	private function diverged( array $operation, $id ) {
		return $this->error( 'eit_migration_target_diverged', __( 'CPT migration target differs from the transformed source.', 'elementor-implementation-toolkit' ), $operation, $id );
	}

	private function value_error( \WP_Error $error, array $operation, $id = 0 ) {
		$error->add_data( [ 'operation_id' => (string) ( $operation['id'] ?? '' ), 'record_id' => absint( $id ) ] );
		return $error;
	}

	private function error( $code, $message, array $operation = [], $id = 0 ) {
		return new \WP_Error( $code, $message, [ 'operation_id' => (string) ( $operation['id'] ?? '' ), 'record_id' => absint( $id ) ] );
	}

	protected function entity_ids( $post_type, $cursor, $limit ) {
		global $wpdb;

		$table = $wpdb->posts;
		$sql = $wpdb->prepare( "SELECT ID FROM `{$table}` WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d", $post_type, $cursor, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal WordPress table name and validated post type.
		$wpdb->last_error = '';
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return '' === $wpdb->last_error ? array_map( 'absint', $ids ?: [] ) : new \WP_Error( 'eit_migration_storage_read_failed', __( 'CPT migration could not read post identifiers.', 'elementor-implementation-toolkit' ) );
	}

	protected function meta_values( array $ids, $key ) {
		global $wpdb;

		if ( ! $ids ) {
			return [];
		}
		$table = $wpdb->postmeta;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$params = array_merge( [ $key ], array_map( 'absint', $ids ) );
		$sql = $wpdb->prepare( "SELECT post_id,meta_value FROM `{$table}` WHERE meta_key = %s AND post_id IN ({$placeholders}) ORDER BY post_id,meta_id", $params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name and generated placeholders.
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'eit_migration_storage_read_failed', __( 'CPT migration could not read post meta.', 'elementor-implementation-toolkit' ) );
		}
		$values = [];
		foreach ( $rows ?: [] as $row ) {
			$values[ absint( $row['post_id'] ) ][] = $row['meta_value'];
		}
		return $values;
	}

	protected function invalidate_meta_cache( $post_id ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( absint( $post_id ), 'post_meta' );
		} elseif ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( absint( $post_id ) );
		}
	}

	protected function insert_target_value( $post_id, $key, $value ) {
		global $wpdb;

		$table = $wpdb->postmeta;
		$set = null === $value ? 'NULL' : '%s';
		$params = null === $value ? [ $post_id, $key, $post_id, $key ] : [ $post_id, $key, $value, $post_id, $key ];
		$sql = "INSERT INTO `{$table}` (post_id,meta_key,meta_value) SELECT %d,%s,{$set} WHERE NOT EXISTS (SELECT 1 FROM `{$table}` WHERE post_id = %d AND meta_key = %s)";
		$wpdb->last_error = '';
		$result = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SQL is prepared; internal table name is trusted.
		return false === $result || '' !== $wpdb->last_error ? new \WP_Error( 'eit_migration_target_write_failed', __( 'Target post meta could not be written.', 'elementor-implementation-toolkit' ) ) : $result;
	}
}
