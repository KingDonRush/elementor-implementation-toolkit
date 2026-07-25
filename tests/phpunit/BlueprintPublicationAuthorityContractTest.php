<?php
/**
 * Publication freshness, migration evidence and global Node identity contracts.
 */

use EIT\Blueprint\LifecycleService;
use EIT\Blueprint\MigrationPublicationGuard;
use EIT\Blueprint\PublicationAuthority;
use EIT\Blueprint\StorageOwnershipValidator;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\StorageClaimStore;
use PHPUnit\Framework\TestCase;

class BlueprintPublicationAuthorityContractTest extends TestCase {

	public function test_verified_import_allows_only_its_exact_storage_handoff(): void {
		$blueprint = $this->imported_blueprint();
		$record = $this->verified_record();
		$guard = $this->migration_guard( [ $record ], $this->candidate() );

		self::assertSame( $record, $guard->validate( $blueprint ) );
		$edited = $blueprint;
		$edited['draft_document']['origin']['mode'] = 'native';
		$edited['draft_checksum'] = str_repeat( 'd', 64 );
		self::assertSame( 'eit_migration_draft_changed', $guard->validate( $edited )->get_error_code(), 'Mutable origin metadata cannot bypass durable migration evidence.' );
		self::assertTrue( $guard->allows_handoff( $blueprint, [ 'strategy' => 'cpt', 'slug' => 'legacy_records' ] ) );
		self::assertFalse( $guard->allows_handoff( $blueprint, [ 'strategy' => 'cct', 'slug' => 'legacy_records' ] ) );
		self::assertFalse( $guard->allows_handoff( $blueprint, [ 'strategy' => 'cpt', 'slug' => 'other_records' ] ) );
	}

	public function test_imported_publication_fails_closed_when_any_evidence_dimension_drifts(): void {
		$blueprint = $this->imported_blueprint();
		$record = $this->verified_record();
		$candidate = $this->candidate();
		$cases = [
			'missing evidence' => [ [], $candidate, 'eit_migration_evidence_missing' ],
			'ambiguous evidence' => [ [ $record, $record ], $candidate, 'eit_migration_evidence_ambiguous' ],
			'unverified comparison' => [ [ array_replace( $record, [ 'status' => 'mismatch' ] ) ], $candidate, 'eit_migration_comparison_unverified' ],
			'stale authority evidence' => [ [ array_replace_recursive( $record, [ 'comparison' => [ 'verification_scope' => 'compiled_projection' ] ] ) ], $candidate, 'eit_migration_evidence_stale' ],
			'draft changed' => [ [ array_replace( $record, [ 'draft_checksum' => str_repeat( 'd', 64 ) ] ) ], $candidate, 'eit_migration_draft_changed' ],
			'source missing' => [ [ $record ], null, 'eit_migration_source_missing' ],
			'source changed' => [ [ $record ], array_replace( $candidate, [ 'source_checksum' => str_repeat( 's', 64 ) ] ), 'eit_migration_source_changed' ],
			'projection changed' => [ [ $record ], array_replace_recursive( $candidate, [ 'blueprint' => [ 'checksum' => str_repeat( 'p', 64 ) ] ] ), 'eit_migration_projection_changed' ],
		];

		foreach ( $cases as $label => [ $records, $current_candidate, $expected_code ] ) {
			$result = $this->migration_guard( $records, $current_candidate )->validate( $blueprint );
			self::assertTrue( is_wp_error( $result ), $label );
			self::assertSame( $expected_code, $result->get_error_code(), $label );
		}
		$compiler_changed = $this->migration_guard( [ $record ], $candidate, str_repeat( 'x', 64 ) )->validate( $blueprint );
		self::assertSame( 'eit_migration_compiler_changed', $compiler_changed->get_error_code() );
	}

	public function test_apply_authority_must_match_the_evidence_frozen_during_prepare(): void {
		$blueprint = $this->imported_blueprint();
		$record = $this->verified_record();
		$guard = $this->migration_guard( [ $record ], $this->candidate() );
		$snapshot = $guard->snapshot( $blueprint );

		self::assertIsArray( $snapshot );
		self::assertTrue( $guard->validate_snapshot( $blueprint, $snapshot ) );

		$changed_record = array_replace( $record, [ 'source_checksum' => str_repeat( 'e', 64 ) ] );
		$changed_candidate = array_replace( $this->candidate(), [ 'source_checksum' => str_repeat( 'e', 64 ) ] );
		$changed = $this->migration_guard( [ $changed_record ], $changed_candidate )->validate_snapshot( $blueprint, $snapshot );
		self::assertSame( 'eit_legacy_authority_evidence_changed', $changed->get_error_code() );
	}

	public function test_external_legacy_storage_requires_verified_matching_handoff(): void {
		$blueprint = $this->imported_blueprint();
		$blueprints = new PublicationBlueprintStore( [ $blueprint ] );
		$artifacts = new PublicationArtifactStore( [] );
		$artifact = $this->entity_artifact( 'entity-one', 'legacy_records' );
		$verified = new PublicationExternalOwnershipValidator( $blueprints, $artifacts, $this->migration_guard( [ $this->verified_record() ], $this->candidate() ) );

		self::assertNotContains( 'storage_identity_external', array_column( $verified->blockers( 'blueprint-one', [ $artifact ] ), 'code' ) );

		$mismatched = $this->verified_record();
		$mismatched['source_key'] = 'other_records';
		$blocked = new PublicationExternalOwnershipValidator( $blueprints, $artifacts, $this->migration_guard( [ $mismatched ], $this->candidate() ) );
		self::assertContains( 'storage_identity_external', array_column( $blocked->blockers( 'blueprint-one', [ $artifact ] ), 'code' ) );
	}

	public function test_every_compiled_node_uuid_is_owned_globally(): void {
		$blueprints = new PublicationBlueprintStore( [ [ 'id' => 'owner-blueprint', 'active_version_id' => 7 ] ] );
		$artifacts = new PublicationArtifactStore(
			[
				7 => [ [ 'kind' => 'collection_contract', 'node_id' => 'shared-node', 'payload' => [] ] ],
			]
		);
		$proposed = [ [ 'kind' => 'presentation_contract', 'node_id' => 'shared-node', 'payload' => [] ] ];

		$blockers = ( new StorageOwnershipValidator( $blueprints, $artifacts ) )->blockers( 'contender-blueprint', $proposed );

		self::assertContains( 'node_identity_owned', array_column( $blockers, 'code' ) );
		self::assertSame( 'owner-blueprint', $blockers[0]['owner']['blueprint_id'] );
	}

	public function test_apply_reloads_and_invalidates_pointer_or_draft_drift_after_locks(): void {
		foreach ( [ 'pointer', 'draft' ] as $drift ) {
			$state = new PublicationApplyState();
			if ( 'pointer' === $drift ) {
				$state->active_version_id = 12;
			} else {
				$state->draft_checksum = str_repeat( 'd', 64 );
			}
			$result = $this->lifecycle( $state )->apply( 'change-one', $state->token, 9 );

			self::assertSame( 'eit_change_set_stale', $result->get_error_code(), $drift );
			self::assertSame( 'failed', $state->status, $drift );
			self::assertNotContains( 'ownership:validate', $state->events, true );
			self::assertTrue( array_search( 'lock:blueprint-blueprint-one', $state->events, true ) < array_search( 'blueprint:get', $state->events, true ), $drift );
			self::assertSame( [ 'release:blueprint-blueprint-one', 'release:storage-ownership' ], array_values( array_filter( $state->events, fn( $event ) => str_starts_with( $event, 'release:' ) ) ) );
		}
	}

	public function test_apply_invalidates_verified_plan_when_migration_becomes_unverified(): void {
		$state = new PublicationApplyState();
		$state->migration_error = new WP_Error( 'eit_migration_source_changed', 'Source drifted.' );

		$result = $this->lifecycle( $state )->apply( 'change-one', $state->token, 9 );

		self::assertSame( 'eit_migration_source_changed', $result->get_error_code() );
		self::assertSame( 'failed', $state->status );
		self::assertNotContains( 'ownership:validate', $state->events, true );
	}

	public function test_same_confirmed_applying_change_set_remains_recoverable_under_lock(): void {
		$state = new PublicationApplyState();
		$state->status = 'applying';
		$authority = new PublicationAuthority( new PublicationApplyBlueprints( $state ), new PublicationApplyChangeSets( $state ), new PublicationApplyMigrationGuard( $state ) );

		$authorized = $authority->authorize_locked( 'change-one', $state->token, 'blueprint-one' );

		self::assertFalse( is_wp_error( $authorized ) );
		self::assertSame( 'applying', $authorized['change_set']['status'] );
		self::assertSame( 'applying', $state->status );
		self::assertNotContains( 'change-set:applying:failed', $state->events, true );
	}

	public function test_durable_claim_allows_only_its_exact_blueprint_to_recover_storage(): void {
		$blueprint = $this->imported_blueprint();
		$blueprints = new PublicationBlueprintStore( [ $blueprint ] );
		$artifacts = new PublicationArtifactStore( [] );
		$guard = $this->migration_guard( [ $this->verified_record() ], $this->candidate() );
		$artifact = $this->entity_artifact( 'entity-one', 'legacy_records' );
		$own_claim = new PublicationStorageClaims( [ 'blueprint_id' => 'blueprint-one', 'status' => 'prepared' ] );
		$foreign_claim = new PublicationStorageClaims( [ 'blueprint_id' => 'foreign-blueprint', 'status' => 'failed' ] );

		$own_codes = array_column( ( new PublicationExternalOwnershipValidator( $blueprints, $artifacts, $guard, $own_claim ) )->blockers( 'blueprint-one', [ $artifact ] ), 'code' );
		$foreign_codes = array_column( ( new PublicationExternalOwnershipValidator( $blueprints, $artifacts, $guard, $foreign_claim ) )->blockers( 'blueprint-one', [ $artifact ] ), 'code' );

		self::assertNotContains( 'storage_identity_external', $own_codes );
		self::assertNotContains( 'storage_identity_claimed', $own_codes );
		self::assertContains( 'storage_identity_claimed', $foreign_codes );
	}

	private function lifecycle( PublicationApplyState $state ): LifecycleService {
		return new LifecycleService(
			[
				'blueprints' => new PublicationApplyBlueprints( $state ),
				'change_sets' => new PublicationApplyChangeSets( $state ),
				'locks' => new PublicationApplyLocks( $state ),
				'migration_guard' => new PublicationApplyMigrationGuard( $state ),
				'migration_ledger' => new PublicationApplyLedger(),
				'ownership' => new PublicationApplyOwnership( $state ),
			]
		);
	}

	private function migration_guard( array $records, $candidate, ?string $compiler_checksum = null ): MigrationPublicationGuard {
		return new MigrationPublicationGuard( new PublicationMigrationRecords( $records ), new PublicationLegacyCandidate( $candidate ), new PublicationLegacyCompiler( $compiler_checksum ?: str_repeat( 'c', 64 ) ) );
	}

	private function imported_blueprint(): array {
		return [
			'id' => 'blueprint-one',
			'draft_checksum' => str_repeat( 'a', 64 ),
			'draft_document' => [ 'origin' => [ 'mode' => 'legacy_shadow' ] ],
		];
	}

	private function verified_record(): array {
		$contract_checksum = str_repeat( 'd', 64 );
		return [
			'blueprint_id' => 'blueprint-one',
			'source_type' => 'cpt',
			'source_key' => 'legacy_records',
			'source_checksum' => str_repeat( 'b', 64 ),
			'draft_checksum' => str_repeat( 'a', 64 ),
			'status' => 'verified',
			'comparison' => [
				'status' => 'verified',
				'verification_scope' => 'independent_authority_projection',
				'compiler_checksum' => str_repeat( 'c', 64 ),
				'authorities' => [
					'legacy' => [ 'authority' => 'legacy_option', 'available' => true, 'contract_checksum' => $contract_checksum ],
					'candidate' => [ 'authority' => 'compiled_candidate_artifact', 'available' => true, 'contract_checksum' => $contract_checksum ],
				],
				'checks' => [
					'semantic_contract_checksum' => [ 'legacy' => $contract_checksum, 'shadow' => $contract_checksum, 'match' => true ],
					'capability_downgrades' => [ 'legacy' => [], 'shadow' => [], 'match' => true ],
				],
			],
		];
	}

	private function candidate(): array {
		return [
			'source_checksum' => str_repeat( 'b', 64 ),
			'blueprint' => [ 'id' => 'blueprint-one', 'checksum' => str_repeat( 'a', 64 ) ],
		];
	}

	private function entity_artifact( string $node_id, string $slug ): array {
		return [
			'kind' => 'entity_definition',
			'node_id' => $node_id,
			'payload' => [ 'entity_id' => $node_id, 'strategy' => 'cpt', 'adapter' => [ 'id' => 'cpt' ], 'definition' => [ 'slug' => $slug ], 'fields' => [] ],
		];
	}
}

class PublicationMigrationRecords {
	private $records;
	public function __construct( array $records ) {
		$this->records = $records;
	}
	public function for_blueprint() {
		return $this->records;
	}
}

class PublicationLegacyCandidate {
	private $candidate;
	public function __construct( $candidate ) {
		$this->candidate = $candidate;
	}
	public function candidate() {
		return $this->candidate;
	}
}

class PublicationLegacyCompiler {
	private $checksum;
	public function __construct( string $checksum ) {
		$this->checksum = $checksum;
	}
	public function compile() {
		return new PublicationCompiledCandidate( $this->checksum );
	}
}

class PublicationCompiledCandidate {
	private $checksum;
	public function __construct( string $checksum ) {
		$this->checksum = $checksum;
	}
	public function is_valid() {
		return true;
	}
	public function checksum() {
		return $this->checksum;
	}
}

class PublicationBlueprintStore extends BlueprintStore {
	private $records;
	public function __construct( array $records ) {
		$this->records = $records;
	}
	public function all() {
		return $this->records;
	}
	public function get( $id ) {
		foreach ( $this->records as $record ) {
			if ( (string) ( $record['id'] ?? '' ) === (string) $id ) {
				return $record;
			}
		}
		return null;
	}
}

class PublicationArtifactStore extends ArtifactStore {
	private $records;
	public function __construct( array $records ) {
		$this->records = $records;
	}
	public function for_version( $version_id, $kind = null ) {
		$records = $this->records[ $version_id ] ?? [];
		return null === $kind ? $records : array_values( array_filter( $records, fn( $artifact ) => $kind === ( $artifact['kind'] ?? '' ) ) );
	}
}

class PublicationExternalOwnershipValidator extends StorageOwnershipValidator {
	protected function external_identity_exists( array $identity ) {
		return in_array( $identity['strategy'] ?? '', [ 'cpt', 'cct' ], true );
	}
}

class PublicationStorageClaims extends StorageClaimStore {
	private $record;
	public function __construct( array $record ) {
		$this->record = $record;
	}
	public function owner( $strategy = null, $slug = null ) {
		return $this->record;
	}
}

class PublicationApplyState {
	public $token = 'publication-token';
	public $status = 'prepared';
	public $active_version_id = 11;
	public $draft_checksum;
	public $migration_error;
	public $events = [];
	public function __construct() {
		$this->draft_checksum = str_repeat( 'a', 64 );
	}
}

class PublicationApplyChangeSets {
	private $state;
	public function __construct( PublicationApplyState $state ) {
		$this->state = $state;
	}
	public function get() {
		$this->state->events[] = 'change-set:get';
		return [
			'id' => 'change-one',
			'blueprint_id' => 'blueprint-one',
			'from_version_id' => 11,
			'draft_checksum' => str_repeat( 'a', 64 ),
			'status' => $this->state->status,
			'confirmation_hash' => hash( 'sha256', $this->state->token ),
		];
	}
	public function transition( $id, $from, $to ) {
		$this->state->events[] = 'change-set:' . $from . ':' . $to;
		$this->state->status = $to;
		return true;
	}
}

class PublicationApplyBlueprints {
	private $state;
	public function __construct( PublicationApplyState $state ) {
		$this->state = $state;
	}
	public function get() {
		$this->state->events[] = 'blueprint:get';
		return [
			'id' => 'blueprint-one',
			'active_version_id' => $this->state->active_version_id,
			'draft_checksum' => $this->state->draft_checksum,
			'draft_document' => [ 'origin' => [ 'mode' => 'native' ] ],
		];
	}
}

class PublicationApplyLocks {
	private $state;
	public function __construct( PublicationApplyState $state ) {
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
		return true;
	}
}

class PublicationApplyMigrationGuard {
	private $state;
	public function __construct( PublicationApplyState $state ) {
		$this->state = $state;
	}
	public function validate() {
		$this->state->events[] = 'migration:validate';
		return $this->state->migration_error ?: true;
	}
	public function blockers() {
		return [];
	}
}

class PublicationApplyLedger {
	public function validate() {
		return true;
	}
}

class PublicationApplyOwnership {
	private $state;
	public function __construct( PublicationApplyState $state ) {
		$this->state = $state;
	}
	public function validate() {
		$this->state->events[] = 'ownership:validate';
		return true;
	}
}
