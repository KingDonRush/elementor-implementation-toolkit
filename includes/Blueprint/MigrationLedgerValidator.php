<?php
/**
 * Proves that a frozen migration plan exactly matches its durable ledger.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\MigrationOperationRecordCodec;
use EIT\Infrastructure\MigrationOperationStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationLedgerValidator {

	private $operations;

	public function __construct( $operations = null ) {
		$this->operations = $operations ?: new MigrationOperationStore();
	}

	public function validate( array $change_set ) {
		$blueprint_id = (string) ( $change_set['blueprint_id'] ?? '' );
		$change_set_id = (string) ( $change_set['id'] ?? '' );
		$plan = $change_set['impact']['migration_plan']['operations'] ?? null;
		if ( '' === $blueprint_id || '' === $change_set_id || ! is_array( $plan ) || ! array_is_list( $plan ) ) {
			return $this->failure( 'plan_scope_invalid' );
		}

		$expected = $this->normalize_plan( $plan );
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}
		$records = $this->operations->for_change_set( $blueprint_id, $change_set_id );
		if ( is_wp_error( $records ) ) {
			return $records;
		}
		$actual = $this->normalize_ledger( $records, $blueprint_id, $change_set_id );
		if ( is_wp_error( $actual ) ) {
			return $actual;
		}

		$missing = array_values( array_diff( array_keys( $expected ), array_keys( $actual ) ) );
		$unexpected = array_values( array_diff( array_keys( $actual ), array_keys( $expected ) ) );
		if ( count( $expected ) !== count( $actual ) || $missing || $unexpected ) {
			return $this->failure(
				'operation_set_mismatch',
				[
					'expected_count' => count( $expected ),
					'actual_count' => count( $actual ),
					'missing_operation_ids' => $missing,
					'unexpected_operation_ids' => $unexpected,
				]
			);
		}

		$divergent = [];
		foreach ( $expected as $id => $signature ) {
			if ( $signature !== $actual[ $id ] ) {
				$divergent[] = $id;
			}
		}
		return $divergent
			? $this->failure( 'operation_signature_mismatch', [ 'divergent_operation_ids' => $divergent ] )
			: true;
	}

	private function normalize_plan( array $operations ) {
		$normalized = [];
		foreach ( $operations as $index => $operation ) {
			$record = MigrationOperationRecordCodec::normalize_operation( $operation );
			if ( is_wp_error( $record ) ) {
				return $this->failure( 'planned_operation_invalid', [ 'operation_index' => $index ] );
			}
			if ( isset( $normalized[ $record['id'] ] ) ) {
				return $this->failure( 'planned_operation_duplicate', [ 'operation_id' => $record['id'] ] );
			}
			$normalized[ $record['id'] ] = $this->signature( $record );
		}
		ksort( $normalized );
		return $normalized;
	}

	private function normalize_ledger( $records, $blueprint_id, $change_set_id ) {
		if ( ! is_array( $records ) || ! array_is_list( $records ) ) {
			return $this->failure( 'ledger_shape_invalid' );
		}
		$normalized = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || ! is_array( $record['operation'] ?? null ) ) {
				return $this->failure( 'durable_operation_invalid' );
			}
			$operation = MigrationOperationRecordCodec::normalize_operation( $record['operation'] );
			if ( is_wp_error( $operation ) ) {
				return $this->failure( 'durable_operation_invalid', $this->operation_data( $record ) );
			}
			$scope_matches = (string) ( $record['blueprint_id'] ?? '' ) === $blueprint_id
				&& (string) ( $record['change_set_id'] ?? '' ) === $change_set_id;
			$signature = $this->signature( $record );
			if ( ! $scope_matches || $signature !== $this->signature( $operation ) ) {
				return $this->failure( 'durable_operation_divergent', $this->operation_data( $record ) );
			}
			$id = $operation['id'];
			if ( isset( $normalized[ $id ] ) ) {
				return $this->failure( 'durable_operation_duplicate', [ 'operation_id' => $id ] );
			}
			$normalized[ $id ] = $signature;
		}
		ksort( $normalized );
		return $normalized;
	}

	private function signature( array $record ) {
		$keys = [ 'id', 'field_id', 'adapter', 'operation_checksum', 'source_identity_hash', 'target_identity_hash' ];
		return array_map( fn( $key ) => (string) ( $record[ $key ] ?? '' ), array_combine( $keys, $keys ) );
	}

	private function operation_data( array $record ) {
		$id = (string) ( $record['id'] ?? '' );
		return preg_match( '/^[a-f0-9]{64}$/', $id ) ? [ 'operation_id' => $id ] : [];
	}

	private function failure( $reason, array $data = [] ) {
		return new \WP_Error(
			'eit_migration_ledger_integrity_failed',
			__( 'Migration execution is blocked because its durable plan is incomplete or divergent.', 'elementor-implementation-toolkit' ),
			array_merge( [ 'reason' => $reason ], $data )
		);
	}
}
