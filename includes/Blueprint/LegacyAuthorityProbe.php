<?php
/**
 * Reads the immutable shadow source directly from legacy options or post meta.
 */

namespace EIT\Blueprint;

use EIT\CCT\DefinitionManager as CctDefinitions;
use EIT\CPT\DefinitionManager as CptDefinitions;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyAuthorityProbe {

	private $option_reader;
	private $document_reader;
	private $semantics;

	public function __construct( $option_reader = null, $document_reader = null, ?LegacySemanticContract $semantics = null ) {
		$this->option_reader = is_callable( $option_reader ) ? $option_reader : fn( $name ) => get_option( $name, [] );
		$this->document_reader = is_callable( $document_reader ) ? $document_reader : [ $this, 'read_document' ];
		$this->semantics = $semantics ?: new LegacySemanticContract();
	}

	public function probe( $source_type, $source_key ) {
		$source_type = sanitize_key( $source_type );
		if ( 'elementor_document' === $source_type ) {
			return $this->document_probe( absint( $source_key ) );
		}
		$options = [
			'cpt' => CptDefinitions::OPTION,
			'cct' => CctDefinitions::OPTION,
			'filter_preset' => FilterPresets::OPTION,
		];
		if ( ! isset( $options[ $source_type ] ) ) {
			return $this->missing( 'unsupported_source' );
		}
		$records = call_user_func( $this->option_reader, $options[ $source_type ] );
		$key = sanitize_key( $source_key );
		$raw = is_array( $records ) && is_array( $records[ $key ] ?? null ) ? $records[ $key ] : null;
		if ( null === $raw ) {
			return $this->missing( 'legacy_option' );
		}
		$contract = 'filter_preset' === $source_type
			? $this->semantics->from_preset( $raw )
			: $this->semantics->from_definition( $source_type, $raw );
		return [
			'available' => true,
			'authority' => 'legacy_option',
			'raw_checksum' => $this->checksum( $raw ),
			'contract' => $contract,
			'contract_checksum' => $this->semantics->checksum( $contract ),
			'fields' => $this->fields( $source_type, $raw ),
			'capability_downgrades' => [],
		];
	}

	public function read_document( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post ) {
			return null;
		}
		return [
			'id' => (int) $post->ID,
			'type' => (string) $post->post_type,
			'status' => (string) $post->post_status,
			'modified' => (string) $post->post_modified_gmt,
			'data' => get_post_meta( $post->ID, '_elementor_data', true ),
		];
	}

	private function document_probe( $post_id ) {
		$raw = call_user_func( $this->document_reader, $post_id );
		if ( ! is_array( $raw ) ) {
			return $this->missing( 'wordpress_post_meta' );
		}
		$contract = [ 'document_id' => absint( $raw['id'] ?? $post_id ) ];
		return [
			'available' => true,
			'authority' => 'wordpress_post_meta',
			'binding_mode' => 'source_wordpress_document',
			'raw_checksum' => $this->checksum( $raw ),
			'contract' => $contract,
			'contract_checksum' => $this->semantics->checksum( $contract ),
			'fields' => [],
			'capability_downgrades' => [],
		];
	}

	private function fields( $source_type, array $definition ) {
		$fields = [];
		if ( 'cpt' === $source_type ) {
			foreach ( $definition['meta_fields'] ?? [] as $field ) {
				if ( is_array( $field ) && '' !== sanitize_key( $field['key'] ?? '' ) ) {
					$fields[] = [ 'key' => sanitize_key( $field['key'] ), 'kind' => 'meta', 'definition' => $field ];
				}
			}
			foreach ( $definition['taxonomies'] ?? [] as $taxonomy ) {
				if ( is_array( $taxonomy ) && '' !== sanitize_key( $taxonomy['slug'] ?? '' ) ) {
					$fields[] = [ 'key' => sanitize_key( $taxonomy['slug'] ), 'kind' => 'taxonomy', 'definition' => $taxonomy ];
				}
			}
		} elseif ( 'cct' === $source_type ) {
			foreach ( $definition['fields'] ?? [] as $field ) {
				if ( is_array( $field ) && ! empty( $field['active'] ) && '' !== sanitize_key( $field['key'] ?? '' ) ) {
					$fields[] = [ 'key' => sanitize_key( $field['key'] ), 'kind' => 'column', 'definition' => $field ];
				}
			}
		}
		usort( $fields, fn( $left, $right ) => strcmp( $left['key'], $right['key'] ) );
		return $fields;
	}

	private function missing( $authority ) {
		return [ 'available' => false, 'authority' => $authority, 'raw_checksum' => '', 'contract' => [], 'contract_checksum' => '', 'fields' => [], 'capability_downgrades' => [] ];
	}

	private function checksum( $value ) {
		return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
