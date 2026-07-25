<?php
/**
 * Canonical integrity proof for prepared and persisted publication snapshots.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PublicationSnapshotIntegrity {

	public function compare( $expected_artifacts, $actual_artifacts, $expected_bindings, $actual_bindings, $blueprint_id, $version_id ) {
		$sets = [];
		foreach (
			[
				'expected_artifacts' => [ $expected_artifacts, 'artifact', false ],
				'actual_artifacts' => [ $actual_artifacts, 'artifact', true ],
				'expected_bindings' => [ $expected_bindings, 'binding', false ],
				'actual_bindings' => [ $actual_bindings, 'binding', true ],
			] as $name => $definition
		) {
			$sets[ $name ] = $this->record_set( $definition[0], $definition[1], $definition[2], $blueprint_id, $version_id );
			if ( is_wp_error( $sets[ $name ] ) ) {
				$sets[ $name ]->add_data(
					array_merge(
						(array) $sets[ $name ]->get_error_data(),
						[ 'snapshot' => $name, 'status' => 409 ]
					)
				);
				return $sets[ $name ];
			}
		}

		$expected = [ 'artifacts' => $sets['expected_artifacts'], 'bindings' => $sets['expected_bindings'] ];
		$actual = [ 'artifacts' => $sets['actual_artifacts'], 'bindings' => $sets['actual_bindings'] ];
		$checksums = [
			'expected' => $this->checksum( $expected ),
			'actual' => $this->checksum( $actual ),
			'artifacts' => [
				'expected' => $this->checksum( $expected['artifacts'] ),
				'actual' => $this->checksum( $actual['artifacts'] ),
			],
			'bindings' => [
				'expected' => $this->checksum( $expected['bindings'] ),
				'actual' => $this->checksum( $actual['bindings'] ),
			],
		];
		if ( in_array( false, [ $checksums['expected'], $checksums['actual'], $checksums['artifacts']['expected'], $checksums['artifacts']['actual'], $checksums['bindings']['expected'], $checksums['bindings']['actual'] ], true ) ) {
			return new \WP_Error( 'eit_reconciliation_snapshot_encode_failed', __( 'Publication integrity evidence could not be encoded safely.', 'elementor-implementation-toolkit' ), [ 'status' => 409 ] );
		}

		$counts = [
			'expected' => count( $expected['artifacts'] ) + count( $expected['bindings'] ),
			'actual' => count( $actual['artifacts'] ) + count( $actual['bindings'] ),
			'artifacts' => [ 'expected' => count( $expected['artifacts'] ), 'actual' => count( $actual['artifacts'] ) ],
			'bindings' => [ 'expected' => count( $expected['bindings'] ), 'actual' => count( $actual['bindings'] ) ],
		];
		$mismatches = [];
		foreach ( [ 'artifacts', 'bindings' ] as $component ) {
			if ( $expected[ $component ] !== $actual[ $component ] ) {
				$mismatches[] = $component;
			}
		}

		return [
			'status' => $mismatches ? 'mismatch' : 'verified',
			'counts' => $counts,
			'checksums' => $checksums,
			'mismatches' => $mismatches,
		];
	}

	private function record_set( $records, $kind, $persisted, $blueprint_id, $version_id ) {
		if ( ! is_array( $records ) || ! array_is_list( $records ) ) {
			return $this->invalid( $kind, 'not_a_list' );
		}
		$set = [];
		foreach ( $records as $record ) {
			$normalized = $this->record( $record, $kind, $persisted, $blueprint_id, $version_id );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}
			$identity = 'artifact' === $kind ? $normalized['id'] : $normalized['field_id'];
			if ( isset( $set[ $identity ] ) ) {
				return $this->invalid( $kind, 'duplicate_identity', [ 'identity' => $identity ] );
			}
			$set[ $identity ] = $normalized;
		}
		ksort( $set, SORT_STRING );
		return $set;
	}

	private function record( $record, $kind, $persisted, $blueprint_id, $version_id ) {
		if ( ! is_array( $record ) ) {
			return $this->invalid( $kind, 'record_not_an_array' );
		}
		if ( $persisted && ( (string) ( $record['blueprint_id'] ?? '' ) !== (string) $blueprint_id || (int) ( $record['version_id'] ?? 0 ) !== (int) $version_id ) ) {
			return $this->invalid( $kind, 'authority_scope_mismatch' );
		}
		return 'artifact' === $kind
			? $this->artifact( $record, $blueprint_id, $version_id )
			: $this->binding( $persisted ? ( $record['payload'] ?? null ) : $record, $blueprint_id, $version_id );
	}

	private function artifact( array $record, $blueprint_id, $version_id ) {
		foreach ( [ 'id', 'kind', 'checksum', 'payload' ] as $key ) {
			if ( ! array_key_exists( $key, $record ) ) {
				return $this->invalid( 'artifact', 'missing_' . $key );
			}
		}
		if ( '' === (string) $record['id'] || '' === (string) $record['kind'] || ! preg_match( '/^[a-f0-9]{64}$/', (string) $record['checksum'] ) || ! is_array( $record['payload'] ) ) {
			return $this->invalid( 'artifact', 'invalid_contract' );
		}
		return $this->sort_recursive(
			[
				'blueprint_id' => (string) $blueprint_id,
				'version_id' => (int) $version_id,
				'id' => (string) $record['id'],
				'node_id' => null === ( $record['node_id'] ?? null ) ? null : (string) $record['node_id'],
				'kind' => (string) $record['kind'],
				'checksum' => (string) $record['checksum'],
				'payload' => $record['payload'],
			]
		);
	}

	private function binding( $payload, $blueprint_id, $version_id ) {
		if ( ! is_array( $payload ) ) {
			return $this->invalid( 'binding', 'payload_not_an_array' );
		}
		foreach ( [ 'field_id', 'entity_id', 'adapter', 'storage_key', 'aliases', 'migration' ] as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				return $this->invalid( 'binding', 'missing_' . $key );
			}
		}
		if ( '' === (string) $payload['field_id'] || '' === (string) $payload['entity_id'] || '' === (string) $payload['adapter'] || '' === (string) $payload['storage_key'] || ! is_array( $payload['aliases'] ) || ( null !== $payload['migration'] && ! is_array( $payload['migration'] ) ) ) {
			return $this->invalid( 'binding', 'invalid_contract' );
		}
		foreach ( $payload['aliases'] as $alias ) {
			if ( ! is_scalar( $alias ) ) {
				return $this->invalid( 'binding', 'invalid_alias' );
			}
		}
		$payload['aliases'] = array_values( array_unique( array_map( 'strval', $payload['aliases'] ) ) );
		sort( $payload['aliases'], SORT_STRING );
		$payload['blueprint_id'] = (string) $blueprint_id;
		$payload['version_id'] = (int) $version_id;
		return $this->sort_recursive( $payload );
	}

	private function checksum( array $records ) {
		$encoded = wp_json_encode( $records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? false : hash( 'sha256', $encoded );
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

	private function invalid( $component, $reason, array $data = [] ) {
		return new \WP_Error(
			'eit_reconciliation_snapshot_invalid',
			__( 'Prepared or persisted publication authority is structurally invalid.', 'elementor-implementation-toolkit' ),
			array_merge( [ 'component' => $component, 'reason' => $reason ], $data )
		);
	}
}
