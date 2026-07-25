<?php
/**
 * Validates shadow-import evidence before an imported Blueprint can publish.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\MigrationStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationPublicationGuard {

	private $migrations;
	private $importer;
	private $compiler;
	private $snapshots;

	public function __construct( $migrations = null, $importer = null, $compiler = null, ?LegacyAuthoritySnapshot $snapshots = null ) {
		$this->migrations = $migrations ?: new MigrationStore();
		$this->importer = $importer ?: new LegacyImporter();
		$this->compiler = $compiler ?: new Compiler();
		$this->snapshots = $snapshots ?: new LegacyAuthoritySnapshot();
	}

	public function validate( array $blueprint ) {
		$blueprint_id = (string) ( $blueprint['id'] ?? '' );
		if ( '' === $blueprint_id && ! $this->is_imported( $blueprint ) ) {
			return true;
		}
		$records = $this->migrations->for_blueprint( $blueprint_id );
		if ( ! $this->is_imported( $blueprint ) && ! $records ) {
			return true;
		}

		if ( 1 !== count( $records ) ) {
			return $this->error(
				$records ? 'eit_migration_evidence_ambiguous' : 'eit_migration_evidence_missing',
				$records
					? __( 'Imported Blueprint has ambiguous migration evidence.', 'elementor-implementation-toolkit' )
					: __( 'Imported Blueprint has no migration evidence.', 'elementor-implementation-toolkit' )
			);
		}
		$record = $records[0];
		if ( (string) ( $record['blueprint_id'] ?? '' ) !== (string) ( $blueprint['id'] ?? '' ) ) {
			return $this->error( 'eit_migration_blueprint_mismatch', __( 'Migration evidence belongs to another Blueprint.', 'elementor-implementation-toolkit' ) );
		}
		if ( 'verified' !== ( $record['status'] ?? '' ) || 'verified' !== ( $record['comparison']['status'] ?? '' ) ) {
			return $this->error( 'eit_migration_comparison_unverified', __( 'Imported Blueprint comparison is not verified.', 'elementor-implementation-toolkit' ) );
		}
		$comparison = $record['comparison'] ?? [];
		$semantic = $comparison['checks']['semantic_contract_checksum'] ?? [];
		$capabilities = $comparison['checks']['capability_downgrades'] ?? [];
		$authorities = $comparison['authorities'] ?? [];
		$legacy_authority = 'elementor_document' === ( $record['source_type'] ?? '' ) ? 'wordpress_post_meta' : 'legacy_option';
		if (
			'independent_authority_projection' !== ( $comparison['verification_scope'] ?? '' )
			|| empty( $semantic['match'] )
			|| empty( $capabilities['match'] )
			|| $legacy_authority !== ( $authorities['legacy']['authority'] ?? '' )
			|| 'compiled_candidate_artifact' !== ( $authorities['candidate']['authority'] ?? '' )
		) {
			return $this->error( 'eit_migration_evidence_stale', __( 'Imported Blueprint evidence predates independent semantic authority checks.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! $this->same_checksum( $record['draft_checksum'] ?? '', $blueprint['draft_checksum'] ?? '' ) ) {
			return $this->error( 'eit_migration_draft_changed', __( 'Imported Blueprint draft changed after verification.', 'elementor-implementation-toolkit' ) );
		}

		try {
			$candidate = $this->importer->candidate( $record['source_type'] ?? '', $record['source_key'] ?? '' );
		} catch ( \Throwable $error ) {
			return $this->error( 'eit_migration_source_unavailable', __( 'Verified legacy source could not be inspected.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! is_array( $candidate ) || ! is_array( $candidate['blueprint'] ?? null ) ) {
			return $this->error( 'eit_migration_source_missing', __( 'Verified legacy source no longer exists.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! $this->same_checksum( $record['source_checksum'] ?? '', $candidate['source_checksum'] ?? '' ) ) {
			return $this->error( 'eit_migration_source_changed', __( 'Legacy source changed after verification.', 'elementor-implementation-toolkit' ) );
		}
		if (
			(string) ( $candidate['blueprint']['id'] ?? '' ) !== (string) ( $blueprint['id'] ?? '' )
			|| ! $this->same_checksum( $record['draft_checksum'] ?? '', $candidate['blueprint']['checksum'] ?? '' )
		) {
			return $this->error( 'eit_migration_projection_changed', __( 'Imported projection changed after verification.', 'elementor-implementation-toolkit' ) );
		}
		$document = $blueprint['draft_document'] ?? null;
		try {
			$compiled = is_array( $document ) ? $this->compiler->compile( $document ) : null;
		} catch ( \Throwable $error ) {
			$compiled = null;
		}
		if ( ! is_object( $compiled ) || ! method_exists( $compiled, 'is_valid' ) || ! method_exists( $compiled, 'checksum' ) || ! $compiled->is_valid() ) {
			return $this->error( 'eit_migration_candidate_uncompilable', __( 'Imported Blueprint no longer compiles under the current runtime.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! $this->same_checksum( $comparison['compiler_checksum'] ?? '', $compiled->checksum() ) ) {
			return $this->error( 'eit_migration_compiler_changed', __( 'Compiler output changed after the shadow evidence was recorded.', 'elementor-implementation-toolkit' ) );
		}
		return $record;
	}

	public function snapshot( array $blueprint ) {
		$record = $this->validate( $blueprint );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		return is_array( $record ) ? $this->snapshots->capture( $record, $blueprint ) : null;
	}

	public function validate_snapshot( array $blueprint, $snapshot ) {
		$record = $this->validate( $blueprint );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		if ( ! is_array( $record ) ) {
			return null === $snapshot
				? true
				: $this->error( 'eit_legacy_authority_snapshot_unexpected', __( 'Native Blueprint cannot consume legacy activation authority.', 'elementor-implementation-toolkit' ) );
		}
		return is_array( $snapshot )
			? $this->snapshots->matches_evidence( $snapshot, $record, $blueprint )
			: $this->error( 'eit_legacy_authority_snapshot_missing', __( 'Imported Blueprint has no frozen legacy activation authority.', 'elementor-implementation-toolkit' ) );
	}

	public function blockers( array $blueprint ) {
		$result = $this->validate( $blueprint );
		if ( ! is_wp_error( $result ) ) {
			return [];
		}
		return [
			[
				'code' => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'blueprint_id' => (string) ( $blueprint['id'] ?? '' ),
			],
		];
	}

	public function allows_handoff( array $blueprint, array $identity ) {
		$record = $this->validate( $blueprint );
		return is_array( $record )
			&& in_array( $identity['strategy'] ?? '', [ 'cpt', 'cct' ], true )
			&& ( $record['source_type'] ?? '' ) === ( $identity['strategy'] ?? '' )
			&& (string) ( $record['source_key'] ?? '' ) === (string) ( $identity['slug'] ?? '' )
			&& (string) ( $record['blueprint_id'] ?? '' ) === (string) ( $blueprint['id'] ?? '' )
			&& $this->same_checksum( $record['draft_checksum'] ?? '', $blueprint['draft_checksum'] ?? '' );
	}

	private function is_imported( array $blueprint ) {
		return 'legacy_shadow' === ( $blueprint['draft_document']['origin']['mode'] ?? '' );
	}

	private function same_checksum( $expected, $actual ) {
		$expected = (string) $expected;
		$actual = (string) $actual;
		return 64 === strlen( $expected ) && 64 === strlen( $actual ) && hash_equals( $expected, $actual );
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, $message, [ 'status' => 409 ] );
	}
}
