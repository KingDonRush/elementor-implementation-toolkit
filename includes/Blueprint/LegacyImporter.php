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
	private $elementor;

	public function __construct( ?FieldContractFactory $factory = null, ?Canonicalizer $canonicalizer = null, ?ElementorDocumentImporter $elementor = null ) {
		$this->factory = $factory ?: new FieldContractFactory( new FieldPrimitiveRegistry() );
		$this->canonicalizer = $canonicalizer ?: new Canonicalizer();
		$this->elementor = $elementor ?: new ElementorDocumentImporter( $this->canonicalizer );
	}

	public function inspect_all() {
		$before = $this->legacy_snapshot();
		$result = [ 'cpt' => [], 'cct' => [], 'filter_preset' => [], 'elementor_document' => [] ];
		foreach ( $before['cpt'] as $slug => $definition ) {
			$result['cpt'][ $slug ] = $this->import_entity( 'cpt', $slug, $definition );
		}
		foreach ( $before['cct'] as $slug => $definition ) {
			$result['cct'][ $slug ] = $this->import_entity( 'cct', $slug, $definition );
		}
		foreach ( $before['filter_preset'] as $id => $preset ) {
			$result['filter_preset'][ $id ] = $this->import_preset( $id, $preset );
		}
		$result['elementor_document'] = $this->elementor->inspect_all();
		$after = $this->legacy_snapshot();

		return [
			'mode' => 'shadow_read_only',
			'read_only' => hash_equals( $this->snapshot_checksum( $before ), $this->snapshot_checksum( $after ) ),
			'source_checksum' => $this->snapshot_checksum( $before ),
			'blueprints' => $result,
		];
	}

	public function candidate( $source_type, $source_key ) {
		$source_type = sanitize_key( $source_type );
		if ( 'elementor_document' === $source_type ) {
			$blueprint = $this->elementor->import( absint( $source_key ) );
			$post = get_post( absint( $source_key ) );
			$raw = $post ? [ 'id' => $post->ID, 'type' => $post->post_type, 'status' => $post->post_status, 'modified' => $post->post_modified_gmt, 'data' => get_post_meta( $post->ID, '_elementor_data', true ) ] : null;
			return $blueprint ? [ 'blueprint' => $blueprint, 'source_checksum' => $this->value_checksum( $raw ) ] : null;
		}

		$options = [
			'cpt' => CptDefinitions::OPTION,
			'cct' => CctDefinitions::OPTION,
			'filter_preset' => FilterPresets::OPTION,
		];
		if ( ! isset( $options[ $source_type ] ) ) {
			return null;
		}
		$records = $this->option_array( $options[ $source_type ] );
		$key = sanitize_key( $source_key );
		if ( ! isset( $records[ $key ] ) || ! is_array( $records[ $key ] ) ) {
			return null;
		}
		$blueprint = 'filter_preset' === $source_type
			? $this->import_preset( $key, $records[ $key ] )
			: $this->import_entity( $source_type, $key, $records[ $key ] );
		return [ 'blueprint' => $blueprint, 'source_checksum' => $this->value_checksum( $records[ $key ] ) ];
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
		$supports = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $definition['supports'] ?? [] ) ) ) ) );
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
					'description' => sanitize_textarea_field( $definition['description'] ?? '' ),
					'menu_icon' => sanitize_text_field( $definition['menu_icon'] ?? ( 'cpt' === $strategy ? 'dashicons-screenoptions' : 'dashicons-database' ) ),
					'public' => ! empty( $definition['public'] ),
					'show_in_rest' => 'cpt' === $strategy && ! empty( $definition['show_in_rest'] ),
					'routed' => 'cpt' === $strategy && ! empty( $definition['has_archive'] ),
					'hierarchical' => 'cpt' === $strategy && ! empty( $definition['hierarchical'] ),
					'route_slug' => 'cpt' === $strategy ? sanitize_title( $definition['rewrite_slug'] ?? '' ) : '',
					'supports' => 'cpt' === $strategy ? $supports : [],
					'versioned' => 'cpt' === $strategy && in_array( 'revisions', $definition['supports'] ?? [], true ),
					'mode' => 'cpt' === $strategy && in_array( 'editor', $definition['supports'] ?? [], true ) ? 'hybrid' : 'structured',
					'state' => 'cct' === $strategy && 'archived' === ( $definition['state'] ?? '' ) ? 'archived' : 'active',
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
				$fields[] = $this->legacy_field( $seed, $key, $taxonomy['singular'] ?? $key, 'taxonomy', $taxonomy );
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
		$contract = $this->factory->make(
			$id,
			$label,
			$type,
			[
				'validation' => [
					'required' => ! empty( $legacy['required'] ),
					'default' => $legacy['default'] ?? '',
					'options' => $this->legacy_options( $legacy['options'] ?? '' ),
				],
				'exposure' => [ 'public' => array_key_exists( 'public', $legacy ) ? ! empty( $legacy['public'] ) : ! empty( $legacy['show_in_rest'] ) ],
				'storage' => [ 'key' => $key, 'aliases' => [ $key ] ],
			]
		);
		if ( 'taxonomy' === $type ) {
			$contract['taxonomy'] = [
				'slug' => $key,
				'singular' => sanitize_text_field( $legacy['singular'] ?? $label ),
				'plural' => sanitize_text_field( $legacy['plural'] ?? $label ),
				'hierarchical' => ! empty( $legacy['hierarchical'] ),
				'public' => ! empty( $legacy['public'] ),
				'show_in_rest' => ! empty( $legacy['show_in_rest'] ),
			];
		}
		$requested = [ 'filter' => ! empty( $legacy['filterable'] ), 'sort' => ! empty( $legacy['sortable'] ) ];
		$downgrades = [];
		foreach ( $requested as $capability => $enabled ) {
			$supported = ! empty( $contract['capabilities'][ $capability ] );
			$contract['indexing'][ $capability ] = $enabled && $supported;
			if ( $enabled && ! $supported ) {
				$downgrades[] = $capability;
			}
		}
		if ( $downgrades ) {
			$contract['migration'] = [ 'capability_downgrades' => $downgrades, 'reason' => 'Legacy intent is incompatible with the selected semantic primitive.' ];
		}
		return $contract;
	}

	private function primitive( $legacy_type ) {
		$map = [
			'text' => 'short_text', 'textarea' => 'long_text', 'number' => 'decimal', 'url' => 'url', 'email' => 'email',
			'date' => 'date', 'time' => 'time', 'datetime' => 'datetime', 'checkbox' => 'boolean', 'boolean' => 'boolean',
			'select' => 'single_choice', 'radio' => 'single_choice', 'multiselect' => 'multiple_choice', 'image' => 'image', 'gallery' => 'gallery',
			'color' => 'color',
		];
		return $map[ sanitize_key( $legacy_type ) ] ?? 'short_text';
	}

	private function legacy_options( $raw ) {
		$options = [];
		foreach ( is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw ) as $option ) {
			if ( is_array( $option ) ) {
				$value = sanitize_key( $option['value'] ?? '' );
				$label = sanitize_text_field( $option['label'] ?? $value );
			} else {
				$parts = array_map( 'trim', explode( '|', (string) $option, 2 ) );
				$value = sanitize_key( $parts[0] ?? '' );
				$label = sanitize_text_field( $parts[1] ?? $value );
			}
			if ( '' !== $value ) {
				$options[] = [ 'value' => $value, 'label' => $label ?: $value ];
			}
		}
		return $options;
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
			'elementor_document' => $this->elementor->snapshot(),
		];
	}

	private function option_array( $option ) {
		$value = get_option( $option, [] );
		return is_array( $value ) ? $value : [];
	}

	private function snapshot_checksum( array $snapshot ) {
		return $this->value_checksum( $snapshot );
	}

	private function value_checksum( $value ) {
		return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
