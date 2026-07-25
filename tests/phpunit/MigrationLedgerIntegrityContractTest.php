<?php
/**
 * Exact plan/ledger integrity and lifecycle fail-closed boundaries.
 */

use EIT\Blueprint\BlueprintApplyService;
use EIT\Blueprint\BlueprintReconciliationService;
use EIT\Blueprint\LifecycleService;
use EIT\Blueprint\MigrationLedgerValidator;
use EIT\Infrastructure\MigrationOperationRecordCodec;
use PHPUnit\Framework\TestCase;

class MigrationLedgerIntegrityContractTest extends TestCase {

	public const BLUEPRINT_ID = '11111111-1111-4111-8111-111111111111';
	public const CHANGE_SET_ID = '22222222-2222-4222-8222-222222222222';

	public function test_exact_plan_and_durable_signatures_match(): void {
		$operation = $this->operation();
		$validator = new MigrationLedgerValidator( new LedgerIntegrityStore( [ $this->record( $operation ) ] ) );

		self::assertTrue( $validator->validate( $this->change_set( [ $operation ] ) ) );
	}

	public function test_empty_missing_and_extra_ledgers_fail_with_exact_set_evidence(): void {
		$operation = $this->operation();
		$missing = ( new MigrationLedgerValidator( new LedgerIntegrityStore( [] ) ) )->validate( $this->change_set( [ $operation ] ) );
		$extra = ( new MigrationLedgerValidator( new LedgerIntegrityStore( [ $this->record( $operation ) ] ) ) )->validate( $this->change_set( [] ) );

		self::assertSame( 'operation_set_mismatch', $missing->get_error_data()['reason'] );
		self::assertSame( [ $operation['id'] ], $missing->get_error_data()['missing_operation_ids'] );
		self::assertSame( 0, $missing->get_error_data()['actual_count'] );
		self::assertSame( 'operation_set_mismatch', $extra->get_error_data()['reason'] );
		self::assertSame( [ $operation['id'] ], $extra->get_error_data()['unexpected_operation_ids'] );
		self::assertSame( 0, $extra->get_error_data()['expected_count'] );
	}

	public function test_checksum_field_adapter_and_physical_identity_drift_fail(): void {
		$operation = $this->operation();
		$mutations = [
			function ( array $candidate ) {
				$candidate['target']['type'] = 'money';
				return $candidate;
			},
			function ( array $candidate ) {
				$candidate['field_id'] = '44444444-4444-4444-8444-444444444444';
				return $candidate;
			},
			function ( array $candidate ) {
				$candidate['adapter'] = 'cct';
				return $candidate;
			},
			function ( array $candidate ) {
				$candidate['target']['key'] = 'price_v3';
				return $candidate;
			},
		];

		foreach ( $mutations as $mutate ) {
			$candidate = $mutate( $operation );
			$result = ( new MigrationLedgerValidator( new LedgerIntegrityStore( [ $this->record( $operation ) ] ) ) )->validate( $this->change_set( [ $candidate ] ) );
			self::assertSame( 'operation_signature_mismatch', $result->get_error_data()['reason'] );
			self::assertSame( [ $operation['id'] ], $result->get_error_data()['divergent_operation_ids'] );
		}
	}

	public function test_durable_columns_scope_and_embedded_operation_must_agree(): void {
		$operation = $this->operation();
		$cases = [];
		$cases['checksum'] = array_replace( $this->record( $operation ), [ 'operation_checksum' => str_repeat( 'f', 64 ) ] );
		$cases['scope'] = array_replace( $this->record( $operation ), [ 'change_set_id' => 'foreign-change-set' ] );
		$cases['identity'] = array_replace( $this->record( $operation ), [ 'source_identity_hash' => str_repeat( 'e', 64 ) ] );
		$embedded = $this->record( $operation );
		$embedded['operation']['target']['type'] = 'money';
		$cases['embedded operation'] = $embedded;

		foreach ( $cases as $label => $record ) {
			$result = ( new MigrationLedgerValidator( new LedgerIntegrityStore( [ $record ] ) ) )->validate( $this->change_set( [ $operation ] ) );
			self::assertSame( 'durable_operation_divergent', $result->get_error_data()['reason'], $label );
		}
	}

	public function test_invalid_or_duplicate_plan_and_ledger_read_failure_are_fail_closed(): void {
		$operation = $this->operation();
		$invalid = $this->change_set( [ [ 'id' => 'unsafe' ] ] );
		$duplicate = $this->change_set( [ $operation, $operation ] );
		$read_error = new WP_Error( 'eit_migration_read_failed', 'ledger unavailable' );

		self::assertSame( 'planned_operation_invalid', ( new MigrationLedgerValidator( new LedgerIntegrityStore() ) )->validate( $invalid )->get_error_data()['reason'] );
		self::assertSame( 'planned_operation_duplicate', ( new MigrationLedgerValidator( new LedgerIntegrityStore() ) )->validate( $duplicate )->get_error_data()['reason'] );
		self::assertSame( $read_error, ( new MigrationLedgerValidator( new LedgerIntegrityStore( $read_error ) ) )->validate( $this->change_set( [] ) ) );
	}

	public function test_apply_reconcile_and_rollback_reject_integrity_before_any_lock_or_transition(): void {
		$error = new WP_Error( 'eit_migration_ledger_integrity_failed', 'ledger mismatch' );
		$state = (object) [ 'locks' => 0, 'transitions' => 0, 'validations' => 0 ];
		$change_set = $this->change_set( [] ) + [ 'status' => 'prepared', 'confirmation_hash' => hash( 'sha256', 'token' ) ];
		$change_sets = new LedgerBoundaryChangeSets( $change_set, $state );
		$ledger = new LedgerBoundaryValidator( $error, $state );
		$locks = new LedgerBoundaryLocks( $state );

		$apply = new BlueprintApplyService( $this->apply_dependencies( $change_sets, $ledger, $locks ) );
		self::assertSame( $error, $apply->apply( self::CHANGE_SET_ID, 'token' ) );
		self::assertSame( 0, $state->locks );
		self::assertSame( 0, $state->transitions );

		$change_sets->record['status'] = 'applied';
		$reconcile = new BlueprintReconciliationService( $this->reconcile_dependencies( $change_sets, $ledger, $locks ) );
		self::assertSame( $error, $reconcile->reconcile( self::CHANGE_SET_ID ) );
		self::assertSame( 0, $state->locks );
		self::assertSame( 0, $state->transitions );

		$versions = new LedgerBoundaryVersions();
		$lifecycle = new LifecycleService(
			[
				'blueprints' => new LedgerBoundaryBlueprints(),
				'versions' => $versions,
				'change_sets' => $change_sets,
				'migration_ledger' => $ledger,
				'locks' => $locks,
				'ownership' => new stdClass(),
			]
		);
		self::assertSame( $error, $lifecycle->rollback( self::BLUEPRINT_ID, 1, 'rollback reason' ) );
		self::assertSame( 0, $state->locks );
		self::assertSame( 0, $state->transitions );
		self::assertSame( 3, $state->validations );
	}

	private function apply_dependencies( $change_sets, $ledger, $locks ): array {
		$unused = new stdClass();
		$dependencies = array_fill_keys( [ 'publication', 'ownership', 'compiler', 'preparer', 'blueprints', 'versions', 'artifacts', 'bindings', 'field_migrations', 'runs', 'run_events', 'cache', 'transaction' ], $unused );
		return $dependencies + [ 'change_sets' => $change_sets, 'migration_ledger' => $ledger, 'locks' => $locks ];
	}

	private function reconcile_dependencies( $change_sets, $ledger, $locks ): array {
		$unused = new stdClass();
		$dependencies = array_fill_keys( [ 'blueprints', 'versions', 'artifacts', 'bindings', 'field_migrations', 'migration_locks', 'runs', 'run_events', 'reconciliations', 'transaction' ], $unused );
		return $dependencies + [ 'change_sets' => $change_sets, 'migration_ledger' => $ledger, 'locks' => $locks ];
	}

	private function change_set( array $operations ): array {
		return [ 'id' => self::CHANGE_SET_ID, 'blueprint_id' => self::BLUEPRINT_ID, 'impact' => [ 'migration_plan' => [ 'operations' => $operations ] ] ];
	}

	private function record( array $operation ): array {
		$record = MigrationOperationRecordCodec::normalize_operation( $operation );
		$record['operation'] = $operation;
		$record['blueprint_id'] = self::BLUEPRINT_ID;
		$record['change_set_id'] = self::CHANGE_SET_ID;
		return $record;
	}

	private function operation(): array {
		return [
			'id' => hash( 'sha256', 'ledger-operation' ),
			'blueprint_scope' => 'field',
			'entity_id' => '55555555-5555-4555-8555-555555555555',
			'field_id' => '33333333-3333-4333-8333-333333333333',
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

class LedgerIntegrityStore {
	private $records;
	public function __construct( $records = [] ) {
		$this->records = $records;
	}
	public function for_change_set() {
		return $this->records;
	}
}

class LedgerBoundaryValidator {
	private $error;
	private $state;
	public function __construct( WP_Error $error, $state ) {
		$this->error = $error;
		$this->state = $state;
	}
	public function validate() {
		++$this->state->validations;
		return $this->error;
	}
}

class LedgerBoundaryLocks {
	private $state;
	public function __construct( $state ) {
		$this->state = $state;
	}
	public function acquire() {
		++$this->state->locks;
		return 'lock';
	}
	public function release() {
		return true;
	}
}

class LedgerBoundaryChangeSets {
	public $record;
	private $state;
	public function __construct( array $record, $state ) {
		$this->record = $record;
		$this->state = $state;
	}
	public function get() {
		return $this->record;
	}
	public function transition() {
		++$this->state->transitions;
		return true;
	}
	public function published_for_checksum() {
		return $this->record;
	}
}

class LedgerBoundaryBlueprints {
	public function get() {
		return [ 'id' => MigrationLedgerIntegrityContractTest::BLUEPRINT_ID, 'active_version_id' => 2 ];
	}
}

class LedgerBoundaryVersions {
	public function get( $id ) {
		return 1 === (int) $id
			? [ 'id' => 1, 'blueprint_id' => MigrationLedgerIntegrityContractTest::BLUEPRINT_ID, 'checksum' => 'target', 'document' => [] ]
			: [ 'id' => 2, 'blueprint_id' => MigrationLedgerIntegrityContractTest::BLUEPRINT_ID, 'checksum' => 'active', 'document' => [] ];
	}
}
