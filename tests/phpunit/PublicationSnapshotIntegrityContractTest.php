<?php
/**
 * Publication reconciliation must prove complete artifacts and Field bindings.
 */

use EIT\Blueprint\BlueprintReconciliationService;
use EIT\Blueprint\LifecycleLeaseGuard;
use EIT\Blueprint\PublicationSnapshotIntegrity;
use PHPUnit\Framework\TestCase;

class PublicationSnapshotIntegrityContractTest extends TestCase {

	public const BLUEPRINT = '11111111-1111-4111-8111-111111111111';
	public const VERSION = 7;

	public function test_complete_snapshot_is_order_independent_and_verified(): void {
		$expected = $this->snapshot();
		$actual_artifacts = array_map( [ $this, 'persisted_artifact' ], array_reverse( $expected['artifacts'] ) );
		$actual_bindings = array_map( [ $this, 'persisted_binding' ], array_reverse( $expected['bindings'] ) );
		$actual_bindings[0]['payload']['aliases'] = array_reverse( $actual_bindings[0]['payload']['aliases'] );

		$result = $this->compare( $expected, $actual_artifacts, $actual_bindings );

		self::assertSame( 'verified', $result['status'] );
		self::assertSame( [], $result['mismatches'] );
		self::assertSame( $result['checksums']['expected'], $result['checksums']['actual'] );
		self::assertSame( [ 'expected' => 2, 'actual' => 2 ], $result['counts']['bindings'] );
	}

	public function test_artifact_payload_drift_is_detected_even_with_same_declared_checksum(): void {
		$expected = $this->snapshot();
		$actual_artifacts = array_map( [ $this, 'persisted_artifact' ], $expected['artifacts'] );
		$actual_artifacts[0]['payload']['definition']['slug'] = 'tampered';

		$result = $this->compare( $expected, $actual_artifacts, array_map( [ $this, 'persisted_binding' ], $expected['bindings'] ) );

		self::assertSame( 'mismatch', $result['status'] );
		self::assertSame( [ 'artifacts' ], $result['mismatches'] );
		self::assertNotSame( $result['checksums']['artifacts']['expected'], $result['checksums']['artifacts']['actual'] );
	}

	public function test_every_complete_binding_dimension_participates_in_the_proof(): void {
		$mutations = [
			'entity' => function ( array $binding ) { $binding['entity_id'] = 'entity-tampered'; return $binding; },
			'adapter' => function ( array $binding ) { $binding['adapter'] = 'cpt'; return $binding; },
			'storage key' => function ( array $binding ) { $binding['storage_key'] = 'title_v2'; return $binding; },
			'aliases' => function ( array $binding ) { $binding['aliases'][] = 'foreign'; return $binding; },
			'migration' => function ( array $binding ) { $binding['migration'] = [ 'transform' => 'replace' ]; return $binding; },
			'extension payload' => function ( array $binding ) { $binding['extension']['scope'] = 'foreign'; return $binding; },
		];

		foreach ( $mutations as $label => $mutate ) {
			$expected = $this->snapshot();
			$actual_bindings = array_map( [ $this, 'persisted_binding' ], $expected['bindings'] );
			$actual_bindings[0]['payload'] = $mutate( $actual_bindings[0]['payload'] );
			$result = $this->compare( $expected, array_map( [ $this, 'persisted_artifact' ], $expected['artifacts'] ), $actual_bindings );

			self::assertSame( 'mismatch', $result['status'], $label );
			self::assertSame( [ 'bindings' ], $result['mismatches'], $label );
		}
	}

	public function test_removed_binding_is_a_count_and_checksum_mismatch(): void {
		$expected = $this->snapshot();
		$actual_bindings = array_map( [ $this, 'persisted_binding' ], $expected['bindings'] );
		array_pop( $actual_bindings );

		$result = $this->compare( $expected, array_map( [ $this, 'persisted_artifact' ], $expected['artifacts'] ), $actual_bindings );

		self::assertSame( 'mismatch', $result['status'] );
		self::assertSame( [ 'expected' => 2, 'actual' => 1 ], $result['counts']['bindings'] );
		self::assertSame( [ 'bindings' ], $result['mismatches'] );
	}

	public function test_malformed_or_cross_scope_persisted_authority_fails_closed(): void {
		$expected = $this->snapshot();
		$artifacts = array_map( [ $this, 'persisted_artifact' ], $expected['artifacts'] );
		$bindings = array_map( [ $this, 'persisted_binding' ], $expected['bindings'] );
		$bindings[0]['blueprint_id'] = 'foreign';

		$result = $this->compare( $expected, $artifacts, $bindings );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'eit_reconciliation_snapshot_invalid', $result->get_error_code() );
		self::assertSame( 'authority_scope_mismatch', $result->get_error_data()['reason'] );
		self::assertSame( 'actual_bindings', $result->get_error_data()['snapshot'] );
	}

	public function test_artifact_and_binding_database_read_failures_stop_before_transition(): void {
		foreach ( [ 'artifacts', 'bindings' ] as $failed_component ) {
			$state = new PublicationSnapshotReadState( $failed_component );
			$unused = new stdClass();
			$service = new BlueprintReconciliationService(
				[
					'change_sets' => $unused,
					'blueprints' => $unused,
					'versions' => $unused,
					'artifacts' => new PublicationSnapshotArtifactReader( $state ),
					'bindings' => new PublicationSnapshotBindingReader( $state ),
					'field_migrations' => $unused,
					'migration_locks' => $unused,
					'locks' => $unused,
					'runs' => new PublicationSnapshotRuns( $state ),
					'run_events' => new PublicationSnapshotEvents( $state ),
					'reconciliations' => new PublicationSnapshotReconciliations( $state ),
					'transaction' => $unused,
				]
			);
			$method = new ReflectionMethod( $service, 'reconcile_locked' );
			$lease_guard = new LifecycleLeaseGuard(
				new class() {
					public function renew() {
						return true;
					}
				},
				[ 'blueprint-test' => 'token' ]
			);
			$error = $method->invoke(
				$service,
				[ 'id' => 'change-one', 'blueprint_id' => self::BLUEPRINT, 'compiled_artifacts' => [ 'artifacts' => [], 'bindings' => [] ], 'impact' => [] ],
				[ 'active_version_id' => self::VERSION ],
				$lease_guard
			);

			self::assertSame( $state->error, $error, $failed_component );
			self::assertSame( 0, $state->transitions, $failed_component );
			self::assertSame( [ 'publication_snapshot_read_failed' ], $state->events, $failed_component );
			self::assertSame( 'failed', $state->finished, $failed_component );
		}
	}

	public function test_reconcile_lease_loss_after_storage_gates_stops_before_runtime_reads(): void {
		$state = new PublicationSnapshotReconcileLeaseState();
		$unused = new stdClass();
		$service = new BlueprintReconciliationService(
			[
				'change_sets' => new PublicationSnapshotReconcileChangeSets( $state ),
				'blueprints' => new PublicationSnapshotReconcileBlueprints(),
				'versions' => new PublicationSnapshotReconcileVersions(),
				'artifacts' => $unused,
				'bindings' => $unused,
				'field_migrations' => $unused,
				'migration_ledger' => new PublicationSnapshotReconcileLedger(),
				'migration_locks' => new PublicationSnapshotReconcileMigrationLocks( $state ),
				'locks' => new PublicationSnapshotReconcileLocks( $state ),
				'runs' => $unused,
				'run_events' => $unused,
				'reconciliations' => $unused,
				'transaction' => $unused,
			]
		);

		$result = $service->reconcile( 'change-one' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'eit_lifecycle_lease_lost', $result->get_error_code() );
		self::assertSame( 1, $state->renew_calls );
		self::assertFalse( $state->transitioned );
		self::assertSame( [ 'storage-cct-items' ], $state->released_storage );
		self::assertSame( [ 'blueprint-' . self::BLUEPRINT, 'storage-ownership' ], $state->released_common );
	}

	private function compare( array $expected, array $actual_artifacts, array $actual_bindings ) {
		return ( new PublicationSnapshotIntegrity() )->compare( $expected['artifacts'], $actual_artifacts, $expected['bindings'], $actual_bindings, self::BLUEPRINT, self::VERSION );
	}

	private function persisted_artifact( array $artifact ): array {
		return $artifact + [ 'blueprint_id' => self::BLUEPRINT, 'version_id' => self::VERSION, 'created_at' => '2026-07-16 00:00:00' ];
	}

	private function persisted_binding( array $binding ): array {
		return [
			'id' => 1,
			'blueprint_id' => self::BLUEPRINT,
			'version_id' => self::VERSION,
			'field_id' => $binding['field_id'],
			'adapter' => $binding['adapter'],
			'storage_key' => $binding['storage_key'],
			'aliases' => $binding['aliases'],
			'payload' => $binding,
		];
	}

	private function snapshot(): array {
		return [
			'artifacts' => [
				[
					'id' => str_repeat( 'a', 64 ),
					'node_id' => 'entity-one',
					'kind' => 'entity_definition',
					'checksum' => str_repeat( '1', 64 ),
					'payload' => [ 'definition' => [ 'slug' => 'items', 'public' => false ], 'adapter' => [ 'version' => '1.0.0', 'id' => 'cct' ] ],
				],
				[
					'id' => str_repeat( 'b', 64 ),
					'node_id' => 'collection-one',
					'kind' => 'collection_contract',
					'checksum' => str_repeat( '2', 64 ),
					'payload' => [ 'entity_id' => 'entity-one' ],
				],
			],
			'bindings' => [
				[
					'field_id' => 'field-one',
					'entity_id' => 'entity-one',
					'adapter' => 'cct',
					'storage_key' => 'title',
					'aliases' => [ 'legacy_title', 'old_title' ],
					'migration' => null,
					'extension' => [ 'scope' => 'content' ],
				],
				[
					'field_id' => 'field-two',
					'entity_id' => 'entity-one',
					'adapter' => 'cct',
					'storage_key' => 'price',
					'aliases' => [],
					'migration' => [ 'transform' => 'integer_to_decimal' ],
				],
			],
		];
	}
}

class PublicationSnapshotReadState {
	public $component;
	public $error;
	public $events = [];
	public $finished = '';
	public $transitions = 0;
	public function __construct( string $component ) {
		$this->component = $component;
		$this->error = new WP_Error( 'eit_' . $component . '_runtime_read_failed', 'database unavailable' );
	}
}

class PublicationSnapshotArtifactReader {
	private $state;
	public function __construct( $state ) {
		$this->state = $state;
	}
	public function for_version_checked() {
		return 'artifacts' === $this->state->component ? $this->state->error : [];
	}
}

class PublicationSnapshotBindingReader {
	private $state;
	public function __construct( $state ) {
		$this->state = $state;
	}
	public function for_version_checked() {
		return 'bindings' === $this->state->component ? $this->state->error : [];
	}
}

class PublicationSnapshotRuns {
	private $state;
	public function __construct( $state ) {
		$this->state = $state;
	}
	public function start() {
		return [ 'id' => 'run-one' ];
	}
	public function finish( $id, $status ) {
		$this->state->finished = $status;
		return true;
	}
}

class PublicationSnapshotEvents {
	private $state;
	public function __construct( $state ) {
		$this->state = $state;
	}
	public function append( $id, $event ) {
		$this->state->events[] = $event;
		return true;
	}
}

class PublicationSnapshotReconciliations {
	private $state;
	public function __construct( $state ) {
		$this->state = $state;
	}
	public function record() {
		++$this->state->transitions;
		return true;
	}
}

class PublicationSnapshotReconcileLeaseState {
	public $renew_calls = 0;
	public $transitioned = false;
	public $released_storage = [];
	public $released_common = [];
}

class PublicationSnapshotReconcileChangeSets {
	private $state;
	public function __construct( $state ) {
		$this->state = $state;
	}
	public function get() {
		return [
			'id' => 'change-one',
			'blueprint_id' => PublicationSnapshotIntegrityContractTest::BLUEPRINT,
			'status' => 'applied',
			'draft_checksum' => str_repeat( 'a', 64 ),
			'impact' => [ 'migration_plan' => [ 'operations' => [] ] ],
			'compiled_artifacts' => [
				'artifacts' => [
					[
						'kind' => 'entity_definition',
						'payload' => [ 'strategy' => 'cct', 'definition' => [ 'slug' => 'items' ] ],
					],
				],
				'bindings' => [],
			],
		];
	}
	public function transition() {
		$this->state->transitioned = true;
		return true;
	}
}

class PublicationSnapshotReconcileBlueprints {
	public function get() {
		return [ 'active_version_id' => PublicationSnapshotIntegrityContractTest::VERSION ];
	}
}

class PublicationSnapshotReconcileVersions {
	public function get() {
		return [ 'checksum' => str_repeat( 'a', 64 ) ];
	}
}

class PublicationSnapshotReconcileLedger {
	public function validate() {
		return true;
	}
}

class PublicationSnapshotReconcileLocks {
	private $state;
	public function __construct( $state ) {
		$this->state = $state;
	}
	public function acquire( $resource ) {
		return 'token-' . $resource;
	}
	public function renew() {
		++$this->state->renew_calls;
		return new WP_Error( 'eit_lock_lost', 'The lock is no longer owned.' );
	}
	public function release( $resource ) {
		$this->state->released_common[] = $resource;
		return true;
	}
}

class PublicationSnapshotReconcileMigrationLocks {
	private $state;
	public function __construct( $state ) {
		$this->state = $state;
	}
	public function acquire_scopes() {
		return [ 'storage-cct-items' => 'storage-token' ];
	}
	public function release( array $leases ) {
		$this->state->released_storage = array_keys( $leases );
		return true;
	}
}
