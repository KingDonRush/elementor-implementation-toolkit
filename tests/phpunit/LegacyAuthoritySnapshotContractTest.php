<?php
/**
 * Frozen legacy activation and rollback authority contracts.
 */

use EIT\Blueprint\LegacyAuthorityProbe;
use EIT\Blueprint\LegacyAuthoritySnapshot;
use EIT\Blueprint\LegacySourceCommitGuard;
use PHPUnit\Framework\TestCase;

class LegacyAuthoritySnapshotContractTest extends TestCase {

	public function test_snapshot_ignores_volatile_rescan_metadata_but_detects_authority_drift(): void {
		$probe = new LegacyAuthoritySnapshotProbe();
		$snapshots = new LegacyAuthoritySnapshot( $probe );
		$blueprint = $this->blueprint();
		$evidence = $this->evidence();
		$snapshot = $snapshots->capture( $evidence, $blueprint );

		self::assertIsArray( $snapshot );
		self::assertSame( 64, strlen( $snapshot['authority_checksum'] ) );

		$rescan = $evidence;
		$rescan['id'] = 'mutable-upsert-row';
		$rescan['updated_at'] = '2030-01-01 00:00:00';
		$rescan['comparison']['duration_ms'] = 999.99;
		$rescan['comparison']['query_plan'] = [ 'legacy_queries' => 99 ];
		self::assertTrue( $snapshots->matches_evidence( $snapshot, $rescan, $blueprint ) );

		$rescan['comparison']['compiler_checksum'] = str_repeat( 'f', 64 );
		$changed = $snapshots->matches_evidence( $snapshot, $rescan, $blueprint );
		self::assertSame( 'eit_legacy_authority_evidence_changed', $changed->get_error_code() );
	}

	public function test_published_document_and_raw_source_are_the_only_rollback_authorities(): void {
		$probe = new LegacyAuthoritySnapshotProbe();
		$snapshots = new LegacyAuthoritySnapshot( $probe );
		$blueprint = $this->blueprint();
		$snapshot = $snapshots->capture( $this->evidence(), $blueprint );
		$version = [
			'blueprint_id' => $blueprint['id'],
			'checksum' => $blueprint['draft_checksum'],
			'document' => $blueprint['draft_document'],
		];

		$published = $snapshots->validate_published_version( $snapshot, $version );
		self::assertSame( [ 'strategy' => 'cpt', 'storage_slug' => 'legacy_records' ], $published['storage_scope'] );
		self::assertIsArray( $snapshots->revalidate_source( $snapshot ) );

		$probe->source_checksum = str_repeat( '9', 64 );
		$drift = $snapshots->revalidate_source( $snapshot );
		self::assertSame( 'eit_legacy_authority_source_changed', $drift->get_error_code() );
	}

	public function test_commit_guard_fails_closed_for_missing_or_changed_import_authority(): void {
		$snapshots = new LegacyAuthoritySnapshot( new LegacyAuthoritySnapshotProbe() );
		$blueprint = $this->blueprint();
		$snapshot = $snapshots->capture( $this->evidence(), $blueprint );
		$change_set = [
			'blueprint_id' => $blueprint['id'],
			'draft_checksum' => $blueprint['draft_checksum'],
			'compiled_artifacts' => [ 'legacy_authority' => $snapshot ],
		];
		$current_checksum = $snapshot['source_checksum'];
		$guard = new LegacySourceCommitGuard(
			$snapshots,
			function () use ( &$current_checksum ) {
				return [ 'available' => true, 'source_checksum' => $current_checksum ];
			}
		);

		self::assertTrue( $guard->validate_and_lock( $change_set, $this->stored_blueprint( $blueprint ) ) );
		$current_checksum = str_repeat( '8', 64 );
		$changed = $guard->validate_and_lock( $change_set, $this->stored_blueprint( $blueprint ) );
		self::assertSame( 'eit_legacy_authority_source_changed', $changed->get_error_code() );

		unset( $change_set['compiled_artifacts']['legacy_authority'] );
		$missing = $guard->validate_and_lock( $change_set, $this->stored_blueprint( $blueprint ) );
		self::assertSame( 'eit_legacy_authority_snapshot_missing', $missing->get_error_code() );
		$native = $this->stored_blueprint( $blueprint );
		$native['draft_document']['origin']['mode'] = 'native';
		self::assertTrue( $guard->validate_and_lock( $change_set, $native ) );
	}

	public function test_elementor_raw_probe_uses_the_importer_checksum_shape(): void {
		$raw = [ 'id' => 27, 'type' => 'page', 'status' => 'publish', 'modified' => '2026-07-16 12:00:00', 'data' => '[{"id":"hero"}]' ];
		$probe = new LegacyAuthorityProbe( null, fn() => $raw );

		$result = $probe->probe( 'elementor_document', '27' );

		self::assertSame( hash( 'sha256', wp_json_encode( $raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), $result['raw_checksum'] );
	}

	private function blueprint(): array {
		$checksum = str_repeat( 'a', 64 );
		$document = [
			'id' => 'bp-legacy',
			'checksum' => $checksum,
			'origin' => [ 'mode' => 'legacy_shadow' ],
			'nodes' => [
				[
					'id' => 'entity',
					'type' => 'entity',
					'config' => [
						'slug' => 'legacy_records',
						'storage' => [ 'strategy' => 'cpt' ],
						'legacy' => [ 'key' => 'legacy_records' ],
					],
				],
			],
		];
		return [ 'id' => 'bp-legacy', 'draft_checksum' => $checksum, 'draft_document' => $document ];
	}

	private function stored_blueprint( array $blueprint ): array {
		return array_merge( $blueprint, [ 'draft_revision' => 4, 'active_version_id' => null ] );
	}

	private function evidence(): array {
		$contract = str_repeat( 'd', 64 );
		return [
			'source_type' => 'cpt',
			'source_key' => 'legacy_records',
			'source_checksum' => str_repeat( 'b', 64 ),
			'blueprint_id' => 'bp-legacy',
			'draft_checksum' => str_repeat( 'a', 64 ),
			'status' => 'verified',
			'comparison' => [
				'status' => 'verified',
				'verification_scope' => 'independent_authority_projection',
				'compiler_checksum' => str_repeat( 'c', 64 ),
				'authorities' => [
					'legacy' => [ 'authority' => 'legacy_option', 'available' => true, 'contract_checksum' => $contract ],
					'candidate' => [ 'authority' => 'compiled_candidate_artifact', 'available' => true, 'contract_checksum' => $contract ],
				],
				'checks' => [
					'semantic_contract_checksum' => [ 'legacy' => $contract, 'shadow' => $contract, 'match' => true ],
					'capability_downgrades' => [ 'legacy' => [], 'shadow' => [], 'match' => true ],
				],
			],
		];
	}
}

class LegacyAuthoritySnapshotProbe {
	public $source_checksum;

	public function __construct() {
		$this->source_checksum = str_repeat( 'b', 64 );
	}

	public function probe(): array {
		return [
			'available' => true,
			'raw_checksum' => $this->source_checksum,
			'contract_checksum' => str_repeat( 'd', 64 ),
		];
	}
}
