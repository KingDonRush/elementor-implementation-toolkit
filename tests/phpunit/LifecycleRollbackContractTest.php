<?php
/**
 * Focused rollback ordering and verification contracts.
 */

use EIT\Blueprint\CompilationResult;
use EIT\Blueprint\LifecycleService;
use PHPUnit\Framework\TestCase;

class LifecycleRollbackContractTest extends TestCase {

	public function test_rollback_verifies_target_before_prepare_and_audit(): void {
		$state = new LifecycleRollbackState();

		$result = $this->service( $state )->rollback( $state->blueprint_id, $state->target_version_id, 'Return to known runtime', 7 );

		self::assertSame( 'rollback-record', $result );
		self::assertSame( $state->target_version_id, $state->active_version_id );
		$this->assert_before( $state, 'lock:acquire:storage-ownership', 'lock:acquire:blueprint-bp-1' );
		$this->assert_before( $state, 'compiler:compile', 'ownership:validate' );
		$this->assert_before( $state, 'ownership:validate', 'runtime:prepare' );
		$this->assert_before( $state, 'blueprints:set-active', 'blueprints:get:3' );
		$this->assert_before( $state, 'artifacts:read:2', 'rollback:record' );
		self::assertSame( [ 'lock:release:blueprint-bp-1', 'lock:release:storage-ownership' ], $this->events_starting_with( $state, 'lock:release:' ) );
	}

	public function test_compiler_artifact_drift_fails_before_ownership_and_prepare(): void {
		$state = new LifecycleRollbackState();
		$state->compiled_artifacts[0]['checksum'] = 'compiled-drift';

		$result = $this->service( $state )->rollback( $state->blueprint_id, $state->target_version_id, 'Unsafe target', 7 );

		self::assertTrue( is_wp_error( $result ) );
		self::assertSame( 'eit_rollback_artifact_mismatch', $result->get_error_code() );
		self::assertNotContains( 'ownership:validate', $state->events );
		self::assertNotContains( 'runtime:prepare', $state->events );
		self::assertNotContains( 'rollback:record', $state->events );
		self::assertSame( [ 'lock:release:blueprint-bp-1', 'lock:release:storage-ownership' ], $this->events_starting_with( $state, 'lock:release:' ) );
	}

	public function test_ownership_conflict_fails_before_prepare_and_releases_both_locks(): void {
		$state = new LifecycleRollbackState();
		$state->ownership_error = new WP_Error( 'eit_storage_ownership_conflict', 'Owned elsewhere.' );

		$result = $this->service( $state )->rollback( $state->blueprint_id, $state->target_version_id, 'Conflicting target', 7 );

		self::assertSame( $state->ownership_error, $result );
		self::assertContains( 'ownership:validate', $state->events );
		self::assertNotContains( 'runtime:prepare', $state->events );
		self::assertNotContains( 'blueprints:set-active', $state->events );
		self::assertSame( [ 'lock:release:blueprint-bp-1', 'lock:release:storage-ownership' ], $this->events_starting_with( $state, 'lock:release:' ) );
	}

	public function test_unverified_active_pointer_prevents_audit_record(): void {
		$state = new LifecycleRollbackState();
		$state->persist_pointer = false;

		$result = $this->service( $state )->rollback( $state->blueprint_id, $state->target_version_id, 'Pointer failure', 7 );

		self::assertTrue( is_wp_error( $result ) );
		self::assertSame( 'eit_rollback_activation_mismatch', $result->get_error_code() );
		self::assertNotContains( 'rollback:record', $state->events );
		self::assertNotContains( 'cache:set', $state->events );
		self::assertSame( $state->from_version_id, $state->active_version_id );
		self::assertSame( [ 'lock:release:blueprint-bp-1', 'lock:release:storage-ownership' ], $this->events_starting_with( $state, 'lock:release:' ) );
	}

	public function test_post_activation_artifact_drift_prevents_audit_record(): void {
		$state = new LifecycleRollbackState();
		$state->drift_after_activation = true;

		$result = $this->service( $state )->rollback( $state->blueprint_id, $state->target_version_id, 'Artifact failure', 7 );

		self::assertTrue( is_wp_error( $result ) );
		self::assertSame( 'eit_rollback_artifact_mismatch', $result->get_error_code() );
		self::assertNotContains( 'rollback:record', $state->events );
		self::assertNotContains( 'cache:set', $state->events );
		self::assertSame( $state->from_version_id, $state->active_version_id );
		self::assertSame( [ 'lock:release:blueprint-bp-1', 'lock:release:storage-ownership' ], $this->events_starting_with( $state, 'lock:release:' ) );
	}

	public function test_blueprint_lock_failure_releases_global_lock(): void {
		$state = new LifecycleRollbackState();
		$state->lock_failure = 'blueprint-bp-1';

		$result = $this->service( $state )->rollback( $state->blueprint_id, $state->target_version_id, 'Lock failure', 7 );

		self::assertTrue( is_wp_error( $result ) );
		self::assertSame( [ 'lock:acquire:storage-ownership', 'lock:acquire:blueprint-bp-1', 'lock:release:storage-ownership' ], $this->events_starting_with( $state, 'lock:' ) );
		self::assertNotContains( 'run:start', $state->events );
	}

	public function test_exception_after_both_acquisitions_releases_both_locks(): void {
		$state = new LifecycleRollbackState();
		$state->compile_exception = true;

		try {
			$this->service( $state )->rollback( $state->blueprint_id, $state->target_version_id, 'Exceptional target', 7 );
			self::fail( 'Rollback compiler exception should escape the focused fake.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Compiler exploded.', $error->getMessage() );
		}

		self::assertSame( [ 'lock:release:blueprint-bp-1', 'lock:release:storage-ownership' ], $this->events_starting_with( $state, 'lock:release:' ) );
	}

	public function test_apply_exception_is_redacted_and_releases_both_long_lived_locks(): void {
		$state = (object) [ 'events' => [] ];
		$token = 'confirm-publication';
		$change_set = [
			'id' => 'change-1',
			'blueprint_id' => 'bp-1',
			'status' => 'prepared',
			'draft_checksum' => 'draft-checksum',
			'confirmation_hash' => hash( 'sha256', $token ),
			'compiled_artifacts' => [ 'artifacts' => [], 'bindings' => [], 'compiler_checksum' => 'compiler-checksum' ],
		];
		$change_sets = new class( $change_set ) {
			private $record;

			public function __construct( array $record ) {
				$this->record = $record;
			}

			public function get() {
				return $this->record;
			}

			public function transition() {
				return true;
			}
		};
		$blueprints = new class() {
			public function get() {
				return [ 'draft_checksum' => 'draft-checksum', 'draft_document' => [] ];
			}
		};
		$locks = new class( $state ) {
			private $state;

			public function __construct( $state ) {
				$this->state = $state;
			}

			public function acquire( $resource, $owner, $ttl ) {
				$this->state->events[] = "acquire:{$resource}:{$ttl}";
				return 'token-' . $resource;
			}

			public function release( $resource ) {
				$this->state->events[] = 'release:' . $resource;
				return true;
			}
		};
		$ownership = new class() {
			public function validate() {
				throw new RuntimeException( 'database-password-should-never-escape' );
			}
		};
		$service = new LifecycleService( [ 'change_sets' => $change_sets, 'blueprints' => $blueprints, 'locks' => $locks, 'ownership' => $ownership ] );

		$result = $service->apply( 'change-1', $token, 7 );

		self::assertSame( 'eit_blueprint_apply_exception', $result->get_error_code() );
		self::assertStringNotContainsString( 'database-password', $result->get_error_message() );
		self::assertSame(
			[
				'acquire:storage-ownership:900',
				'acquire:blueprint-bp-1:900',
				'release:blueprint-bp-1',
				'release:storage-ownership',
			],
			$state->events
		);
	}

	private function service( LifecycleRollbackState $state ): LifecycleService {
		$collaborators = new LifecycleRollbackCollaborators( $state );
		return new LifecycleService(
			[
				'blueprints' => new LifecycleRollbackBlueprints( $state ),
				'versions' => new LifecycleRollbackVersions( $state ),
				'artifacts' => $collaborators,
				'compiler' => $collaborators,
				'ownership' => $collaborators,
				'preparer' => $collaborators,
				'locks' => $collaborators,
				'runs' => $collaborators,
				'run_events' => $collaborators,
				'rollbacks' => $collaborators,
				'cache' => $collaborators,
				'transaction' => $collaborators,
			]
		);
	}

	private function assert_before( LifecycleRollbackState $state, string $first, string $second ): void {
		$first_position = array_search( $first, $state->events, true );
		$second_position = array_search( $second, $state->events, true );
		self::assertNotFalse( $first_position, implode( "\n", $state->events ) );
		self::assertNotFalse( $second_position, implode( "\n", $state->events ) );
		self::assertTrue( $first_position < $second_position, implode( "\n", $state->events ) );
	}

	private function events_starting_with( LifecycleRollbackState $state, string $prefix ): array {
		return array_values( array_filter( $state->events, fn( $event ) => 0 === strpos( $event, $prefix ) ) );
	}
}

class LifecycleRollbackState {
	public $blueprint_id = 'bp-1';
	public $from_version_id = 2;
	public $target_version_id = 1;
	public $active_version_id = 2;
	public $compiled_artifacts;
	public $stored_artifacts;
	public $ownership_error;
	public $prepare_error;
	public $lock_failure = '';
	public $persist_pointer = true;
	public $drift_after_activation = false;
	public $compile_exception = false;
	public $artifact_reads = 0;
	public $blueprint_reads = 0;
	public $events = [];

	public function __construct() {
		$this->stored_artifacts = [ [ 'id' => 'artifact-1', 'checksum' => 'checksum-1', 'kind' => 'entity_definition', 'payload' => [] ] ];
		$this->compiled_artifacts = $this->stored_artifacts;
	}
}

class LifecycleRollbackBlueprints {
	private $state;

	public function __construct( LifecycleRollbackState $state ) {
		$this->state = $state;
	}

	public function get( $blueprint_id ) {
		++$this->state->blueprint_reads;
		$this->state->events[] = 'blueprints:get:' . $this->state->blueprint_reads;
		return $blueprint_id === $this->state->blueprint_id ? [ 'id' => $blueprint_id, 'active_version_id' => $this->state->active_version_id ] : null;
	}

	public function set_active_version( $blueprint_id, $version_id ) {
		$this->state->events[] = 'blueprints:set-active';
		if ( $blueprint_id === $this->state->blueprint_id && $this->state->persist_pointer ) {
			$this->state->active_version_id = $version_id;
		}
		return true;
	}
}

class LifecycleRollbackVersions {
	private $state;

	public function __construct( LifecycleRollbackState $state ) {
		$this->state = $state;
	}

	public function get( $version_id ) {
		$this->state->events[] = 'versions:get';
		return $version_id === $this->state->target_version_id ? [ 'id' => $version_id, 'blueprint_id' => $this->state->blueprint_id, 'document' => [ 'id' => $this->state->blueprint_id ] ] : null;
	}
}

class LifecycleRollbackCollaborators {
	private $state;

	public function __construct( LifecycleRollbackState $state ) {
		$this->state = $state;
	}

	public function acquire( $resource ) {
		$this->state->events[] = 'lock:acquire:' . $resource;
		return $resource === $this->state->lock_failure ? new WP_Error( 'eit_blueprint_locked', 'Locked.' ) : 'token-' . $resource;
	}

	public function release( $resource ) {
		$this->state->events[] = 'lock:release:' . $resource;
		return true;
	}

	public function start() {
		$this->state->events[] = 'run:start';
		return [ 'id' => 'run-1' ];
	}

	public function finish( $id, $status ) {
		$this->state->events[] = 'run:finish:' . $status;
		return true;
	}

	public function for_version() {
		++$this->state->artifact_reads;
		$this->state->events[] = 'artifacts:read:' . $this->state->artifact_reads;
		$artifacts = $this->state->stored_artifacts;
		if ( $this->state->drift_after_activation && $this->state->artifact_reads > 1 ) {
			$artifacts[0]['checksum'] = 'post-activation-drift';
		}
		return $artifacts;
	}

	public function compile() {
		$this->state->events[] = 'compiler:compile';
		if ( $this->state->compile_exception ) {
			throw new RuntimeException( 'Compiler exploded.' );
		}
		return new CompilationResult( [ 'valid' => true, 'artifacts' => $this->state->compiled_artifacts ] );
	}

	public function validate() {
		$this->state->events[] = 'ownership:validate';
		return $this->state->ownership_error ?: true;
	}

	public function prepare() {
		$this->state->events[] = 'runtime:prepare';
		return $this->state->prepare_error ?: true;
	}

	public function run( callable $callback ) {
		$this->state->events[] = 'transaction:start';
		$previous = $this->state->active_version_id;
		$result = $callback();
		if ( is_wp_error( $result ) ) {
			$this->state->active_version_id = $previous;
		}
		$this->state->events[] = 'transaction:end';
		return $result;
	}

	public function record() {
		$this->state->events[] = 'rollback:record';
		return 'rollback-record';
	}

	public function set() {
		$this->state->events[] = 'cache:set';
		return true;
	}

	public function append() {
		$this->state->events[] = 'event:append';
		return 1;
	}
}
