<?php
/**
 * Compares raw legacy authority with an independently compiled candidate projection.
 */

namespace EIT\Blueprint;

use EIT\Elementor\ElementorDocumentRenderer;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShadowComparator {

	private $cct_records;
	private $elementor;
	private $legacy;
	private $candidate;
	private $runtime;

	public function __construct(
		?CctShadowRecordProbe $cct_records = null,
		?ElementorDocumentRenderer $elementor = null,
		?LegacyAuthorityProbe $legacy = null,
		?CandidateArtifactProbe $candidate = null,
		?ActiveRuntimeProbe $runtime = null
	) {
		$this->cct_records = $cct_records ?: new CctShadowRecordProbe();
		$this->elementor = $elementor ?: new ElementorDocumentRenderer();
		$this->legacy = $legacy ?: new LegacyAuthorityProbe();
		$this->candidate = $candidate ?: new CandidateArtifactProbe();
		$this->runtime = $runtime ?: new ActiveRuntimeProbe();
	}

	public function compare( $source_type, $source_key, array $blueprint ) {
		global $wpdb;

		$started = microtime( true );
		$compiled = ( new Compiler() )->compile( $blueprint );
		if ( ! $compiled->is_valid() ) {
			return $this->compiler_mismatch( $started, $compiled->errors() );
		}

		$queries_before = (int) $wpdb->num_queries;
		$legacy_authority = $this->legacy->probe( $source_type, $source_key );
		$legacy = $this->snapshot_source( $source_type, $source_key, $legacy_authority['fields'] ?? [] );
		$legacy_queries = (int) $wpdb->num_queries - $queries_before;

		$shadow_before = (int) $wpdb->num_queries;
		$candidate_authority = $this->candidate->probe( $source_type, $source_key, $compiled->artifacts(), $blueprint );
		$shadow = $this->snapshot_source( $source_type, $source_key, $candidate_authority['fields'] ?? [], $blueprint );
		$shadow_queries = (int) $wpdb->num_queries - $shadow_before;

		$runtime_before = (int) $wpdb->num_queries;
		$runtime = $this->runtime->probe( $source_type, $source_key );
		$runtime_queries = (int) $wpdb->num_queries - $runtime_before;
		$checks = $this->checks( $legacy, $shadow, $legacy_authority, $candidate_authority );
		$query_budget = max( 10, $legacy_queries + 2 );
		$checks['query_budget'] = [
			'legacy' => $legacy_queries,
			'shadow' => $shadow_queries,
			'budget' => $query_budget,
			'match' => $shadow_queries <= $query_budget,
		];
		$verified = ! in_array( false, array_column( $checks, 'match' ), true );

		return [
			'status' => $verified ? 'verified' : 'mismatch',
			'verification_scope' => 'independent_authority_projection',
			'compiler_checksum' => $compiled->checksum(),
			'runtime_switched' => false,
			'record_probe' => $this->record_probe_facts( $source_type ),
			'authorities' => [
				'legacy' => $this->authority_facts( $legacy_authority ),
				'candidate' => $this->authority_facts( $candidate_authority ),
				'active_runtime' => $this->authority_facts( $runtime ),
			],
			'checks' => $checks,
			'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
			'query_plan' => [
				'legacy_queries' => $legacy_queries,
				'shadow_queries' => $shadow_queries,
				'active_runtime_queries' => $runtime_queries,
				'budget' => $query_budget,
			],
		];
	}

	private function snapshot_source( $source_type, $source_key, array $fields, ?array $blueprint = null ) {
		switch ( sanitize_key( $source_type ) ) {
			case 'cpt':
				return $this->cpt_snapshot( sanitize_key( $source_key ), $fields );
			case 'cct':
				return $this->cct_snapshot( sanitize_key( $source_key ), $fields );
			case 'filter_preset':
				return $this->preset_snapshot( sanitize_key( $source_key ), $blueprint );
			case 'elementor_document':
				return $this->elementor_snapshot( absint( $source_key ), $blueprint );
			default:
				return [];
		}
	}

	private function cpt_snapshot( $slug, array $fields ) {
		$statuses = [ 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'eit_archived' ];
		$ids = get_posts( [ 'post_type' => $slug, 'post_status' => $statuses, 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ] );
		$rows = [];
		$available = true;
		foreach ( $ids as $post_id ) {
			$values = [];
			foreach ( $fields as $field ) {
				$key = sanitize_key( $field['key'] ?? '' );
				if ( 'taxonomy' === ( $field['kind'] ?? '' ) ) {
					$terms = wp_get_object_terms( $post_id, $key, [ 'fields' => 'ids' ] );
					if ( is_wp_error( $terms ) ) {
						$available = false;
						$values[] = null;
					} else {
						$values[] = array_map( 'intval', $terms );
					}
				} else {
					$values[] = get_post_meta( $post_id, $key, true );
				}
			}
			$rows[] = [ 'id' => (int) $post_id, 'status' => get_post_status( $post_id ), 'values' => $values ];
		}
		return array_merge( $this->record_snapshot( $rows ), [ 'storage_available' => $available ] );
	}

	private function cct_snapshot( $slug, array $fields ) {
		$probe = $this->cct_records->snapshot( $slug, $fields );
		return array_merge( $this->record_snapshot( $probe['rows'] ?? [] ), [ 'storage_available' => ! empty( $probe['available'] ) ] );
	}

	private function preset_snapshot( $id, ?array $blueprint = null ) {
		if ( null === $blueprint ) {
			$presets = get_option( FilterPresets::OPTION, [] );
			$preset = is_array( $presets ) ? ( $presets[ $id ] ?? [] ) : [];
			$filters = array_values( array_map( [ $this, 'legacy_filter_shape' ], $preset['filters'] ?? [] ) );
		} else {
			$filters = [];
			foreach ( $blueprint['nodes'] ?? [] as $node ) {
				if ( 'filter_surface' === ( $node['type'] ?? '' ) ) {
					$filters = array_values( array_map( [ $this, 'legacy_filter_shape' ], $node['config']['filters'] ?? [] ) );
				}
			}
		}
		return [ 'count' => count( $filters ), 'status_checksum' => hash( 'sha256', 'configured' ), 'data_checksum' => $this->checksum( $filters ) ];
	}

	private function elementor_snapshot( $post_id, ?array $blueprint = null ) {
		if ( null !== $blueprint ) {
			$post_id = $this->blueprint_document_id( $blueprint ) ?: $post_id;
			$html = $this->elementor->render( $post_id );
		} else {
			$frontend = class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::$instance->frontend : null;
			$html = is_object( $frontend ) && method_exists( $frontend, 'get_builder_content_for_display' ) ? (string) $frontend->get_builder_content_for_display( $post_id, true ) : '';
		}
		return [
			'count' => get_post( $post_id ) ? 1 : 0,
			'status_checksum' => hash( 'sha256', (string) get_post_status( $post_id ) ),
			'data_checksum' => hash( 'sha256', (string) get_post_meta( $post_id, '_elementor_data', true ) ),
			'html_checksum' => hash( 'sha256', $this->normalize_html( $html ) ),
		];
	}

	private function record_snapshot( array $rows ) {
		$statuses = array_count_values( array_column( $rows, 'status' ) );
		ksort( $statuses );
		return [ 'count' => count( $rows ), 'status_counts' => $statuses, 'status_checksum' => $this->checksum( $statuses ), 'data_checksum' => $this->checksum( $rows ) ];
	}

	private function checks( array $legacy, array $shadow, array $legacy_authority, array $candidate_authority ) {
		$checks = [
			'authority_available' => [ 'legacy' => ! empty( $legacy_authority['available'] ), 'shadow' => ! empty( $candidate_authority['available'] ), 'match' => ! empty( $legacy_authority['available'] ) && ! empty( $candidate_authority['available'] ) ],
			'semantic_contract_checksum' => [
				'legacy' => $legacy_authority['contract_checksum'] ?? '',
				'shadow' => $candidate_authority['contract_checksum'] ?? '',
				'match' => $this->same_checksum( $legacy_authority['contract_checksum'] ?? '', $candidate_authority['contract_checksum'] ?? '' ),
			],
			'capability_downgrades' => [
				'legacy' => [],
				'shadow' => $candidate_authority['capability_downgrades'] ?? [],
				'match' => empty( $candidate_authority['capability_downgrades'] ),
			],
		];
		foreach ( [ 'count', 'status_checksum', 'data_checksum', 'html_checksum' ] as $key ) {
			if ( ! array_key_exists( $key, $legacy ) && ! array_key_exists( $key, $shadow ) ) {
				continue;
			}
			$checks[ $key ] = [ 'legacy' => $legacy[ $key ] ?? null, 'shadow' => $shadow[ $key ] ?? null, 'match' => ( $legacy[ $key ] ?? null ) === ( $shadow[ $key ] ?? null ) ];
		}
		if ( array_key_exists( 'storage_available', $legacy ) || array_key_exists( 'storage_available', $shadow ) ) {
			$checks['storage_available'] = [ 'legacy' => ! empty( $legacy['storage_available'] ), 'shadow' => ! empty( $shadow['storage_available'] ), 'match' => ! empty( $legacy['storage_available'] ) && ! empty( $shadow['storage_available'] ) ];
		}
		return $checks;
	}

	private function compiler_mismatch( $started, array $errors ) {
		return [
			'status' => 'mismatch',
			'verification_scope' => 'independent_authority_projection',
			'compiler_checksum' => '',
			'runtime_switched' => false,
			'authorities' => [],
			'checks' => [ 'compiler' => [ 'legacy' => true, 'shadow' => false, 'match' => false ] ],
			'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
			'query_plan' => [ 'legacy_queries' => 0, 'shadow_queries' => 0, 'active_runtime_queries' => 0, 'budget' => 0 ],
			'errors' => $errors,
		];
	}

	private function authority_facts( array $probe ) {
		$facts = [
			'authority' => (string) ( $probe['authority'] ?? '' ),
			'available' => ! empty( $probe['available'] ),
			'contract_checksum' => (string) ( $probe['contract_checksum'] ?? '' ),
		];
		if ( ! empty( $probe['binding_mode'] ) ) {
			$facts['binding_mode'] = sanitize_key( $probe['binding_mode'] );
		}
		return $facts;
	}

	private function record_probe_facts( $source_type ) {
		$facts = [
			'cpt' => [ 'mode' => 'wordpress_api_diagnostic', 'runtime_definition_independent' => false ],
			'cct' => [ 'mode' => 'direct_table_contract_hydration', 'runtime_definition_independent' => true ],
			'filter_preset' => [ 'mode' => 'legacy_option_and_compiled_projection', 'runtime_definition_independent' => true ],
			'elementor_document' => [ 'mode' => 'same_wordpress_document_binding', 'runtime_definition_independent' => false ],
		];
		return $facts[ sanitize_key( $source_type ) ] ?? [ 'mode' => 'unsupported', 'runtime_definition_independent' => false ];
	}

	private function same_checksum( $left, $right ) {
		$left = (string) $left;
		$right = (string) $right;
		return 64 === strlen( $left ) && 64 === strlen( $right ) && hash_equals( $left, $right );
	}

	private function legacy_filter_shape( $filter ) {
		return [ 'type' => sanitize_key( $filter['type'] ?? '' ), 'key' => sanitize_key( $filter['resolved_key'] ?? $filter['key'] ?? '' ), 'enabled' => ! empty( $filter['enabled'] ) ];
	}

	private function blueprint_document_id( array $blueprint ) {
		foreach ( $blueprint['nodes'] ?? [] as $node ) {
			if ( 'presentation' === ( $node['type'] ?? '' ) ) {
				return absint( $node['config']['document_id'] ?? 0 );
			}
		}
		return 0;
	}

	private function normalize_html( $html ) {
		$html = preg_replace( '/<!--.*?-->/s', '', (string) $html );
		$html = preg_replace( '/\s+/', ' ', $html );
		return trim( (string) $html );
	}

	private function checksum( $value ) {
		return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
