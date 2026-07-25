<?php
/**
 * Prepared compiler authority must remain exact until locked publication.
 */

use EIT\Blueprint\CompilationResult;
use EIT\Blueprint\LifecycleService;
use EIT\Blueprint\PreparedCompilationFreshness;
use PHPUnit\Framework\TestCase;

class PreparedCompilationFreshnessContractTest extends TestCase {

	public function test_equal_complete_sets_remain_fresh_independent_of_record_and_map_order(): void {
		$prepared = $this->snapshot();
		$current_artifacts = array_reverse( $prepared['artifacts'] );
		$current_artifacts[1] = [
			'payload' => [
				'definition' => [ 'table' => 'items', 'slug' => 'items' ],
				'adapter' => [ 'version' => '1.0.0', 'id' => 'cct' ],
			],
			'checksum' => $prepared['artifacts'][0]['checksum'],
			'kind' => 'entity_definition',
			'node_id' => 'entity-one',
			'id' => 'artifact-one',
		];
		$current_bindings = array_reverse( $prepared['bindings'] );
		$current_bindings[1] = [
			'aliases' => [ 'legacy_title' ],
			'storage_key' => 'title',
			'adapter' => 'cct',
			'entity_id' => 'entity-one',
			'field_id' => 'field-one',
		];
		$compiler = new PreparedFreshnessCompiler(
			$this->compilation_result( $prepared['compiler_checksum'], $current_artifacts, $current_bindings )
		);

		$result = ( new PreparedCompilationFreshness( $compiler ) )->validate( $this->change_set( $prepared ), $this->blueprint() );

		self::assertTrue( $result );
		self::assertSame( 1, $compiler->calls );
	}

	public function test_compiler_checksum_drift_is_an_explicit_conflict(): void {
		$prepared = $this->snapshot();
		$compiler = new PreparedFreshnessCompiler(
			$this->compilation_result( str_repeat( 'd', 64 ), $prepared['artifacts'], $prepared['bindings'] )
		);

		$result = ( new PreparedCompilationFreshness( $compiler ) )->validate( $this->change_set( $prepared ), $this->blueprint() );

		$this->assert_conflict( $result, 'eit_change_set_compiler_drift', 'compiler_checksum' );
	}

	public function test_artifact_payload_drift_is_rejected_even_when_identity_checksum_and_count_match(): void {
		$prepared = $this->snapshot();
		$current = $prepared['artifacts'];
		$current[0]['payload']['definition']['slug'] = 'drifted-items';
		$compiler = new PreparedFreshnessCompiler(
			$this->compilation_result( $prepared['compiler_checksum'], $current, $prepared['bindings'] )
		);

		$result = ( new PreparedCompilationFreshness( $compiler ) )->validate( $this->change_set( $prepared ), $this->blueprint() );

		$this->assert_conflict( $result, 'eit_change_set_artifact_drift', 'artifact' );
		self::assertSame( 'content_mismatch', $result->get_error_data()['reason'] );
	}

	public function test_binding_payload_drift_is_rejected_even_when_field_identity_and_count_match(): void {
		$prepared = $this->snapshot();
		$current = $prepared['bindings'];
		$current[0]['storage_key'] = 'title_v2';
		$compiler = new PreparedFreshnessCompiler(
			$this->compilation_result( $prepared['compiler_checksum'], $prepared['artifacts'], $current )
		);

		$result = ( new PreparedCompilationFreshness( $compiler ) )->validate( $this->change_set( $prepared ), $this->blueprint() );

		$this->assert_conflict( $result, 'eit_change_set_binding_drift', 'binding' );
		self::assertSame( 'content_mismatch', $result->get_error_data()['reason'] );
	}

	public function test_invalid_current_compilation_fails_closed_as_a_conflict(): void {
		$prepared = $this->snapshot();
		$compiler = new PreparedFreshnessCompiler(
			new CompilationResult( [ 'errors' => [ [ 'code' => 'extension_unavailable' ] ] ] )
		);

		$result = ( new PreparedCompilationFreshness( $compiler ) )->validate( $this->change_set( $prepared ), $this->blueprint() );

		$this->assert_conflict( $result, 'eit_change_set_recompile_failed', 'compiler' );
	}

	public function test_lifecycle_uses_its_injected_compiler_under_both_locks_before_ownership(): void {
		$prepared = $this->snapshot();
		$state = new PreparedFreshnessApplyState( $prepared );
		$compiler = new PreparedFreshnessCompiler(
			$this->compilation_result( $prepared['compiler_checksum'], $prepared['artifacts'], $prepared['bindings'] ),
			$state
		);
		$state->ownership_result = new WP_Error( 'after-freshness', 'Stop after proving the gate order.' );

		$result = $this->lifecycle( $state, $compiler )->apply( 'change-one', $state->token, 7 );

		self::assertSame( $state->ownership_result, $result );
		self::assertSame( 1, $compiler->calls );
		self::assertLessThan( array_search( 'compiler:compile', $state->events, true ), array_search( 'lock:blueprint-blueprint-one', $state->events, true ) );
		self::assertLessThan( array_search( 'ownership:validate', $state->events, true ), array_search( 'compiler:compile', $state->events, true ) );
		self::assertSame( 0, $state->transitions );
	}

	public function test_apply_lease_loss_after_storage_gates_stops_before_ownership_and_transition(): void {
		$prepared = $this->snapshot();
		$state = new PreparedFreshnessApplyState( $prepared );
		$state->renew_failure_at = 1;
		$compiler = new PreparedFreshnessCompiler(
			$this->compilation_result( $prepared['compiler_checksum'], $prepared['artifacts'], $prepared['bindings'] ),
			$state
		);

		$result = $this->lifecycle( $state, $compiler )->apply( 'change-one', $state->token, 7 );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'eit_lifecycle_lease_lost', $result->get_error_code() );
		self::assertSame( 1, $state->renew_calls );
		self::assertNotContains( 'ownership:validate', $state->events, true );
		self::assertSame( 0, $state->transitions );
	}

	public function test_prepared_and_recovering_change_sets_do_not_transition_or_mutate_on_drift(): void {
		foreach ( [ 'prepared', 'applying' ] as $status ) {
			$prepared = $this->snapshot();
			$state = new PreparedFreshnessApplyState( $prepared );
			$state->status = $status;
			$compiler = new PreparedFreshnessCompiler(
				$this->compilation_result( str_repeat( 'e', 64 ), $prepared['artifacts'], $prepared['bindings'] ),
				$state
			);

			$result = $this->lifecycle( $state, $compiler )->apply( 'change-one', $state->token, 7 );

			$this->assert_conflict( $result, 'eit_change_set_compiler_drift', 'compiler_checksum' );
			self::assertSame( $status, $state->status );
			self::assertSame( 0, $state->transitions );
			self::assertNotContains( 'ownership:validate', $state->events, true );
			self::assertNotContains( 'claims:write', $state->events, true );
			self::assertNotContains( 'migration:acquire', $state->events, true );
			self::assertSame( [ 'release:blueprint-blueprint-one', 'release:storage-ownership' ], $state->releases() );
		}
	}

	public function test_applying_authority_drift_preserves_recovery_state_and_fence_lineage(): void {
		$prepared = $this->snapshot();
		$state = new PreparedFreshnessApplyState( $prepared );
		$state->status = 'applying';
		$state->draft_checksum = str_repeat( 'd', 64 );
		$compiler = new PreparedFreshnessCompiler(
			$this->compilation_result( $prepared['compiler_checksum'], $prepared['artifacts'], $prepared['bindings'] ),
			$state
		);

		$result = $this->lifecycle( $state, $compiler )->apply( 'change-one', $state->token, 7 );

		self::assertSame( 'eit_change_set_stale', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertTrue( $result->get_error_data()['recovery_required'] );
		self::assertSame( 'applying', $state->status );
		self::assertSame( 0, $state->transitions );
		self::assertSame( 0, $compiler->calls );
		self::assertNotContains( 'ownership:validate', $state->events, true );
	}

	private function lifecycle( PreparedFreshnessApplyState $state, PreparedFreshnessCompiler $compiler ): LifecycleService {
		return new LifecycleService(
			[
				'compiler' => $compiler,
				'blueprints' => new PreparedFreshnessBlueprints( $state ),
				'change_sets' => new PreparedFreshnessChangeSets( $state ),
				'locks' => new PreparedFreshnessLocks( $state ),
				'migration_guard' => new PreparedFreshnessMigrationGuard( $state ),
				'migration_ledger' => new PreparedFreshnessLedger(),
				'ownership' => new PreparedFreshnessOwnership( $state ),
				'claims' => new PreparedFreshnessClaims( $state ),
				'migration_locks' => new PreparedFreshnessMigrationLocks( $state ),
			]
		);
	}

	private function compilation_result( string $checksum, array $artifacts, array $bindings ): CompilationResult {
		return new CompilationResult(
			[
				'valid' => true,
				'checksum' => $checksum,
				'artifacts' => $artifacts,
				'bindings' => $bindings,
			]
		);
	}

	private function blueprint(): array {
		return [
			'id' => 'blueprint-one',
			'active_version_id' => null,
			'draft_checksum' => str_repeat( 'a', 64 ),
			'draft_document' => [ 'id' => 'blueprint-one', 'nodes' => [], 'connections' => [] ],
		];
	}

	private function change_set( array $snapshot ): array {
		return [
			'id' => 'change-one',
			'blueprint_id' => 'blueprint-one',
			'from_version_id' => null,
			'draft_checksum' => str_repeat( 'a', 64 ),
			'status' => 'prepared',
			'compiled_artifacts' => $snapshot,
		];
	}

	private function snapshot(): array {
		return [
			'compiler_checksum' => str_repeat( 'c', 64 ),
			'artifacts' => [
				[
					'id' => 'artifact-one',
					'node_id' => 'entity-one',
					'kind' => 'entity_definition',
					'checksum' => str_repeat( '1', 64 ),
					'payload' => [
						'adapter' => [ 'id' => 'cct', 'version' => '1.0.0' ],
						'definition' => [ 'slug' => 'items', 'table' => 'items' ],
					],
				],
				[
					'id' => 'artifact-two',
					'node_id' => 'route-one',
					'kind' => 'route_contract',
					'checksum' => str_repeat( '2', 64 ),
					'payload' => [ 'path' => 'items/{id}', 'public' => true ],
				],
			],
			'bindings' => [
				[ 'field_id' => 'field-one', 'entity_id' => 'entity-one', 'adapter' => 'cct', 'storage_key' => 'title', 'aliases' => [ 'legacy_title' ] ],
				[ 'field_id' => 'field-two', 'entity_id' => 'entity-one', 'adapter' => 'cct', 'storage_key' => 'price', 'aliases' => [] ],
			],
		];
	}

	private function assert_conflict( $error, string $code, string $component ): void {
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( $code, $error->get_error_code() );
		self::assertSame( 409, $error->get_error_data()['status'] );
		self::assertSame( $component, $error->get_error_data()['component'] );
	}
}

class PreparedFreshnessCompiler {
	private $result;
	private $state;
	public $calls = 0;
	public function __construct( CompilationResult $result, $state = null ) {
		$this->result = $result;
		$this->state = $state;
	}
	public function compile() {
		++$this->calls;
		if ( $this->state ) {
			$this->state->events[] = 'compiler:compile';
		}
		return $this->result;
	}
}

class PreparedFreshnessApplyState {
	public $token = 'freshness-token';
	public $status = 'prepared';
	public $events = [];
	public $transitions = 0;
	public $snapshot;
	public $draft_checksum;
	public $ownership_result = true;
	public $renew_calls = 0;
	public $renew_failure_at = 0;
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
		$this->draft_checksum = str_repeat( 'a', 64 );
	}
	public function releases(): array {
		return array_values( array_filter( $this->events, fn( $event ) => str_starts_with( $event, 'release:' ) ) );
	}
}

class PreparedFreshnessChangeSets {
	private $state;
	public function __construct( PreparedFreshnessApplyState $state ) {
		$this->state = $state;
	}
	public function get() {
		$this->state->events[] = 'change-set:get';
		return [
			'id' => 'change-one',
			'blueprint_id' => 'blueprint-one',
			'from_version_id' => null,
			'draft_checksum' => str_repeat( 'a', 64 ),
			'status' => $this->state->status,
			'confirmation_hash' => hash( 'sha256', $this->state->token ),
			'compiled_artifacts' => $this->state->snapshot,
			'impact' => [ 'migration_plan' => [ 'operations' => [] ] ],
		];
	}
	public function transition( $id, $from, $to ) {
		++$this->state->transitions;
		$this->state->status = $to;
		$this->state->events[] = 'change-set:' . $from . ':' . $to;
		return true;
	}
}

class PreparedFreshnessBlueprints {
	private $state;
	public function __construct( PreparedFreshnessApplyState $state ) {
		$this->state = $state;
	}
	public function get() {
		$this->state->events[] = 'blueprint:get';
		return [
			'id' => 'blueprint-one',
			'active_version_id' => null,
			'draft_checksum' => $this->state->draft_checksum,
			'draft_document' => [ 'id' => 'blueprint-one', 'nodes' => [], 'connections' => [] ],
		];
	}
}

class PreparedFreshnessLocks {
	private $state;
	public function __construct( PreparedFreshnessApplyState $state ) {
		$this->state = $state;
	}
	public function acquire( $resource ) {
		$this->state->events[] = 'lock:' . $resource;
		return 'token-' . $resource;
	}
	public function release( $resource ) {
		$this->state->events[] = 'release:' . $resource;
		return true;
	}
	public function renew( $resource ) {
		$this->state->events[] = 'renew:' . $resource;
		++$this->state->renew_calls;
		if ( $this->state->renew_failure_at === $this->state->renew_calls ) {
			return new WP_Error( 'eit_lock_lost', 'The lock is no longer owned.' );
		}
		return true;
	}
}

class PreparedFreshnessMigrationGuard {
	private $state;
	public function __construct( PreparedFreshnessApplyState $state ) {
		$this->state = $state;
	}
	public function validate() {
		$this->state->events[] = 'migration:validate';
		return true;
	}
	public function blockers() {
		return [];
	}
}

class PreparedFreshnessLedger {
	public function validate() {
		return true;
	}
}

class PreparedFreshnessOwnership {
	private $state;
	public function __construct( PreparedFreshnessApplyState $state ) {
		$this->state = $state;
	}
	public function validate() {
		$this->state->events[] = 'ownership:validate';
		return $this->state->ownership_result;
	}
}

class PreparedFreshnessClaims {
	private $state;
	public function __construct( PreparedFreshnessApplyState $state ) {
		$this->state = $state;
	}
	public function claim_many() {
		$this->state->events[] = 'claims:write';
		return [];
	}
}

class PreparedFreshnessMigrationLocks {
	private $state;
	public function __construct( PreparedFreshnessApplyState $state ) {
		$this->state = $state;
	}
	public function acquire() {
		$this->state->events[] = 'migration:acquire';
		return [];
	}
	public function acquire_scopes() {
		$this->state->events[] = 'storage:acquire';
		return [];
	}
	public function release() {
		return true;
	}
}
