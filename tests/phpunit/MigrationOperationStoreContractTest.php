<?php
/**
 * Focused contracts for the durable staged-migration ledger.
 */

use EIT\Blueprint\MigrationWriteFence;
use EIT\Infrastructure\MigrationOperationStore;
use EIT\Infrastructure\Tables;
use EIT\Infrastructure\Transaction;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time() {
		return '2026-07-16 12:00:00';
	}
}

class MigrationOperationTransaction extends Transaction {
	public function run( callable $callback ) {
		return $callback();
	}
}

class MigrationOperationWpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $rows = [];
	public $change_sets = [];
	public $fail_results = false;

	public function suppress_errors( $suppress = null ) {
		return false;
	}

	public function prepare( $query, ...$arguments ) {
		if ( 1 === count( $arguments ) && is_array( $arguments[0] ) ) {
			$arguments = $arguments[0];
		}
		foreach ( $arguments as $argument ) {
			$replacement = "'" . str_replace( "'", "''", (string) $argument ) . "'";
			$query = preg_replace( '/%s/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function insert( $table, array $data ) {
		foreach ( $this->rows as $row ) {
			if ( $row['id'] === $data['id'] || $row['target_identity_hash'] === $data['target_identity_hash'] || ( $row['change_set_id'] === $data['change_set_id'] && $row['field_id'] === $data['field_id'] ) ) {
				$this->last_error = 'Duplicate entry';
				return false;
			}
		}
		$this->last_error = '';
		$this->rows[ $data['id'] ] = $data;
		return 1;
	}

	public function update( $table, array $data, array $where ) {
		foreach ( $this->rows as $id => $row ) {
			foreach ( $where as $key => $expected ) {
				if ( ! array_key_exists( $key, $row ) || (string) $row[ $key ] !== (string) $expected ) {
					continue 2;
				}
			}
			$this->rows[ $id ] = array_merge( $row, $data );
			return 1;
		}
		return 0;
	}

	public function get_row( $query, $output ) {
		if ( str_contains( $query, 'eit_change_sets' ) && preg_match( "/WHERE id = '([^']+)'/", $query, $matches ) ) {
			return $this->change_sets[ $matches[1] ] ?? null;
		}
		if ( preg_match( "/WHERE id = '([^']+)'/", $query, $matches ) ) {
			return $this->rows[ $matches[1] ] ?? null;
		}
		if ( preg_match( "/WHERE `target_identity_hash` = '([^']+)'/", $query, $matches ) ) {
			return $this->first( fn( $row ) => $row['target_identity_hash'] === $matches[1] );
		}
		if ( preg_match( "/WHERE change_set_id = '([^']+)' AND field_id = '([^']+)'/", $query, $matches ) ) {
			return $this->first( fn( $row ) => $row['change_set_id'] === $matches[1] && $row['field_id'] === $matches[2] );
		}
		return null;
	}

	public function get_results( $query, $output ) {
		if ( $this->fail_results ) {
			$this->last_error = 'simulated ledger read failure';
			return null;
		}
		if ( str_contains( $query, 'WHERE status IN' ) ) {
			return array_values( $this->rows );
		}
		if ( ! preg_match( "/WHERE blueprint_id = '([^']+)' AND change_set_id = '([^']+)'/", $query, $matches ) ) {
			return [];
		}
		$rows = array_filter( $this->rows, fn( $row ) => $row['blueprint_id'] === $matches[1] && $row['change_set_id'] === $matches[2] );
		usort( $rows, fn( $left, $right ) => strcmp( $left['field_id'], $right['field_id'] ) );
		return array_values( $rows );
	}

	private function first( callable $matches ) {
		foreach ( $this->rows as $row ) {
			if ( $matches( $row ) ) {
				return $row;
			}
		}
		return null;
	}
}

class MigrationOperationStoreContractTest extends TestCase {

	private const BLUEPRINT_ID = '11111111-1111-4111-8111-111111111111';
	private const CHANGE_SET_ID = '22222222-2222-4222-8222-222222222222';
	private const FIELD_ID = '33333333-3333-4333-8333-333333333333';

	private $wpdb;
	private $store;

	protected function setUp(): void {
		global $wpdb;

		$this->wpdb = new MigrationOperationWpdb();
		$this->wpdb->change_sets[ self::CHANGE_SET_ID ] = [ 'blueprint_id' => self::BLUEPRINT_ID, 'status' => 'applying' ];
		$wpdb = $this->wpdb;
		$this->store = new MigrationOperationStore( new MigrationOperationTransaction() );
	}

	public function test_checksum_and_physical_identities_are_canonical_and_side_scoped(): void {
		$operation = $this->operation();
		$reordered = array_reverse( $operation, true );

		self::assertSame( MigrationOperationStore::operation_checksum( $operation ), MigrationOperationStore::operation_checksum( $reordered ) );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', MigrationOperationStore::source_identity_hash( $operation ) );
		self::assertNotSame( MigrationOperationStore::source_identity_hash( $operation ), MigrationOperationStore::target_identity_hash( $operation ) );
		self::assertTrue( MigrationOperationStore::can_transition( 'copying', 'copied' ) );
		self::assertFalse( MigrationOperationStore::can_transition( 'copied', 'switched' ) );
	}

	public function test_reservation_is_idempotent_but_target_identity_is_permanently_unique(): void {
		$operation = $this->operation();
		$first = $this->store->reserve_many( self::BLUEPRINT_ID, self::CHANGE_SET_ID, [ $operation ] );
		$second = $this->store->reserve_many( self::BLUEPRINT_ID, self::CHANGE_SET_ID, [ $operation ] );

		self::assertSame( 'planned', $first[0]['status'] );
		self::assertSame( $first[0]['operation_checksum'], $second[0]['operation_checksum'] );
		self::assertCount( 1, $this->store->for_change_set( self::BLUEPRINT_ID, self::CHANGE_SET_ID ) );

		$collision = $operation;
		$collision['transform'] = 'different';
		self::assertSame( 'eit_migration_operation_collision', $this->store->reserve_many( self::BLUEPRINT_ID, self::CHANGE_SET_ID, [ $collision ] )->get_error_code() );

		$target_collision = $operation;
		$target_collision['id'] = hash( 'sha256', 'other-operation' );
		$target_collision['field_id'] = '44444444-4444-4444-8444-444444444444';
		self::assertSame( 'eit_migration_target_claimed', $this->store->reserve_many( self::BLUEPRINT_ID, self::CHANGE_SET_ID, [ $target_collision ] )->get_error_code() );
	}

	public function test_cas_rejects_stale_writes_and_proof_is_required_for_validation(): void {
		$record = $this->reserve();
		$record = $this->store->transition( $record['id'], 'planned', 0, 'target_preparing' );
		self::assertSame( 1, $record['state_revision'] );
		self::assertSame( 'eit_migration_state_conflict', $this->store->transition( $record['id'], 'target_preparing', 0, 'target_prepared' )->get_error_code() );
		$record = $this->store->transition( $record['id'], 'target_preparing', 1, 'target_prepared' );
		$record = $this->store->transition( $record['id'], 'target_prepared', 2, 'copying' );
		$record = $this->store->checkpoint( $record['id'], 3, [ 'last_id' => 10 ], 10 );
		self::assertSame( 10, $record['copied_count'] );
		self::assertSame( 'eit_migration_state_conflict', $this->store->checkpoint( $record['id'], 4, [ 'last_id' => 9 ], 9 )->get_error_code() );
		$record = $this->store->transition( $record['id'], 'copying', 4, 'copied' );
		$record = $this->store->transition( $record['id'], 'copied', 5, 'validating' );
		self::assertSame( 'eit_migration_transition_invalid', $this->store->transition( $record['id'], 'validating', 6, 'validated' )->get_error_code() );

		$checksum = hash( 'sha256', 'semantic-values' );
		$proof = [
			'source_count' => 10,
			'transformed_count' => 10,
			'target_count' => 10,
			'rejected_count' => 0,
			'source_checksum' => $checksum,
			'target_checksum' => $checksum,
		];
		$record = $this->store->record_proof( $record['id'], 6, $proof, true );
		self::assertSame( 'validated', $record['status'] );
		self::assertSame( 10, $record['target_count'] );
	}

	public function test_retryable_failure_resumes_exact_phase_and_terminal_failure_finishes(): void {
		$record = $this->reserve();
		$record = $this->store->transition( $record['id'], 'planned', 0, 'target_preparing' );
		$record = $this->store->record_failure( $record['id'], 'target_preparing', 1, 'retryable_failure', 'eit_driver_timeout' );
		self::assertSame( 'target_preparing', $record['resume_status'] );
		self::assertSame( 1, $record['attempts'] );
		$record = $this->store->resume( $record['id'], 2 );
		self::assertSame( 'target_preparing', $record['status'] );
		self::assertNull( $record['error_code'] );
		$record = $this->store->record_failure( $record['id'], 'target_preparing', 3, 'terminal_failure', 'eit_driver_contract_invalid' );
		self::assertSame( 'terminal_failure', $record['status'] );
		self::assertNotNull( $record['finished_at'] );
	}

	public function test_change_set_read_failure_is_never_coerced_to_an_empty_ledger(): void {
		$this->wpdb->fail_results = true;

		$result = $this->store->for_change_set( self::BLUEPRINT_ID, self::CHANGE_SET_ID );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'eit_migration_read_failed', $result->get_error_code() );
		self::assertStringContainsString( 'simulated ledger read failure', $result->get_error_data()['database_error'] );
	}

	public function test_corrupted_operation_columns_fail_get_list_and_write_fence_closed(): void {
		$record = $this->reserve();
		$original = $this->wpdb->rows[ $record['id'] ];
		$corruptions = [
			'id' => str_repeat( 'e', 64 ),
			'field_id' => '44444444-4444-4444-8444-444444444444',
			'adapter' => 'cct',
			'operation_checksum' => str_repeat( 'f', 64 ),
			'source_identity_hash' => str_repeat( 'd', 64 ),
			'target_identity_hash' => str_repeat( 'c', 64 ),
		];
		foreach ( $corruptions as $column => $value ) {
			$this->wpdb->rows[ $record['id'] ] = array_replace( $original, [ $column => $value ] );
			self::assertSame( 'eit_migration_record_integrity_failed', $this->store->get( $record['id'] )->get_error_code(), $column );
		}
		$operation = json_decode( $original['operation'], true );
		$operation['target']['type'] = 'money';
		$this->wpdb->rows[ $record['id'] ] = array_replace( $original, [ 'operation' => wp_json_encode( $operation ) ] );
		self::assertSame( 'eit_migration_record_integrity_failed', $this->store->get( $record['id'] )->get_error_code(), 'operation JSON' );

		$this->wpdb->rows[ $record['id'] ] = $original;
		$this->wpdb->rows[ $record['id'] ]['status'] = 'copying';
		$this->wpdb->rows[ $record['id'] ]['operation_checksum'] = str_repeat( 'f', 64 );

		$get = $this->store->get( $record['id'] );
		$list = $this->store->for_change_set( self::BLUEPRINT_ID, self::CHANGE_SET_ID );
		$fence = ( new MigrationWriteFence() )->guard_storage( 'cpt', 'listings' );

		self::assertSame( 'eit_migration_record_integrity_failed', $get->get_error_code() );
		self::assertSame( 'eit_migration_record_integrity_failed', $list->get_error_code() );
		self::assertSame( 'eit_migration_record_integrity_failed', $fence->get_error_code() );
	}

	public function test_state_writers_propagate_corrupt_hydration_errors(): void {
		$record = $this->reserve();
		$this->wpdb->rows[ $record['id'] ]['operation_checksum'] = str_repeat( 'f', 64 );
		$results = [
			$this->store->checkpoint( $record['id'], 0, [], 0 ),
			$this->store->record_proof( $record['id'], 0, [], false ),
			$this->store->record_failure( $record['id'], 'planned', 0, 'terminal_failure', 'driver_contract_invalid' ),
			$this->store->resume( $record['id'], 0 ),
		];

		foreach ( $results as $result ) {
			self::assertSame( 'eit_migration_record_integrity_failed', $result->get_error_code() );
		}
	}

	public function test_terminal_group_failure_aborts_every_pre_switch_sibling_with_lineage(): void {
		$first = $this->operation();
		$second = $this->operation();
		$second['id'] = hash( 'sha256', 'second-migration-operation' );
		$second['field_id'] = '44444444-4444-4444-8444-444444444444';
		$second['target']['key'] = 'price_v3';
		$records = $this->store->reserve_many( self::BLUEPRINT_ID, self::CHANGE_SET_ID, [ $first, $second ] );
		foreach ( $records as $record ) {
			$this->store->transition( $record['id'], 'planned', 0, 'target_preparing' );
		}

		$result = $this->store->record_terminal_group_failure( self::BLUEPRINT_ID, self::CHANGE_SET_ID, $first['id'], 'target_preparing', 1, 'eit_migration_target_exists' );
		$by_id = array_column( $result, null, 'id' );

		self::assertSame( 'terminal_failure', $by_id[ $first['id'] ]['status'] );
		self::assertSame( 'eit_migration_target_exists', $by_id[ $first['id'] ]['error_code'] );
		self::assertSame( 'aborted', $by_id[ $second['id'] ]['status'] );
		self::assertSame( 'eit_migration_sibling_aborted_' . substr( $first['id'], 0, 12 ), $by_id[ $second['id'] ]['error_code'] );
		self::assertNotNull( $by_id[ $second['id'] ]['finished_at'] );
	}

	public function test_schema_and_uninstall_include_the_immutable_ledger(): void {
		self::assertContains( Tables::MIGRATION_OPERATIONS, Tables::keys() );
		$schema = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Infrastructure/SchemaManager.php' );
		$uninstall = file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		self::assertStringContainsString( 'UNIQUE KEY target_identity (target_identity_hash)', $schema );
		self::assertStringContainsString( "'migration_operations'", $uninstall );
	}

	private function reserve() {
		return $this->store->reserve_many( self::BLUEPRINT_ID, self::CHANGE_SET_ID, [ $this->operation() ] )[0];
	}

	private function operation() {
		return [
			'id' => hash( 'sha256', 'migration-operation' ),
			'blueprint_scope' => 'field',
			'entity_id' => '55555555-5555-4555-8555-555555555555',
			'field_id' => self::FIELD_ID,
			'adapter' => 'cpt',
			'strategy' => 'cpt',
			'storage_slug' => 'listings',
			'source' => [ 'key' => 'price', 'type' => 'integer', 'shape' => 'scalar' ],
			'target' => [ 'key' => 'price_v2', 'type' => 'decimal', 'shape' => 'scalar' ],
			'transform' => 'integer_to_decimal',
			'preserve_source' => true,
		];
	}
}
