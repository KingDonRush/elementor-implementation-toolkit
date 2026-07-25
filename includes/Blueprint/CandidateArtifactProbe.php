<?php
/**
 * Projects semantic facts only from a compiler result, never from active runtime definitions.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CandidateArtifactProbe {

	private $semantics;

	public function __construct( ?LegacySemanticContract $semantics = null ) {
		$this->semantics = $semantics ?: new LegacySemanticContract();
	}

	public function probe( $source_type, $source_key, array $artifacts, array $blueprint = [] ) {
		$source_type = sanitize_key( $source_type );
		if ( in_array( $source_type, [ 'cpt', 'cct' ], true ) ) {
			return $this->entity_probe( $source_type, sanitize_key( $source_key ), $artifacts );
		}
		if ( 'elementor_document' === $source_type ) {
			return $this->document_probe( absint( $source_key ), $artifacts );
		}
		if ( 'filter_preset' === $source_type ) {
			return $this->preset_probe( $blueprint, $artifacts );
		}
		return $this->missing();
	}

	private function entity_probe( $strategy, $slug, array $artifacts ) {
		foreach ( $artifacts as $artifact ) {
			$payload = $artifact['payload'] ?? [];
			$definition = $payload['definition'] ?? null;
			if (
				'entity_definition' !== ( $artifact['kind'] ?? '' )
				|| $strategy !== ( $payload['strategy'] ?? '' )
				|| ! is_array( $definition )
				|| $slug !== sanitize_key( $definition['slug'] ?? '' )
			) {
				continue;
			}
			$contract = $this->semantics->from_definition( $strategy, $definition );
			return [
				'available' => true,
				'authority' => 'compiled_candidate_artifact',
				'artifact_checksum' => (string) ( $artifact['checksum'] ?? '' ),
				'contract' => $contract,
				'contract_checksum' => $this->semantics->checksum( $contract ),
				'fields' => $this->entity_fields( $strategy, $definition ),
				'capability_downgrades' => $this->downgrades( $payload['fields'] ?? [] ),
			];
		}
		return $this->missing();
	}

	private function document_probe( $post_id, array $artifacts ) {
		foreach ( $artifacts as $artifact ) {
			$payload = $artifact['payload'] ?? [];
			if ( 'presentation_contract' !== ( $artifact['kind'] ?? '' ) || $post_id !== absint( $payload['document_id'] ?? 0 ) ) {
				continue;
			}
			$contract = [ 'document_id' => $post_id ];
			return [
				'available' => true,
				'authority' => 'compiled_candidate_artifact',
				'binding_mode' => 'same_wordpress_document',
				'artifact_checksum' => (string) ( $artifact['checksum'] ?? '' ),
				'contract' => $contract,
				'contract_checksum' => $this->semantics->checksum( $contract ),
				'fields' => [],
				'capability_downgrades' => [],
			];
		}
		return $this->missing();
	}

	private function preset_probe( array $blueprint, array $artifacts ) {
		$has_artifact = false;
		foreach ( $artifacts as $artifact ) {
			if ( 'filter_contract' === ( $artifact['kind'] ?? '' ) ) {
				$has_artifact = true;
				break;
			}
		}
		foreach ( $blueprint['nodes'] ?? [] as $node ) {
			if ( $has_artifact && 'filter_surface' === ( $node['type'] ?? '' ) ) {
				$legacy = $node['config']['legacy'] ?? [];
				$contract = $this->semantics->from_preset(
					[
						'name' => $node['name'] ?? '',
						'target_selector' => $legacy['target_selector'] ?? '',
						'item_selector' => $legacy['item_selector'] ?? '',
						'filters' => $node['config']['filters'] ?? [],
					]
				);
				return [
					'available' => true,
					'authority' => 'compiled_candidate_artifact',
					'artifact_checksum' => $this->artifact_set_checksum( $artifacts ),
					'contract' => $contract,
					'contract_checksum' => $this->semantics->checksum( $contract ),
					'fields' => [],
					'capability_downgrades' => $this->blueprint_downgrades( $blueprint ),
				];
			}
		}
		return $this->missing();
	}

	private function entity_fields( $strategy, array $definition ) {
		$fields = [];
		if ( 'cpt' === $strategy ) {
			foreach ( $definition['meta_fields'] ?? [] as $field ) {
				$fields[] = [ 'key' => sanitize_key( $field['key'] ?? '' ), 'kind' => 'meta', 'definition' => $field ];
			}
			foreach ( $definition['taxonomies'] ?? [] as $taxonomy ) {
				$fields[] = [ 'key' => sanitize_key( $taxonomy['slug'] ?? '' ), 'kind' => 'taxonomy', 'definition' => $taxonomy ];
			}
		} else {
			foreach ( $definition['fields'] ?? [] as $field ) {
				if ( ! empty( $field['active'] ) ) {
					$fields[] = [ 'key' => sanitize_key( $field['key'] ?? '' ), 'kind' => 'column', 'definition' => $field ];
				}
			}
		}
		$fields = array_values( array_filter( $fields, fn( $field ) => '' !== $field['key'] ) );
		usort( $fields, fn( $left, $right ) => strcmp( $left['key'], $right['key'] ) );
		return $fields;
	}

	private function downgrades( array $fields ) {
		$downgrades = [];
		foreach ( $fields as $field ) {
			$capabilities = $field['migration']['capability_downgrades'] ?? [];
			if ( $capabilities ) {
				$capabilities = array_values( array_unique( array_map( 'sanitize_key', (array) $capabilities ) ) );
				sort( $capabilities );
				$downgrades[] = [ 'field_id' => (string) ( $field['id'] ?? '' ), 'storage_key' => sanitize_key( $field['storage']['key'] ?? '' ), 'capabilities' => $capabilities ];
			}
		}
		usort( $downgrades, fn( $left, $right ) => strcmp( $left['field_id'], $right['field_id'] ) );
		return $downgrades;
	}

	private function blueprint_downgrades( array $blueprint ) {
		$fields = [];
		foreach ( $blueprint['nodes'] ?? [] as $node ) {
			if ( 'field_group' === ( $node['type'] ?? '' ) ) {
				$fields = array_merge( $fields, $node['config']['fields'] ?? [] );
			}
		}
		return $this->downgrades( $fields );
	}

	private function artifact_set_checksum( array $artifacts ) {
		$checksums = array_values( array_filter( array_map( fn( $artifact ) => (string) ( $artifact['checksum'] ?? '' ), $artifacts ) ) );
		sort( $checksums );
		return hash( 'sha256', implode( '|', $checksums ) );
	}

	private function missing() {
		return [ 'available' => false, 'authority' => 'compiled_candidate_artifact', 'artifact_checksum' => '', 'contract' => [], 'contract_checksum' => '', 'fields' => [], 'capability_downgrades' => [] ];
	}
}
