<?php
/**
 * Compiles Collection and Filter Surface nodes into Field-ID query contracts.
 */

namespace EIT\Blueprint;

use EIT\Collection\CollectionFieldSemantics;
use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionContractCompiler {

	private $registries;
	private $semantics;

	public function __construct( RegistryHub $registries = null ) {
		$this->registries = $registries ?: ( new CoreRegistryFactory() )->create();
		$this->semantics = new CollectionFieldSemantics();
	}

	public function compile_collection( array $collection, array $nodes, array $connections, array $entities ) {
		$entity_id = $this->connected_id( $collection['id'], 'collection_for', 'from', $connections );
		$entity = $entities[ $entity_id ] ?? [];
		$fields = $this->entity_fields( $entity_id, $nodes, $connections );
		$config = $collection['config'] ?? [];
		$provider_id = $this->provider_id( $entity );
		$provider = $this->registries->collection_providers()->get( $provider_id );
		if ( ! $provider ) {
			return new \WP_Error( 'eit_collection_provider_missing', __( 'The Collection provider is not registered.', 'elementor-implementation-toolkit' ) );
		}
		$health = $provider->health_check();
		if ( ! is_array( $health ) || empty( $health['ok'] ) ) {
			return new \WP_Error( 'eit_collection_provider_unhealthy', __( 'The Collection provider is not available in this environment.', 'elementor-implementation-toolkit' ) );
		}
		$filter_id = $this->connected_id( $collection['id'], 'filters', 'to', $connections, 'from' );
		$filter_config = $nodes[ $filter_id ]['config'] ?? [];
		$policy_id = $this->connected_id( $collection['id'], 'governs_collection', 'from', $connections );
		$policy_config = $nodes[ $policy_id ]['config'] ?? [];
		$filter_ids = $this->selected_capability_fields( $fields, $filter_config['fields'] ?? [], 'filter' );
		$sort_ids = $this->selected_capability_fields( $fields, $config['sort_field_ids'] ?? [], 'sort' );
		$projection_ids = $this->projection_fields( $fields, $config['projection_field_ids'] ?? [] );

		return [
			'node_id' => $collection['id'],
			'collection_id' => $collection['id'],
			'name' => $collection['name'],
			'entity_id' => $entity_id,
			'entity' => [
				'strategy' => $entity['strategy'] ?? '',
				'definition' => $entity['definition'] ?? [],
				'adapter' => $entity['adapter'] ?? [],
			],
			'provider' => [
				'id' => $provider_id,
				'version' => $provider->get_version(),
				'capabilities' => $provider->get_capabilities(),
			],
			'fields' => array_values( $fields ),
			'projection_field_ids' => $projection_ids,
			'filter_field_ids' => $filter_ids,
			'sort_field_ids' => $sort_ids,
			'search_field_ids' => $this->capability_fields( $fields, 'search' ),
			'default_sort' => $this->default_sort( $config, $sort_ids ),
			'page_size' => min( 48, max( 1, absint( $config['page_size'] ?? 24 ) ) ),
			'access' => $this->access( $config, $entity ),
			'policy' => [
				'id' => $policy_id,
				'capability' => sanitize_key( $policy_config['read_capability'] ?? $policy_config['capability'] ?? 'read' ),
			],
			'cache' => [
				'enabled' => ! isset( $config['cache']['enabled'] ) || ! empty( $config['cache']['enabled'] ),
				'ttl_seconds' => min( 3600, max( 30, absint( $config['cache']['ttl_seconds'] ?? 300 ) ) ),
			],
			'filter_surface_id' => $filter_id,
			'explain' => ! isset( $config['explain'] ) || ! empty( $config['explain'] ),
		];
	}

	public function compile_filter( array $surface, array $nodes, array $connections, array $entities ) {
		$collection_id = $this->connected_id( $surface['id'], 'filters', 'from', $connections );
		$collection = $nodes[ $collection_id ] ?? [];
		$contract = $this->compile_collection( $collection, $nodes, $connections, $entities );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}
		$fields = array_column( $contract['fields'], null, 'id' );
		$config = $surface['config'] ?? [];
		$field_ids = $this->selected_capability_fields( $fields, $config['fields'] ?? [], 'filter' );
		$facet_ids = $this->facet_fields( $config, $field_ids, $fields );
		$controls = [];
		foreach ( $field_ids as $field_id ) {
			$field = $fields[ $field_id ];
			$controls[] = [
				'field_id' => $field_id,
				'label' => $field['name'],
				'control' => $this->semantics->control( $field ),
				'operators' => $this->semantics->operators( $field ),
				'options' => $this->options( $field ),
				'facet' => in_array( $field_id, $facet_ids, true ),
			];
		}

		return [
			'node_id' => $surface['id'],
			'surface_id' => $surface['id'],
			'name' => $surface['name'],
			'collection_id' => $collection_id,
			'controls' => $controls,
			'facet_field_ids' => $facet_ids,
			'sort_options' => $this->sort_options( $contract['sort_field_ids'], $fields ),
			'url_state' => ! isset( $config['url_state'] ) || ! empty( $config['url_state'] ),
			'active_chips' => ! isset( $config['active_chips'] ) || ! empty( $config['active_chips'] ),
		];
	}

	private function provider_id( array $entity ) {
		$strategy = $entity['strategy'] ?? '';
		if ( 'cpt' === $strategy ) {
			return 'wp_query';
		}
		if ( 'cct' === $strategy ) {
			return 'cct_indexed';
		}
		$adapter_id = sanitize_key( $entity['adapter']['id'] ?? '' );
		return 'woocommerce' === $adapter_id ? 'woocommerce' : 'legacy_dom';
	}

	private function entity_fields( $entity_id, array $nodes, array $connections ) {
		$fields = [];
		foreach ( $connections as $connection ) {
			if ( 'entity_fields' !== ( $connection['type'] ?? '' ) || $entity_id !== ( $connection['from'] ?? '' ) ) {
				continue;
			}
			foreach ( $nodes[ $connection['to'] ]['config']['fields'] ?? [] as $field ) {
				$fields[ $field['id'] ] = $field;
			}
		}
		return $fields;
	}

	private function selected_capability_fields( array $fields, $selected, $capability ) {
		$available = $this->capability_fields( $fields, $capability );
		$selected = is_array( $selected ) ? array_values( array_filter( array_map( 'strval', $selected ) ) ) : [];
		return $selected ? array_values( array_intersect( $selected, $available ) ) : $available;
	}

	private function capability_fields( array $fields, $capability ) {
		$result = [];
		foreach ( $fields as $field ) {
			if ( ! empty( $field['capabilities'][ $capability ] ) && ! empty( $field['indexing'][ $capability ] ) ) {
				$result[] = $field['id'];
			}
		}
		return $result;
	}

	private function projection_fields( array $fields, $selected ) {
		$available = array_keys( $fields );
		$selected = is_array( $selected ) ? array_values( array_filter( array_map( 'strval', $selected ) ) ) : [];
		return $selected ? array_values( array_intersect( $selected, $available ) ) : $available;
	}

	private function default_sort( array $config, array $sort_ids ) {
		$field_id = (string) ( $config['default_sort']['field_id'] ?? '' );
		return [
			'field_id' => in_array( $field_id, $sort_ids, true ) ? $field_id : '',
			'direction' => 'desc' === strtolower( (string) ( $config['default_sort']['direction'] ?? '' ) ) ? 'desc' : 'asc',
		];
	}

	private function access( array $config, array $entity ) {
		$access = sanitize_key( $config['access'] ?? '' );
		if ( in_array( $access, [ 'public', 'authenticated' ], true ) ) {
			return $access;
		}
		return ! empty( $entity['definition']['public'] ) ? 'public' : 'authenticated';
	}

	private function facet_fields( array $config, array $field_ids, array $fields ) {
		$configured = is_array( $config['facet_fields'] ?? null ) ? $config['facet_fields'] : [];
		if ( $configured ) {
			return array_slice( array_values( array_intersect( $configured, $field_ids ) ), 0, 10 );
		}
		$result = [];
		foreach ( $field_ids as $field_id ) {
			if ( in_array( $fields[ $field_id ]['type'] ?? '', [ 'boolean', 'single_choice', 'multiple_choice', 'taxonomy', 'relation' ], true ) ) {
				$result[] = $field_id;
			}
		}
		return array_slice( $result, 0, 10 );
	}

	private function options( array $field ) {
		$options = [];
		foreach ( $field['validation']['options'] ?? [] as $option ) {
			$option = is_array( $option ) ? $option : [ 'value' => $option, 'label' => $option ];
			$options[] = [ 'value' => (string) ( $option['value'] ?? '' ), 'label' => (string) ( $option['label'] ?? $option['value'] ?? '' ) ];
		}
		return $options;
	}

	private function sort_options( array $field_ids, array $fields ) {
		$options = [];
		foreach ( $field_ids as $field_id ) {
			$options[] = [ 'field_id' => $field_id, 'label' => $fields[ $field_id ]['name'], 'directions' => [ 'asc', 'desc' ] ];
		}
		return $options;
	}

	private function connected_id( $node_id, $type, $side, array $connections, $match_side = 'to' ) {
		foreach ( $connections as $connection ) {
			if ( $type === ( $connection['type'] ?? '' ) && $node_id === ( $connection[ $match_side ] ?? '' ) ) {
				return $connection[ $side ] ?? '';
			}
		}
		return '';
	}
}
