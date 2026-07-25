<?php
/**
 * First-activation legacy rollback authority contracts.
 */

use EIT\Blueprint\LegacyRollbackService;
use EIT\Blueprint\LifecycleService;
use PHPUnit\Framework\TestCase;

class LegacyRollbackServiceContractTest extends TestCase {

	public function test_first_activation_restores_legacy_authority_under_all_gates(): void {
		$state = new LegacyRollbackState();

		$result = $this->service( $state )->rollback( $state->blueprint_id, 'Return to legacy', 7 );

		self::assertSame( 'rollback-id', $result );
		self::assertNull( $state->active_version_id );
		self::assertSame( 'rolled_back', $state->change_set_status );
		self::assertSame( [ 'from' => 9, 'to' => 0, 'reason' => 'Return to legacy' ], $state->rollback_record );
		self::assertSame( [ 'bp-legacy', 9 ], $state->cache_invalidated );
		self::assertTrue( $state->runtime_invalidated );
		self::assertSame(
			[ 'lock:storage-ownership', 'lock:blueprint-bp-legacy', 'lock:eit-write-cpt-legacy_records' ],
			array_values( array_filter( $state->events, fn( $event ) => str_starts_with( $event, 'lock:' ) ) )
		);
		$this->assert_before( $state, 'lock:eit-write-cpt-legacy_records', 'source:2' );
		$this->assert_before( $state, 'renew:storage-ownership', 'source:3' );
		$this->assert_before( $state, 'source:4', 'blueprint:deactivate:9' );
		self::assertSame(
			[ 'release:eit-write-cpt-legacy_records', 'release:blueprint-bp-legacy', 'release:storage-ownership' ],
			array_values( array_filter( $state->events, fn( $event ) => str_starts_with( $event, 'release:' ) ) )
		);
	}

	public function test_active_writer_is_drained_before_authority_can_be_revalidated(): void {
		$state = new LegacyRollbackState();
		$state->active_writers = [ 'eit-writer-cpt-legacy_records-request' ];

		$result = $this->service( $state )->rollback( $state->blueprint_id, 'Writer is active', 7 );

		self::assertSame( 'eit_migration_storage_locked', $result->get_error_code() );
		self::assertSame( 1, $state->source_reads );
		self::assertSame( 9, $state->active_version_id );
		self::assertNull( $state->rollback_record );
		self::assertContains( 'drain:eit-writer-cpt-legacy_records-', $state->events );
	}

	public function test_source_drift_after_storage_lock_fails_closed_before_cas(): void {
		$state = new LegacyRollbackState();
		$state->source_error_after_first = new WP_Error( 'eit_legacy_authority_source_changed', 'Source changed.' );

		$result = $this->service( $state )->rollback( $state->blueprint_id, 'Unsafe rollback', 7 );

		self::assertSame( 'eit_legacy_authority_source_changed', $result->get_error_code() );
		self::assertSame( 9, $state->active_version_id );
		self::assertNull( $state->rollback_record );
		self::assertNotContains( 'blueprint:deactivate:9', $state->events );
		self::assertSame( 3, count( array_filter( $state->events, fn( $event ) => str_starts_with( $event, 'release:' ) ) ) );
	}

	public function test_only_null_from_version_lineage_can_restore_legacy(): void {
		$state = new LegacyRollbackState();
		$state->from_version_id = 4;

		$result = $this->service( $state )->rollback( $state->blueprint_id, 'Too late', 7 );

		self::assertSame( 'eit_legacy_rollback_not_first_activation', $result->get_error_code() );
		self::assertEmpty( array_filter( $state->events, fn( $event ) => str_starts_with( $event, 'lock:' ) ) );
		self::assertSame( 9, $state->active_version_id );
	}

	public function test_native_blueprint_cannot_request_legacy_target(): void {
		$state = new LegacyRollbackState();
		$state->origin = 'native';

		$result = $this->service( $state )->rollback( $state->blueprint_id, 'Invalid target', 7 );

		self::assertSame( 'eit_legacy_rollback_unavailable', $result->get_error_code() );
		self::assertSame( 0, $state->source_reads );
	}

	public function test_source_without_toolkit_writer_gate_is_not_offered_or_restored(): void {
		$state = new LegacyRollbackState();
		$state->source_type = 'elementor_document';

		$service = $this->service( $state );
		$result = $service->availability( $state->blueprint_id );

		self::assertSame( 'eit_legacy_rollback_storage_unsupported', $result->get_error_code() );
		self::assertSame( 9, $state->active_version_id );
		self::assertEmpty( array_filter( $state->events, fn( $event ) => str_starts_with( $event, 'lock:' ) ) );
	}

	public function test_pointer_cas_failure_rolls_back_lineage_and_audit(): void {
		$state = new LegacyRollbackState();
		$state->cas_failure = true;

		$result = $this->service( $state )->rollback( $state->blueprint_id, 'Conflicting authority', 7 );

		self::assertSame( 'eit_blueprint_deactivation_stale', $result->get_error_code() );
		self::assertSame( 9, $state->active_version_id );
		self::assertSame( 'reconciled', $state->change_set_status );
		self::assertNull( $state->rollback_record );
		self::assertContains( 'run:finish:failed', $state->events );
	}

	public function test_newer_draft_does_not_replace_published_rollback_authority(): void {
		$state = new LegacyRollbackState();
		$state->draft_checksum = str_repeat( 'd', 64 );
		$state->draft_revision = 8;

		$result = $this->service( $state )->rollback( $state->blueprint_id, 'Keep newer draft', 7 );

		self::assertSame( 'rollback-id', $result );
		self::assertNull( $state->active_version_id );
		self::assertSame( str_repeat( 'a', 64 ), $state->snapshot_draft_checksum );
		self::assertContains( 'blueprint:cas-revision:8', $state->events );
	}

	public function test_mutable_evidence_and_current_compiler_are_not_consulted_during_rollback(): void {
		$state = new LegacyRollbackState();
		$state->mutable_evidence_changed = true;
		$state->current_compiler_checksum = str_repeat( 'f', 64 );

		$result = $this->service( $state )->rollback( $state->blueprint_id, 'Historical proof wins', 7 );

		self::assertSame( 'rollback-id', $result );
		self::assertSame( 0, $state->mutable_evidence_reads );
		self::assertNull( $state->active_version_id );
	}

	public function test_lifecycle_dispatches_zero_and_semantic_targets_without_version_lookup(): void {
		$legacy = new class() {
			public $targets = [];
			public function rollback( $blueprint_id, $reason, $user_id ) {
				$this->targets[] = [ $blueprint_id, $reason, $user_id ];
				return 'legacy-rollback';
			}
		};
		$service = new LifecycleService( [ 'legacy_rollback' => $legacy ] );

		self::assertSame( 'legacy-rollback', $service->rollback( 'bp', 0, 'Zero target', 2 ) );
		self::assertSame( 'legacy-rollback', $service->rollback( 'bp', 'legacy', 'Semantic target', 3 ) );
		self::assertSame( [ [ 'bp', 'Zero target', 2 ], [ 'bp', 'Semantic target', 3 ] ], $legacy->targets );
	}

	private function service( LegacyRollbackState $state ): LegacyRollbackService {
		$collaborators = new LegacyRollbackCollaborators( $state );
		return new LegacyRollbackService(
			[
				'blueprints' => $collaborators,
				'versions' => $collaborators,
				'change_sets' => $collaborators,
				'legacy_authority' => $collaborators,
				'source_commit' => $collaborators,
				'migration_ledger' => $collaborators,
				'locks' => $collaborators,
				'runs' => $collaborators,
				'run_events' => $collaborators,
				'rollbacks' => $collaborators,
				'cache' => $collaborators,
				'transaction' => $collaborators,
				'runtime_invalidator' => function () use ( $state ) {
					$state->runtime_invalidated = true;
					$state->events[] = 'runtime:invalidate';
				},
			]
		);
	}

	private function assert_before( LegacyRollbackState $state, string $first, string $second ): void {
		self::assertTrue( array_search( $first, $state->events, true ) < array_search( $second, $state->events, true ), implode( "\n", $state->events ) );
	}
}

class LegacyRollbackState {
	public $blueprint_id = 'bp-legacy';
	public $active_version_id = 9;
	public $draft_checksum;
	public $draft_revision = 3;
	public $snapshot_draft_checksum;
	public $source_checksum;
	public $source_type = 'cpt';
	public $origin = 'legacy_shadow';
	public $from_version_id = null;
	public $change_set_status = 'reconciled';
	public $source_reads = 0;
	public $source_error_after_first;
	public $mutable_evidence_changed = false;
	public $mutable_evidence_reads = 0;
	public $current_compiler_checksum;
	public $cas_failure = false;
	public $active_writers = [];
	public $rollback_record;
	public $cache_invalidated;
	public $runtime_invalidated = false;
	public $events = [];

	public function __construct() {
		$this->snapshot_draft_checksum = str_repeat( 'a', 64 );
		$this->draft_checksum = $this->snapshot_draft_checksum;
		$this->source_checksum = str_repeat( 'b', 64 );
		$this->current_compiler_checksum = str_repeat( 'c', 64 );
	}
}

class LegacyRollbackCollaborators {
	private $state;

	public function __construct( LegacyRollbackState $state ) {
		$this->state = $state;
	}

	public function get( $value ) {
		if ( $value === $this->state->blueprint_id ) {
			$this->state->events[] = 'blueprint:get';
			return [
				'id' => $value,
				'active_version_id' => $this->state->active_version_id,
				'draft_revision' => $this->state->draft_revision,
				'draft_checksum' => $this->state->draft_checksum,
				'draft_document' => [ 'id' => $value, 'origin' => [ 'mode' => 'native' ] ],
			];
		}
		return 9 === $value
			? [
				'id' => 9,
				'blueprint_id' => $this->state->blueprint_id,
				'checksum' => $this->state->snapshot_draft_checksum,
				'document' => [
					'id' => $this->state->blueprint_id,
					'checksum' => $this->state->snapshot_draft_checksum,
					'origin' => [ 'mode' => $this->state->origin ],
				],
			]
			: null;
	}

	public function validate() {
		$this->state->events[] = 'ledger:validate';
		return true;
	}

	public function published_for_checksum() {
		return [
			'id' => 'first-change',
			'status' => $this->state->change_set_status,
			'from_version_id' => $this->state->from_version_id,
			'compiled_artifacts' => [ 'legacy_authority' => $this->snapshot() ],
		];
	}

	public function validate_published_version( array $snapshot ) {
		$this->state->events[] = 'snapshot:validate';
		if ( 'legacy_shadow' !== $this->state->origin ) {
			return new WP_Error( 'eit_legacy_rollback_unavailable', 'Unavailable.' );
		}
		return [
			'snapshot' => $snapshot,
			'storage_scope' => [ 'strategy' => $snapshot['source_type'], 'storage_slug' => $snapshot['source_key'] ],
		];
	}

	public function validate_snapshot_and_lock() {
		++$this->state->source_reads;
		$this->state->events[] = 'source:' . $this->state->source_reads;
		if ( 1 < $this->state->source_reads && $this->state->source_error_after_first ) {
			return $this->state->source_error_after_first;
		}
		return true;
	}

	public function acquire( $resource ) {
		$this->state->events[] = 'lock:' . $resource;
		return 'lease-' . $resource;
	}

	public function release( $resource ) {
		$this->state->events[] = 'release:' . $resource;
		return true;
	}

	public function renew( $resource ) {
		$this->state->events[] = 'renew:' . $resource;
		return true;
	}

	public function active_with_prefix( $prefix ) {
		$this->state->events[] = 'drain:' . $prefix;
		return $this->state->active_writers;
	}

	public function start() {
		$this->state->events[] = 'run:start';
		return [ 'id' => 'run-id' ];
	}

	public function finish( $id, $status ) {
		$this->state->events[] = 'run:finish:' . $status;
		return true;
	}

	public function run( callable $callback ) {
		$active = $this->state->active_version_id;
		$status = $this->state->change_set_status;
		$record = $this->state->rollback_record;
		$result = $callback();
		if ( is_wp_error( $result ) ) {
			$this->state->active_version_id = $active;
			$this->state->change_set_status = $status;
			$this->state->rollback_record = $record;
		}
		return $result;
	}

	public function deactivate_if_current( $blueprint_id, $version_id, $checksum, $revision = null ) {
		$this->state->events[] = 'blueprint:deactivate:' . $version_id;
		$this->state->events[] = 'blueprint:cas-revision:' . $revision;
		if ( $this->state->cas_failure || $blueprint_id !== $this->state->blueprint_id || $version_id !== $this->state->active_version_id || $checksum !== $this->state->draft_checksum || $revision !== $this->state->draft_revision ) {
			return new WP_Error( 'eit_blueprint_deactivation_stale', 'Stale.' );
		}
		$this->state->active_version_id = null;
		return true;
	}

	public function transition( $id, $from, $to ) {
		$this->state->events[] = 'lineage:' . $to;
		$this->state->change_set_status = $to;
		return [ 'id' => $id, 'status' => $to ];
	}

	public function record( $blueprint_id, $from, $to, $reason ) {
		$this->state->events[] = 'rollback:record';
		$this->state->rollback_record = [ 'from' => $from, 'to' => $to, 'reason' => $reason ];
		return 'rollback-id';
	}

	public function invalidate( $blueprint_id, $version_id ) {
		$this->state->events[] = 'cache:invalidate';
		$this->state->cache_invalidated = [ $blueprint_id, $version_id ];
	}

	public function append() {
		$this->state->events[] = 'run:event';
		return 1;
	}

	private function snapshot() {
		return [
			'source_type' => $this->state->source_type,
			'source_key' => 'legacy_records',
			'source_checksum' => $this->state->source_checksum,
			'draft_checksum' => $this->state->snapshot_draft_checksum,
			'version_checksum' => $this->state->snapshot_draft_checksum,
			'blueprint_id' => $this->state->blueprint_id,
			'authority_checksum' => str_repeat( 'e', 64 ),
		];
	}
}
