<?php
/**
 * Freezes the verified authority that permits one legacy shadow activation.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyAuthoritySnapshot {

	const SCHEMA = 'eit.dev/legacy-authority/v1';

	private $probe;

	public function __construct( $probe = null ) {
		$this->probe = $probe ?: new LegacyAuthorityProbe();
	}

	public function capture( array $evidence, array $blueprint ) {
		$comparison = is_array( $evidence['comparison'] ?? null ) ? $evidence['comparison'] : [];
		$source_type = sanitize_key( $evidence['source_type'] ?? '' );
		$source_key = $this->source_key( $source_type, $evidence['source_key'] ?? '' );
		$blueprint_id = strtolower( (string) ( $blueprint['id'] ?? '' ) );
		$draft_checksum = strtolower( (string) ( $blueprint['draft_checksum'] ?? '' ) );
		$source_checksum = strtolower( (string) ( $evidence['source_checksum'] ?? '' ) );
		if (
			! in_array( $source_type, [ 'cpt', 'cct', 'filter_preset', 'elementor_document' ], true )
			|| '' === $source_key
			|| '' === $blueprint_id
			|| ! $this->is_checksum( $draft_checksum )
			|| ! $this->is_checksum( $source_checksum )
			|| $blueprint_id !== strtolower( (string) ( $evidence['blueprint_id'] ?? '' ) )
			|| 'verified' !== ( $evidence['status'] ?? '' )
			|| 'verified' !== ( $comparison['status'] ?? '' )
		) {
			return $this->error( 'eit_legacy_authority_snapshot_invalid', __( 'Verified legacy authority cannot be frozen safely.', 'elementor-implementation-toolkit' ) );
		}

		$payload = [
			'schema' => self::SCHEMA,
			'source_type' => $source_type,
			'source_key' => $source_key,
			'source_checksum' => $source_checksum,
			'blueprint_id' => $blueprint_id,
			'draft_checksum' => $draft_checksum,
			'version_checksum' => $draft_checksum,
			'contract' => $this->contract( $evidence ),
		];
		$payload['authority_checksum'] = $this->checksum( $payload );
		return $payload;
	}

	public function validate( array $snapshot ) {
		$payload = $this->normalized_payload( $snapshot );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$expected = strtolower( (string) ( $snapshot['authority_checksum'] ?? '' ) );
		if ( ! $this->is_checksum( $expected ) || ! hash_equals( $expected, $this->checksum( $payload ) ) ) {
			return $this->error( 'eit_legacy_authority_snapshot_tampered', __( 'Frozen legacy authority is incomplete or divergent.', 'elementor-implementation-toolkit' ) );
		}
		$payload['authority_checksum'] = $expected;
		return $payload;
	}

	public function matches_evidence( array $snapshot, array $evidence, array $blueprint ) {
		$validated = $this->validate( $snapshot );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$current = $this->capture( $evidence, $blueprint );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		return hash_equals( $validated['authority_checksum'], $current['authority_checksum'] )
			? true
			: $this->error( 'eit_legacy_authority_evidence_changed', __( 'Legacy migration evidence changed after the impact plan was prepared.', 'elementor-implementation-toolkit' ) );
	}

	public function validate_published_version( array $snapshot, array $version ) {
		$validated = $this->validate( $snapshot );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$document = is_array( $version['document'] ?? null ) ? $version['document'] : [];
		if (
			(string) ( $version['blueprint_id'] ?? '' ) !== $validated['blueprint_id']
			|| ! $this->same_checksum( $validated['version_checksum'], $version['checksum'] ?? '' )
			|| ! $this->same_checksum( $validated['version_checksum'], $document['checksum'] ?? '' )
			|| (string) ( $document['id'] ?? '' ) !== $validated['blueprint_id']
			|| 'legacy_shadow' !== ( $document['origin']['mode'] ?? '' )
		) {
			return $this->error( 'eit_legacy_authority_version_mismatch', __( 'Published Blueprint history does not match the frozen legacy authority.', 'elementor-implementation-toolkit' ) );
		}
		$scope = $this->document_storage_scope( $document, $validated );
		return is_wp_error( $scope ) ? $scope : [ 'snapshot' => $validated, 'storage_scope' => $scope ];
	}

	public function validate_document_scope( array $snapshot, array $document ) {
		$validated = $this->validate( $snapshot );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if (
			(string) ( $document['id'] ?? '' ) !== $validated['blueprint_id']
			|| ! $this->same_checksum( $validated['draft_checksum'], $document['checksum'] ?? '' )
			|| 'legacy_shadow' !== ( $document['origin']['mode'] ?? '' )
		) {
			return $this->error( 'eit_legacy_authority_document_mismatch', __( 'Blueprint document does not match the frozen legacy authority.', 'elementor-implementation-toolkit' ) );
		}
		return in_array( $validated['source_type'], [ 'cpt', 'cct' ], true )
			? $this->document_storage_scope( $document, $validated )
			: true;
	}

	public function revalidate_source( array $snapshot ) {
		$validated = $this->validate( $snapshot );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		try {
			$source = $this->probe->probe( $validated['source_type'], $validated['source_key'] );
		} catch ( \Throwable $error ) {
			return $this->error( 'eit_legacy_authority_source_unavailable', __( 'Frozen legacy source could not be inspected.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! is_array( $source ) || empty( $source['available'] ) ) {
			return $this->error( 'eit_legacy_authority_source_missing', __( 'Frozen legacy source no longer exists.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! $this->same_checksum( $validated['source_checksum'], $source['raw_checksum'] ?? '' ) ) {
			return $this->error( 'eit_legacy_authority_source_changed', __( 'Legacy source changed after its activation authority was frozen.', 'elementor-implementation-toolkit' ) );
		}
		$legacy_contract = $validated['contract']['legacy_authority']['contract_checksum'] ?? '';
		if ( $this->is_checksum( $legacy_contract ) && ! $this->same_checksum( $legacy_contract, $source['contract_checksum'] ?? '' ) ) {
			return $this->error( 'eit_legacy_authority_contract_changed', __( 'Legacy source contract changed after its activation authority was frozen.', 'elementor-implementation-toolkit' ) );
		}
		return $validated;
	}

	private function normalized_payload( array $snapshot ) {
		$source_type = sanitize_key( $snapshot['source_type'] ?? '' );
		$source_key = $this->source_key( $source_type, $snapshot['source_key'] ?? '' );
		$blueprint_id = strtolower( (string) ( $snapshot['blueprint_id'] ?? '' ) );
		$source_checksum = strtolower( (string) ( $snapshot['source_checksum'] ?? '' ) );
		$draft_checksum = strtolower( (string) ( $snapshot['draft_checksum'] ?? '' ) );
		$version_checksum = strtolower( (string) ( $snapshot['version_checksum'] ?? '' ) );
		$contract = is_array( $snapshot['contract'] ?? null ) ? $this->normalize_contract( $snapshot['contract'] ) : null;
		if (
			self::SCHEMA !== ( $snapshot['schema'] ?? '' )
			|| ! in_array( $source_type, [ 'cpt', 'cct', 'filter_preset', 'elementor_document' ], true )
			|| '' === $source_key
			|| '' === $blueprint_id
			|| ! $this->is_checksum( $source_checksum )
			|| ! $this->is_checksum( $draft_checksum )
			|| ! $this->same_checksum( $draft_checksum, $version_checksum )
			|| null === $contract
		) {
			return $this->error( 'eit_legacy_authority_snapshot_invalid', __( 'Frozen legacy authority has an invalid contract.', 'elementor-implementation-toolkit' ) );
		}
		return [
			'schema' => self::SCHEMA,
			'source_type' => $source_type,
			'source_key' => $source_key,
			'source_checksum' => $source_checksum,
			'blueprint_id' => $blueprint_id,
			'draft_checksum' => $draft_checksum,
			'version_checksum' => $version_checksum,
			'contract' => $contract,
		];
	}

	private function contract( array $evidence ) {
		$comparison = is_array( $evidence['comparison'] ?? null ) ? $evidence['comparison'] : [];
		$checks = is_array( $comparison['checks'] ?? null ) ? $comparison['checks'] : [];
		return $this->normalize_contract(
			[
				'evidence_status' => $evidence['status'] ?? '',
				'comparison_status' => $comparison['status'] ?? '',
				'verification_scope' => $comparison['verification_scope'] ?? '',
				'compiler_checksum' => $comparison['compiler_checksum'] ?? '',
				'legacy_authority' => $comparison['authorities']['legacy'] ?? [],
				'candidate_authority' => $comparison['authorities']['candidate'] ?? [],
				'semantic_contract_checksum' => $checks['semantic_contract_checksum'] ?? [],
				'capability_downgrades_checksum' => $this->checksum( is_array( $checks['capability_downgrades'] ?? null ) ? $checks['capability_downgrades'] : [] ),
			]
		);
	}

	private function normalize_contract( array $contract ) {
		$compiler_checksum = strtolower( (string) ( $contract['compiler_checksum'] ?? '' ) );
		$downgrade_checksum = strtolower( (string) ( $contract['capability_downgrades_checksum'] ?? '' ) );
		$semantic = is_array( $contract['semantic_contract_checksum'] ?? null ) ? $contract['semantic_contract_checksum'] : [];
		$legacy = $this->authority_contract( $contract['legacy_authority'] ?? [] );
		$candidate = $this->authority_contract( $contract['candidate_authority'] ?? [] );
		if (
			'verified' !== ( $contract['evidence_status'] ?? '' )
			|| 'verified' !== ( $contract['comparison_status'] ?? '' )
			|| 'independent_authority_projection' !== ( $contract['verification_scope'] ?? '' )
			|| ! $this->is_checksum( $compiler_checksum )
			|| ! $this->is_checksum( $downgrade_checksum )
			|| null === $legacy
			|| null === $candidate
			|| ! $this->is_checksum( $semantic['legacy'] ?? '' )
			|| ! $this->is_checksum( $semantic['shadow'] ?? '' )
			|| empty( $semantic['match'] )
		) {
			return null;
		}
		return [
			'evidence_status' => 'verified',
			'comparison_status' => 'verified',
			'verification_scope' => 'independent_authority_projection',
			'compiler_checksum' => $compiler_checksum,
			'legacy_authority' => $legacy,
			'candidate_authority' => $candidate,
			'semantic_contract_checksum' => [
				'legacy' => strtolower( (string) $semantic['legacy'] ),
				'shadow' => strtolower( (string) $semantic['shadow'] ),
				'match' => true,
			],
			'capability_downgrades_checksum' => $downgrade_checksum,
		];
	}

	private function authority_contract( $authority ) {
		if ( ! is_array( $authority ) || empty( $authority['available'] ) || '' === (string) ( $authority['authority'] ?? '' ) || ! $this->is_checksum( $authority['contract_checksum'] ?? '' ) ) {
			return null;
		}
		$result = [
			'authority' => sanitize_key( $authority['authority'] ),
			'available' => true,
			'contract_checksum' => strtolower( (string) $authority['contract_checksum'] ),
		];
		if ( ! empty( $authority['binding_mode'] ) ) {
			$result['binding_mode'] = sanitize_key( $authority['binding_mode'] );
		}
		return $result;
	}

	private function document_storage_scope( array $document, array $snapshot ) {
		$matches = [];
		foreach ( $document['nodes'] ?? [] as $node ) {
			if ( ! is_array( $node ) || 'entity' !== ( $node['type'] ?? '' ) ) {
				continue;
			}
			$config = is_array( $node['config'] ?? null ) ? $node['config'] : [];
			$strategy = sanitize_key( $config['storage']['strategy'] ?? '' );
			$slug = sanitize_key( $config['slug'] ?? '' );
			$legacy_key = sanitize_key( $config['legacy']['key'] ?? '' );
			if ( $strategy === $snapshot['source_type'] && $slug === $snapshot['source_key'] && $legacy_key === $snapshot['source_key'] ) {
				$matches[] = [ 'strategy' => $strategy, 'storage_slug' => $slug ];
			}
		}
		if ( 1 !== count( $matches ) || '' === StorageMutationGuard::resource_key( $matches[0]['strategy'] ?? '', $matches[0]['storage_slug'] ?? '' ) ) {
			return $this->error( 'eit_legacy_authority_storage_mismatch', __( 'Published storage scope does not match the frozen legacy authority.', 'elementor-implementation-toolkit' ) );
		}
		return $matches[0];
	}

	private function source_key( $source_type, $source_key ) {
		if ( 'elementor_document' !== $source_type ) {
			return sanitize_key( $source_key );
		}
		$post_id = absint( $source_key );
		return $post_id ? (string) $post_id : '';
	}

	private function checksum( $value ) {
		return hash( 'sha256', wp_json_encode( $this->sort_recursive( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ $this, 'sort_recursive' ], $value );
		}
		ksort( $value );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->sort_recursive( $child );
		}
		return $value;
	}

	private function same_checksum( $expected, $actual ) {
		$expected = strtolower( (string) $expected );
		$actual = strtolower( (string) $actual );
		return $this->is_checksum( $expected ) && $this->is_checksum( $actual ) && hash_equals( $expected, $actual );
	}

	private function is_checksum( $checksum ) {
		return (bool) preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $checksum ) );
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, $message, [ 'status' => 409 ] );
	}
}
