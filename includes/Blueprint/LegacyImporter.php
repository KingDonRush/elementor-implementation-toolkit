<?php
/**
 * Read-only deterministic importer for legacy options in shadow mode.
 */

namespace EIT\Blueprint;

use EIT\CCT\DefinitionManager as CctDefinitions;
use EIT\CPT\DefinitionManager as CptDefinitions;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyImporter {

	private $factory;
	private $canonicalizer;

	public function __construct( FieldContractFactory $factory = null, Canonicalizer $canonicalizer = null ) {
		$this->factory = $factory ?: new FieldContractFactory( new FieldPrimitiveRegistry() );
		$this->canonicalizer = $canonicalizer ?: new Canonicalizer();
	}

	public function inspect_all() {
		$before = $this->legacy_snapshot();
		$result = [ 'cpt' => [], 'cct' => [], 'filter_preset' => [] ];
		foreach ( $before['cpt'] as $slug => $definition ) {
			$result['cpt'][ $slug ] = $this->import_entity( 'cpt', $slug, $definition );
		}
		foreach ( $before['cct'] as $slug => $definition ) {
			$result['cct'][ $slug ] = $this->import_entity( 'cct', $slug, $definition );
		}
		foreach ( $before['filter_preset'] as $id => $preset ) {
			$result['filter_preset'][ $id ] = $this->import_preset( $id, $preset );
		}
		$after = $this->legacy_snapshot();

		return [
			'mode' => 'shadow_read_only',
			'read_only' => hash_equals( $this->snapshot_checksum( $before ), $this->snapshot_checksum( $after ) ),
			'source_checksum' => $this->snapshot_checksum( $before ),
			'blueprints' => $result,
		];
	}

	public function import_entity( $strategy, $slug, array $definition ) {
		$strategy = 'cpt' === $strategy ? 'cpt' : 'cct';
		$seed = $strategy . ':' . sanitize_key( $slug );
		$blueprint_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'blueprint:' . $seed );
		$entity_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entity:' . $seed );
		$fields = 'cpt' === $strategy
			? $this->cpt_fields( $seed, $definition )
			: $this->cct_fields( $seed, $definition );
		$name = sanitize_text_field( $definition['singular'] ?? $definition['plural'] ?? $slug );
		$nodes = [
			[
				'id' => $entity_id,
				'type' => 'entity',
				'lane' => 'data',
				'name' => $name,
				'config' => [
					'slug' => sanitize_key( $slug ),
					'singular' => sanitize_text_field( $definition['singular'] ?? $name ),
					'plural' => sanitize_text_field( $definition['plural'] ?? $name ),
					'public' => ! empty( $definition['public'] ),
					'routed' => 'cpt' === $strategy && ! empty( $definition['has_archive'] ),
					'versioned' => 'cpt' === $strategy && in_array( 'revisions', $definition['supports'] ?? [], true ),
					'mode' => 'cpt' === $strategy && in_array( 'editor', $definition['supports'] ?? [], true ) ? 'hybrid' : 'structured',
					'storage' => [
						'strategy' => $strategy,
						'override_reason' => 'Preserve the existing legacy storage during read-only shadow comparison.',
					],
					'legacy' => [ 'option' => 'cpt' === $strategy ? CptDefinitions::OPTION : CctDefinitions::OPTION, 'key' => sanitize_key( $slug ) ],
				],
			],
		];
		$connections = [];
		if ( $fields ) {
			$group_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'field-group:' . $seed );
			$nodes[] = [ 'id' => $group_id, 'type' => 'field_group', 'lane' => 'data', 'name' => 'Imported fields', 'config' => [ 'fields' => $fields ] ];
			$connections[] = $this->connection( $seed . ':fields', 'entity_fields', $entity_id, $group_id );
		} else {
			$collection_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:' . $seed );
			$nodes[] = [ 'id' => $collection_id, 'type' => 'collection', 'lane' => 'experience', 'name' => 'Imported collection', 'config' => [ 'legacy' => true ] ];
			$connections[] = $this->connection( $seed . ':collection', 'collection_for', $entity_id, $collection_id );
		}

		return $this->blueprint( $blueprint_id, 'Imported ' . $name, 'legacy-' . $strategy . '-' . $slug, $nodes, $connections );
	}

	public function import_preset( $id, array $preset ) {
		$seed = 'filter-preset:' . sanitize_key( $id );
		$blueprint_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'blueprint:' . $seed );
		$adapter_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'adapter:' . $seed );
		$entity_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entity:' . $seed );
		$collection_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'collection:' . $seed );
		$filter_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'filter:' . $seed );
		$name = sanitize_text_field( $preset['name'] ?? $id );
		$fields = $this->preset_fields( $seed, $preset );
		$nodes = [
			[ 'id' => $adapter_id, 'type' => 'adapter', 'lane' => 'governance', 'name' => 'Legacy DOM', 'config' => [ 'adapter_id' => 'legacy_dom', 'read_only' => true ] ],
			[ 'id' => $entity_id, 'type' => 'entity', 'lane' => 'data', 'name' => $name, 'config' => [ 'owner' => 'external', 'adapter_id' => 'legacy_dom', 'slug' => sanitize_key( $id ), 'mode' => 'structured', 'legacy' => [ 'option' => FilterPresets::OPTION, 'key' => sanitize_key( $id ) ] ] ],
			[ 'id' => $collection_id, 'type' => 'collection', 'lane' => 'experience', 'name' => $name . ' collection', 'config' => [ 'provider' => 'legacy_dom', 'limit' => 200 ] ],
			[ 'id' => $filter_id, 'type' => 'filter_surface', 'lane' => 'experience', 'name' => $name . ' filters', 'config' => [ 'legacy' => [ 'target_selector' => $preset['target_selector'] ?? '', 'item_selector' => $preset['item_selector'] ?? '' ], 'filters' => $preset['filters'] ?? [] ] ],
		];
		$connections = [
			$this->connection( $seed . ':adapter', 'adapts', $adapter_id, $entity_id ),
			$this->connection( $seed . ':collection', 'collection_for', $entity_id, $collection_id ),
			$this->connection( $seed . ':filters', 'filters', $collection_id, $filter_id ),
		];
		if ( $fields ) {
			$group_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'field-group:' . $seed );
			$nodes[] = [ 'id' => $group_id, 'type' => 'field_group', 'lane' => 'data', 'name' => 'Imported filter bindings', 'config' => [ 'fields' => $fields ] ];
			$connections[] = $this->connection( $seed . ':fields', 'entity_fields', $entity_id, $group_id );
		}
		return $this->blueprint( $blueprint_id, 'Imported ' . $name, 'legacy-filter-' . $id, $nodes, $connections );
	}

	private function cpt_fields( $seed, array $definition ) {
		$fields = [];
		foreach ( $definition['meta_fields'] ?? [] as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );
			if ( '' !== $key ) {
				$fields[] = $this->legacy_field( $seed, $key, $field['label'] ?? $key, $this->primitive( $field['type'] ?? 'text' ), $field );
			}
		}
		foreach ( $definition['taxonomies'] ?? [] as $taxonomy ) {
			$key = sanitize_key( $taxonomy['slug'] ?? '' );
			if ( '' !== $key ) {
				$fields[] = $this->legacy_field( $seed, $key, $taxonomy['singular'] ?? $key, 'taxonomy', [ 'show_in_rest' => $taxonomy['show_in_rest'] ?? false ] );
			}
		}
		return $fields;
	}

	private function cct_fields( $seed, array $definition ) {
		$fields = [];
		foreach ( $definition['fields'] ?? [] as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );
			if ( '' !== $key && ! empty( $field['active'] ) ) {
				$fields[] = $this->legacy_field( $seed, $key, $field['label'] ?? $key, $this->primitive( $field['type'] ?? 'text' ), $field );
			}
		}
		return $fields;
	}

	private function preset_fields( $seed, array $preset ) {
		$fields = [];
		$seen = [];
		foreach ( $preset['filters'] ?? [] as $index => $filter ) {
			$key = sanitize_key( $filter['resolved_key'] ?? $filter['key'] ?? $filter['query_var'] ?? 'filter_' . $index );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$type = 'range' === ( $filter['type'] ?? '' ) ? 'decimal' : ( in_array( $filter['type'] ?? '', [ 'checkbox', 'chips' ], true ) ? 'multiple_choice' : 'short_text' );
			$fields[] = $this->legacy_field( $seed, $key, $filter['label'] ?? $key, $type, [ 'filterable' => true ] );
		}
		return $fields;
	}

	private function legacy_field( $seed, $key, $label, $type, array $legacy ) {
		$id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'field:' . $seed . ':' . $key );
		return $this->factory->make(
			$id,
			$label,
			$type,
			[
				'validation' => [ 'required' => ! empty( $legacy['required'] ) ],
				'exposure' => [ 'public' => ! empty( $legacy['show_in_rest'] ) || ! empty( $legacy['public'] ) ],
				'storage' => [ 'key' => $key, 'aliases' => [ $key ] ],
				'indexing' => [ 'filter' => ! empty( $legacy['filterable'] ), 'sort' => ! empty( $legacy['sortable'] ) ],
			]
		);
	}

	private function primitive( $legacy_type ) {
		$map = [
			'text' => 'short_text', 'textarea' => 'long_text', 'number' => 'decimal', 'url' => 'url', 'email' => 'email',
			'date' => 'date', 'time' => 'time', 'datetime' => 'datetime', 'checkbox' => 'boolean', 'boolean' => 'boolean',
			'select' => 'single_choice', 'radio' => 'single_choice', 'multiselect' => 'multiple_choice', 'image' => 'image', 'gallery' => 'gallery',
		];
		return $map[ sanitize_key( $legacy_type ) ] ?? 'short_text';
	}

	private function connection( $seed, $type, $from, $to ) {
		return [ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'connection:' . $seed ), 'type' => $type, 'from' => $from, 'to' => $to ];
	}

	private function blueprint( $id, $name, $slug, array $nodes, array $connections ) {
		$blueprint = [ 'api_version' => BlueprintValidator::API_VERSION, 'kind' => BlueprintValidator::KIND, 'id' => $id, 'slug' => sanitize_title( $slug ), 'name' => $name, 'version' => 1, 'nodes' => $nodes, 'connections' => $connections, 'origin' => [ 'mode' => 'legacy_shadow', 'imported_at' => null ] ];
		$blueprint['checksum'] = $this->canonicalizer->checksum( $blueprint );
		return $blueprint;
	}

	private function legacy_snapshot() {
		return [
			'cpt' => $this->option_array( CptDefinitions::OPTION ),
			'cct' => $this->option_array( CctDefinitions::OPTION ),
			'filter_preset' => $this->option_array( FilterPresets::OPTION ),
		];
	}

	private function option_array( $option ) {
		$value = get_option( $option, [] );
		return is_array( $value ) ? $value : [];
	}

	private function snapshot_checksum( array $snapshot ) {
		return hash( 'sha256', wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
