<?php
/**
 * Compares legacy sources with imported draft projections without switching runtime.
 */

namespace EIT\Blueprint;

use EIT\CCT\DefinitionManager as CctDefinitions;
use EIT\CCT\Repository as CctRepository;
use EIT\CPT\DefinitionManager as CptDefinitions;
use EIT\Elementor\ElementorDocumentRenderer;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShadowComparator {

	private $cct;
	private $elementor;

	public function __construct( CctRepository $cct = null, ElementorDocumentRenderer $elementor = null ) {
		$this->cct = $cct ?: new CctRepository();
		$this->elementor = $elementor ?: new ElementorDocumentRenderer();
	}

	public function compare( $source_type, $source_key, array $blueprint ) {
		global $wpdb;

		$started = microtime( true );
		$compiled = ( new Compiler() )->compile( $blueprint );
		if ( ! $compiled->is_valid() ) {
			return [
				'status' => 'mismatch',
				'verification_scope' => 'compiled_projection',
				'runtime_switched' => false,
				'checks' => [ 'compiler' => [ 'legacy' => true, 'shadow' => false, 'match' => false ] ],
				'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
				'query_plan' => [ 'legacy_queries' => 0, 'shadow_queries' => 0, 'budget' => 0 ],
				'errors' => $compiled->errors(),
			];
		}
		$projection = $compiled->artifacts();
		$queries_before = (int) $wpdb->num_queries;
		$legacy = $this->snapshot_source( $source_type, $source_key, null );
		$legacy_queries = (int) $wpdb->num_queries - $queries_before;
		$shadow_before = (int) $wpdb->num_queries;
		$shadow = $this->snapshot_source( $source_type, $source_key, $blueprint, $projection );
		$shadow_queries = (int) $wpdb->num_queries - $shadow_before;
		$checks = $this->checks( $legacy, $shadow );
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
			'verification_scope' => 'compiled_projection',
			'runtime_switched' => false,
			'checks' => $checks,
			'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
			'query_plan' => [ 'legacy_queries' => $legacy_queries, 'shadow_queries' => $shadow_queries, 'budget' => $query_budget ],
		];
	}

	private function snapshot_source( $source_type, $source_key, array $blueprint = null, array $projection = [] ) {
		switch ( sanitize_key( $source_type ) ) {
			case 'cpt':
				return $this->cpt_snapshot( sanitize_key( $source_key ), $blueprint, $projection );
			case 'cct':
				return $this->cct_snapshot( sanitize_key( $source_key ), $blueprint, $projection );
			case 'filter_preset':
				return $this->preset_snapshot( sanitize_key( $source_key ), $blueprint );
			case 'elementor_document':
				return $this->elementor_snapshot( absint( $source_key ), $blueprint );
			default:
				return [];
		}
	}

	private function cpt_snapshot( $slug, array $blueprint = null, array $projection = [] ) {
		$definition = CptDefinitions::get( $slug );
		if ( ! $definition ) {
			return [];
		}
		$field_keys = null === $blueprint ? $this->legacy_cpt_keys( $definition ) : $this->compiled_field_keys( 'cpt', $projection );
		$statuses = [ 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'eit_archived' ];
		$ids = get_posts( [ 'post_type' => $slug, 'post_status' => $statuses, 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ] );
		$rows = [];
		foreach ( $ids as $post_id ) {
			$values = [];
			foreach ( $field_keys as $key ) {
				$values[] = taxonomy_exists( $key )
					? array_map( 'intval', wp_get_object_terms( $post_id, $key, [ 'fields' => 'ids' ] ) )
					: get_post_meta( $post_id, $key, true );
			}
			$rows[] = [ 'id' => (int) $post_id, 'status' => get_post_status( $post_id ), 'values' => $values ];
		}
		return $this->record_snapshot( $rows );
	}

	private function cct_snapshot( $slug, array $blueprint = null, array $projection = [] ) {
		$definition = CctDefinitions::get( $slug );
		if ( ! $definition ) {
			return [];
		}
		$field_keys = null === $blueprint ? array_keys( CctDefinitions::fields( $slug ) ) : $this->compiled_field_keys( 'cct', $projection );
		$page = 1;
		$rows = [];
		do {
			$result = $this->cct->query( $slug, [ 'status' => [ 'publish', 'draft', 'review', 'archived' ], 'page' => $page, 'per_page' => 100, 'orderby' => 'id' ] );
			foreach ( $result['items'] ?? [] as $item ) {
				$values = [];
				foreach ( $field_keys as $key ) {
					$values[] = $item[ $key ] ?? null;
				}
				$rows[] = [ 'id' => (int) $item['id'], 'status' => $item['status'], 'values' => $values ];
			}
			++$page;
		} while ( $page <= (int) ( $result['pages'] ?? 1 ) );
		return $this->record_snapshot( $rows );
	}

	private function preset_snapshot( $id, array $blueprint = null ) {
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

	private function elementor_snapshot( $post_id, array $blueprint = null ) {
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

	private function checks( array $legacy, array $shadow ) {
		$checks = [];
		foreach ( [ 'count', 'status_checksum', 'data_checksum', 'html_checksum' ] as $key ) {
			if ( ! array_key_exists( $key, $legacy ) && ! array_key_exists( $key, $shadow ) ) {
				continue;
			}
			$checks[ $key ] = [ 'legacy' => $legacy[ $key ] ?? null, 'shadow' => $shadow[ $key ] ?? null, 'match' => ( $legacy[ $key ] ?? null ) === ( $shadow[ $key ] ?? null ) ];
		}
		return $checks;
	}

	private function blueprint_field_keys( array $blueprint ) {
		$keys = [];
		foreach ( $blueprint['nodes'] ?? [] as $node ) {
			if ( 'field_group' !== ( $node['type'] ?? '' ) ) {
				continue;
			}
			foreach ( $node['config']['fields'] ?? [] as $field ) {
				if ( ! empty( $field['storage']['key'] ) ) {
					$keys[] = sanitize_key( $field['storage']['key'] );
				}
			}
		}
		return $keys;
	}

	private function compiled_field_keys( $strategy, array $artifacts ) {
		foreach ( $artifacts as $artifact ) {
			$payload = $artifact['payload'] ?? [];
			if ( 'entity_definition' !== ( $artifact['kind'] ?? '' ) || $strategy !== ( $payload['strategy'] ?? '' ) ) {
				continue;
			}
			$definition = $payload['definition'] ?? [];
			if ( 'cct' === $strategy ) {
				return array_values( array_filter( array_map( fn( $field ) => sanitize_key( $field['key'] ?? '' ), $definition['fields'] ?? [] ) ) );
			}
			return $this->legacy_cpt_keys( $definition );
		}
		return [];
	}

	private function legacy_cpt_keys( array $definition ) {
		$meta = array_filter( array_map( fn( $field ) => sanitize_key( $field['key'] ?? '' ), $definition['meta_fields'] ?? [] ) );
		$taxonomies = array_filter( array_map( fn( $taxonomy ) => sanitize_key( $taxonomy['slug'] ?? '' ), $definition['taxonomies'] ?? [] ) );
		return array_values( array_merge( $meta, $taxonomies ) );
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
