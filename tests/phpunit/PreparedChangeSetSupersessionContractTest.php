<?php
/**
 * Explicit preparation supersedes stale compiler authority without mutating it.
 */

use EIT\Blueprint\CompilationResult;
use EIT\Blueprint\LifecycleService;
use PHPUnit\Framework\TestCase;

class PreparedChangeSetSupersessionContractTest extends TestCase {

	public function test_new_compiler_output_materializes_once_and_then_reuses_its_exact_authority(): void {
		$state = new PreparedSupersessionState( $this->snapshot( 'a' ) );
		$lifecycle = $this->lifecycle( $state, $this->snapshot( 'b' ) );

		$first = $lifecycle->prepare( 'blueprint-one', 7 );
		$second = $lifecycle->prepare( 'blueprint-one', 8 );

		self::assertFalse( is_wp_error( $first ) );
		self::assertFalse( is_wp_error( $second ) );
		self::assertNotSame( 'change-a', $first['id'] );
		self::assertSame( $first['id'], $second['id'] );
		self::assertNotSame( $first['confirmation_token'], $second['confirmation_token'] );
		self::assertSame( 1, $state->creates );
		self::assertSame( 1, $state->rotations );
		self::assertSame( 'prepared', $state->records['change-a']['status'] );
		self::assertSame( hash( 'sha256', $state->token_a ), $state->records['change-a']['confirmation_hash'] );

		$stale = $lifecycle->apply( 'change-a', $state->token_a, 7 );

		self::assertSame( 'eit_change_set_compiler_drift', $stale->get_error_code() );
		self::assertSame( 409, $stale->get_error_data()['status'] );
		self::assertSame( 'prepared', $state->records['change-a']['status'] );
		self::assertSame( 0, $state->transitions );
		self::assertSame( 0, $state->ownership_validations );
	}

	public function test_reserved_migration_plan_fails_closed_instead_of_superseding_its_authority(): void {
		$state = new PreparedSupersessionState( $this->snapshot( 'a' ) );
		$state->records['change-a']['impact']['migration_plan']['operations'] = [ [ 'id' => 'migration-a' ] ];
		$state->records['change-a']['impact']['summary']['migration_operations'] = 1;
		$original = $state->records['change-a'];
		$lifecycle = $this->lifecycle( $state, $this->snapshot( 'b' ) );

		$result = $lifecycle->prepare( 'blueprint-one', 7 );

		self::assertSame( 'eit_migration_plan_supersession_required', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( 'change-a', $result->get_error_data()['change_set_id'] );
		self::assertTrue( $result->get_error_data()['reprepare_required'] );
		self::assertSame( [ 'change-a' => $original ], $state->records );
		self::assertSame( 0, $state->creates );
		self::assertSame( 0, $state->rotations );
		self::assertSame( 0, $state->reservations );
	}

	private function lifecycle( PreparedSupersessionState $state, array $current ): LifecycleService {
		return new LifecycleService(
			[
				'compiler' => new PreparedSupersessionCompiler( $current ),
				'blueprints' => new PreparedSupersessionBlueprints(),
				'change_sets' => new PreparedSupersessionChangeSets( $state ),
				'impact_planner' => new PreparedSupersessionImpactPlanner(),
				'impact_map' => new PreparedSupersessionImpactMap(),
				'migration_guard' => new PreparedSupersessionMigrationGuard(),
				'ownership' => new PreparedSupersessionOwnership( $state ),
				'transaction' => new PreparedSupersessionTransaction(),
				'locks' => new PreparedSupersessionLocks(),
				'migration_ledger' => new PreparedSupersessionLedger(),
				'field_migrations' => new PreparedSupersessionFieldMigrations( $state ),
			]
		);
	}

	private function snapshot( string $seed ): array {
		return [
			'compiler_checksum' => str_repeat( $seed, 64 ),
			'artifacts' => [
				[
					'id' => 'artifact-one',
					'node_id' => 'entity-one',
					'kind' => 'entity_definition',
					'checksum' => str_repeat( $seed, 64 ),
					'payload' => [ 'definition' => [ 'slug' => 'items-' . $seed ] ],
				],
			],
			'bindings' => [
				[ 'field_id' => 'field-one', 'entity_id' => 'entity-one', 'adapter' => 'cct', 'storage_key' => 'title-' . $seed, 'aliases' => [] ],
			],
			'legacy_authority' => null,
		];
	}
}

class PreparedSupersessionState {
	public $token_a = 'prepared-a-token';
	public $records = [];
	public $creates = 0;
	public $rotations = 0;
	public $transitions = 0;
	public $ownership_validations = 0;
	public $reservations = 0;
	public function __construct( array $snapshot ) {
		$this->records['change-a'] = [
			'id' => 'change-a',
			'blueprint_id' => 'blueprint-one',
			'from_version_id' => null,
			'draft_checksum' => str_repeat( 'd', 64 ),
			'status' => 'prepared',
			'impact' => PreparedSupersessionImpactPlanner::impact() + [ 'map' => [] ],
			'compiled_artifacts' => $snapshot,
			'confirmation_hash' => hash( 'sha256', $this->token_a ),
			'created_by' => 1,
		];
	}
}

class PreparedSupersessionCompiler {
	private $snapshot;
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}
	public function compile() {
		return new CompilationResult(
			[
				'valid' => true,
				'checksum' => $this->snapshot['compiler_checksum'],
				'artifacts' => $this->snapshot['artifacts'],
				'bindings' => $this->snapshot['bindings'],
			]
		);
	}
}

class PreparedSupersessionBlueprints {
	public function get() {
		return [
			'id' => 'blueprint-one',
			'active_version_id' => null,
			'draft_checksum' => str_repeat( 'd', 64 ),
			'draft_document' => [ 'id' => 'blueprint-one', 'nodes' => [], 'connections' => [] ],
		];
	}
}

class PreparedSupersessionChangeSets {
	private $state;
	public function __construct( PreparedSupersessionState $state ) {
		$this->state = $state;
	}
	public function get( $id ) {
		return $this->state->records[ $id ] ?? null;
	}
	public function prepared_candidates_for_draft() {
		$prepared = array_filter( $this->state->records, fn( $record ) => 'prepared' === $record['status'] );
		return array_values( array_reverse( $prepared ) );
	}
	public function create( array $record ) {
		++$this->state->creates;
		$this->state->records[ $record['id'] ] = $record;
		return $record;
	}
	public function rotate_confirmation( $id, $expected, $next, $user_id ) {
		++$this->state->rotations;
		if ( ! hash_equals( $this->state->records[ $id ]['confirmation_hash'], $expected ) ) {
			return new WP_Error( 'rotation-conflict', 'Prepared authority changed.' );
		}
		$this->state->records[ $id ]['confirmation_hash'] = $next;
		$this->state->records[ $id ]['created_by'] = $user_id;
		return $this->state->records[ $id ];
	}
	public function transition( $id, $from, $to ) {
		++$this->state->transitions;
		$this->state->records[ $id ]['status'] = $to;
		return $this->state->records[ $id ];
	}
}

class PreparedSupersessionImpactPlanner {
	public function plan() {
		return self::impact();
	}
	public static function impact(): array {
		return [
			'blocked' => false,
			'blockers' => [],
			'artifacts' => [ 'added' => [ 'entity_definition|entity-one' ], 'changed' => [], 'removed' => [] ],
			'bindings' => [],
			'migration_plan' => [ 'operations' => [], 'blockers' => [] ],
			'affected_node_ids' => [ 'entity-one' ],
			'summary' => [ 'added' => 1, 'changed' => 0, 'removed' => 0, 'binding_changes' => 0, 'migration_operations' => 0 ],
		];
	}
}

class PreparedSupersessionImpactMap {
	public function build() {
		return [];
	}
}

class PreparedSupersessionMigrationGuard {
	public function validate() {
		return true;
	}
	public function validate_snapshot() {
		return true;
	}
	public function blockers() {
		return [];
	}
	public function snapshot() {
		return null;
	}
}

class PreparedSupersessionOwnership {
	private $state;
	public function __construct( PreparedSupersessionState $state ) {
		$this->state = $state;
	}
	public function blockers() {
		return [];
	}
	public function validate() {
		++$this->state->ownership_validations;
		return true;
	}
}

class PreparedSupersessionTransaction {
	public function run( callable $callback ) {
		return $callback();
	}
}

class PreparedSupersessionLocks {
	public function acquire( $resource ) {
		return 'token-' . $resource;
	}
	public function release() {
		return true;
	}
}

class PreparedSupersessionLedger {
	public function validate() {
		return true;
	}
}

class PreparedSupersessionFieldMigrations {
	private $state;
	public function __construct( PreparedSupersessionState $state ) {
		$this->state = $state;
	}
	public function reserve() {
		++$this->state->reservations;
		return [];
	}
}
