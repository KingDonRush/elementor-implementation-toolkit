<?php
/**
 * Pure orchestration contracts for resumable destructive field migrations.
 */

use EIT\Blueprint\FieldMigrationExecutionService;
use PHPUnit\Framework\TestCase;

class FieldMigrationExecutionServiceContractTest extends TestCase {

	public function test_copy_validation_switch_and_reconcile_follow_durable_states(): void {
		$store = new ExecutionMigrationStore();
		$driver = new ExecutionMigrationDriver();
		$service = new FieldMigrationExecutionService( $store, [ $driver ] );

		self::assertNotInstanceOf( WP_Error::class, $service->before_runtime_prepare( 'blueprint', 'change' ) );
		self::assertSame( 'target_preparing', $store->record['status'] );
		self::assertNotInstanceOf( WP_Error::class, $service->after_runtime_prepare( 'blueprint', 'change' ) );
		self::assertSame( 'target_prepared', $store->record['status'] );
		self::assertNotInstanceOf( WP_Error::class, $service->copy_and_validate( 'blueprint', 'change' ) );
		self::assertSame( 'validated', $store->record['status'] );
		self::assertSame( [ 1 => '10', 2 => '20' ], $driver->target );

		$service->prepare_switch( 'blueprint', 'change' );
		self::assertSame( 'switching', $store->record['status'] );
		$service->mark_switched( 'blueprint', 'change' );
		self::assertSame( 'switched', $store->record['status'] );
		self::assertNotInstanceOf( WP_Error::class, $service->reconcile( 'blueprint', 'change' ) );
		self::assertSame( 'reconciled', $store->record['status'] );
	}

	public function test_checksum_mismatch_is_persisted_as_retryable_failure(): void {
		$store = new ExecutionMigrationStore( 'target_prepared' );
		$driver = new ExecutionMigrationDriver();
		$driver->corrupt_copy = true;
		$service = new FieldMigrationExecutionService( $store, [ $driver ] );

		$result = $service->copy_and_validate( 'blueprint', 'change' );

		self::assertSame( 'eit_migration_proof_mismatch', $result->get_error_code() );
		self::assertSame( 'retryable_failure', $store->record['status'] );
		self::assertSame( 'validating', $store->record['resume_status'] );
		self::assertSame( 1, $store->record['attempts'] );
	}

	public function test_copy_resumes_after_the_last_committed_record(): void {
		$store = new ExecutionMigrationStore( 'retryable_failure' );
		$store->record['resume_status'] = 'copying';
		$store->record['cursor'] = [ 'last_id' => 1 ];
		$store->record['copied_count'] = 1;
		$driver = new ExecutionMigrationDriver();
		$driver->target = [ 1 => '10' ];
		$service = new FieldMigrationExecutionService( $store, [ $driver ] );

		$result = $service->copy_and_validate( 'blueprint', 'change' );

		self::assertNotInstanceOf( WP_Error::class, $result );
		self::assertSame( 'validated', $store->record['status'] );
		self::assertSame( [ 1 => '10', 2 => '20' ], $driver->target );
		self::assertSame( 2, $store->record['copied_count'] );
	}

	public function test_rollback_reactivates_only_an_unchanged_preserved_source(): void {
		[ $service, $store ] = $this->reconciled_service();

		self::assertNotInstanceOf( WP_Error::class, $service->prepare_rollback( 'blueprint', 'change' ) );
		self::assertSame( 'rolling_back', $store->record['status'] );
		self::assertNotInstanceOf( WP_Error::class, $service->mark_rolled_back( 'blueprint', 'change' ) );
		self::assertSame( 'rolled_back', $store->record['status'] );
	}

	public function test_rollback_blocks_when_active_target_received_new_values(): void {
		[ $service, $store, $driver ] = $this->reconciled_service();
		$driver->target[2] = '30';

		$result = $service->prepare_rollback( 'blueprint', 'change' );

		self::assertSame( 'eit_migration_rollback_data_changed', $result->get_error_code() );
		self::assertSame( 'reconciled', $store->record['status'] );
	}

	public function test_ledger_read_errors_propagate_through_all_public_state_consumers(): void {
		$store = new ExecutionMigrationStore();
		$store->read_error = new WP_Error( 'eit_migration_read_failed', 'ledger unavailable' );
		$service = new FieldMigrationExecutionService( $store, [ new ExecutionMigrationDriver() ] );

		self::assertSame( $store->read_error, $service->has_operations( 'blueprint', 'change' ) );
		self::assertSame( $store->read_error, $service->can_retry( 'blueprint', 'change' ) );
		self::assertSame( $store->read_error, $service->before_runtime_prepare( 'blueprint', 'change' ) );
	}

	public function test_switch_persistence_failure_is_retryable_and_resumes_the_exact_phase(): void {
		$store = new ExecutionMigrationStore( 'switching' );
		$service = new FieldMigrationExecutionService( $store, [ new ExecutionMigrationDriver() ] );

		$recorded = $service->record_switch_failure( 'blueprint', 'change', new WP_Error( 'eit_blueprint_version_write_failed', 'temporary write failure' ) );

		self::assertTrue( $recorded['retryable'] );
		self::assertSame( 'retryable_failure', $store->record['status'] );
		self::assertSame( 'switching', $store->record['resume_status'] );
		self::assertNotInstanceOf( WP_Error::class, $service->prepare_switch( 'blueprint', 'change' ) );
		self::assertSame( 'switching', $store->record['status'] );
	}

	private function reconciled_service(): array {
		$store = new ExecutionMigrationStore();
		$driver = new ExecutionMigrationDriver();
		$service = new FieldMigrationExecutionService( $store, [ $driver ] );
		$service->before_runtime_prepare( 'blueprint', 'change' );
		$service->after_runtime_prepare( 'blueprint', 'change' );
		$service->copy_and_validate( 'blueprint', 'change' );
		$service->prepare_switch( 'blueprint', 'change' );
		$service->mark_switched( 'blueprint', 'change' );
		$service->reconcile( 'blueprint', 'change' );
		return [ $service, $store, $driver ];
	}
}

class ExecutionMigrationStore {

	public $record;
	public $read_error;

	public function __construct( string $status = 'planned' ) {
		$this->record = [
			'id' => str_repeat( 'a', 64 ),
			'blueprint_id' => 'blueprint',
			'change_set_id' => 'change',
			'operation' => [
				'id' => str_repeat( 'a', 64 ),
				'entity_id' => 'entity',
				'field_id' => 'field',
				'adapter' => 'cct',
				'strategy' => 'cct',
				'storage_slug' => 'projects',
				'source' => [ 'key' => 'budget', 'type' => 'integer', 'shape' => 'scalar' ],
				'target' => [ 'key' => 'budget_v2', 'type' => 'integer', 'shape' => 'scalar' ],
				'transform' => 'identity',
			],
			'status' => $status,
			'resume_status' => null,
			'state_revision' => 0,
			'cursor' => null,
			'copied_count' => 0,
			'attempts' => 0,
			'source_checksum' => null,
			'target_checksum' => null,
		];
	}

	public function for_change_set() {
		return $this->read_error ?: [ $this->record ];
	}

	public function reserve_many() {
		return [ $this->record ];
	}

	public function transition( $id, $from, $revision, $to ) {
		if ( $this->record['status'] !== $from || $this->record['state_revision'] !== $revision ) {
			return new WP_Error( 'state_conflict', 'state conflict' );
		}
		$this->record['status'] = $to;
		$this->record['resume_status'] = null;
		++$this->record['state_revision'];
		return $this->record;
	}

	public function checkpoint( $id, $revision, array $cursor, $count ) {
		if ( 'copying' !== $this->record['status'] || $revision !== $this->record['state_revision'] ) {
			return new WP_Error( 'state_conflict', 'state conflict' );
		}
		$this->record['cursor'] = $cursor;
		$this->record['copied_count'] = $count;
		++$this->record['state_revision'];
		return $this->record;
	}

	public function record_proof( $id, $revision, array $proof, $verified ) {
		if ( 'validating' !== $this->record['status'] || $revision !== $this->record['state_revision'] ) {
			return new WP_Error( 'state_conflict', 'state conflict' );
		}
		$this->record = array_merge( $this->record, $proof );
		$this->record['status'] = $verified ? 'validated' : 'retryable_failure';
		$this->record['resume_status'] = $verified ? null : 'validating';
		$this->record['attempts'] += $verified ? 0 : 1;
		++$this->record['state_revision'];
		return $this->record;
	}

	public function record_failure( $id, $status, $revision, $failure, $code ) {
		if ( $status !== $this->record['status'] || $revision !== $this->record['state_revision'] ) {
			return new WP_Error( 'state_conflict', 'state conflict' );
		}
		$this->record['status'] = $failure;
		$this->record['resume_status'] = 'retryable_failure' === $failure ? $status : null;
		$this->record['error_code'] = $code;
		++$this->record['attempts'];
		++$this->record['state_revision'];
		return $this->record;
	}

	public function record_retryable_group_failure( $blueprint_id, $change_set_id, $status, $code ) {
		if ( 'retryable_failure' === $this->record['status'] && $status === $this->record['resume_status'] ) {
			return [ $this->record ];
		}
		$result = $this->record_failure( $this->record['id'], $status, $this->record['state_revision'], 'retryable_failure', $code );
		return is_wp_error( $result ) ? $result : [ $result ];
	}

	public function record_terminal_group_failure( $blueprint_id, $change_set_id, $id, $status, $revision, $code ) {
		$result = $this->record_failure( $id, $status, $revision, 'terminal_failure', $code );
		return is_wp_error( $result ) ? $result : [ $result ];
	}

	public function resume( $id, $revision ) {
		if ( 'retryable_failure' !== $this->record['status'] || $revision !== $this->record['state_revision'] ) {
			return new WP_Error( 'state_conflict', 'state conflict' );
		}
		$this->record['status'] = $this->record['resume_status'];
		$this->record['resume_status'] = null;
		++$this->record['state_revision'];
		return $this->record;
	}
}

class ExecutionMigrationDriver {

	public $source = [ 1 => '10', 2 => '20' ];
	public $target = [];
	public $corrupt_copy = false;

	public function supports() {
		return true;
	}

	public function assert_target_available() {
		return true;
	}

	public function assert_target_ready() {
		return true;
	}

	public function copy_batch( array $operation, int $cursor ) {
		$processed = 0;
		foreach ( $this->source as $id => $value ) {
			if ( $id <= $cursor ) {
				continue;
			}
			$this->target[ $id ] = $this->corrupt_copy && 2 === $id ? '21' : $value;
			$cursor = $id;
			++$processed;
		}
		return [ 'cursor' => $cursor, 'processed' => $processed, 'copied' => $processed, 'complete' => true ];
	}

	public function fingerprint( array $operation, string $side ) {
		$values = 'target' === $side ? $this->target : $this->source;
		ksort( $values );
		return [
			'record_count' => count( $values ),
			'value_count' => count( $values ),
			'rejected_count' => 0,
			'checksum' => hash( 'sha256', wp_json_encode( $values ) ),
		];
	}
}
