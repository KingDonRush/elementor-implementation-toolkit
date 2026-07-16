<?php
/**
 * Compiles Collection and Filter Surface nodes into Field-ID query contracts.
 */

namespace EIT\Blueprint;

use EIT\Collection\CollectionFieldSemantics;
use EIT\Registry\ExtensionContract;
use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionContractCompiler {

	private $registries;
	private $semantics;
	private $requirements;

	public function __construct( RegistryHub $registries = null, CollectionProviderRequirements $requirements = null ) {
		$this->registries = $registries ?: ( new CoreRegistryFactory() )->create();
		$this->semantics = new CollectionFieldSemantics();
		$this->requirements = $requirements ?: new CollectionProviderRequirements();
	}

	public function compile_collection( array $collection, array $nodes, array $connections, array $entities ) {
		$entity_id = $this->connected_id( $collection['id'], 'collection_for', 'from', $connections );
		$entity = $entities[ $entity_id ] ?? [];
		$fields = array_column( $entity['fields'] ?? [], null, 'id' );
		if ( ! $fields ) {
			$fields = $this->entity_fields( $entity_id, $nodes, $connections );
		}
		$fields = array_filter( $fields, [ $this, 'is_public_field' ] );
		$config = $collection['config'] ?? [];
		$explicit_provider = $this->explicit_provider( $entity_id, $nodes, $connections );
		$filter_id = $this->connected_id( $collection['id'], 'filters', 'to', $connections, 'from' );
		$filter_config = $nodes[ $filter_id ]['config'] ?? [];
		$filter_config['_connected'] = (bool) $filter_id;
		$provider_id = ! empty( $explicit_provider['configured'] ) && $this->valid_provider_config( $explicit_provider['contract'] ?? null )
			? (string) $explicit_provider['contract']['id']
			: $this->provider_id( $entity );
		$declared = ! empty( $explicit_provider['configured'] ) && $this->valid_provider_config( $explicit_provider['contract'] ?? null )
			? $this->capabilities( $explicit_provider['contract']['required_capabilities'] ?? [] )
			: [];
		$plan = $this->requirements->plan( $provider_id, $config, $filter_config, array_values( $fields ), $declared );
		$provider_contract = $this->provider_contract( $explicit_provider, $entity, $plan['required_capabilities'] );
		if ( is_wp_error( $provider_contract ) ) {
			return $provider_contract;
		}
		$policy_id = $this->connected_id( $collection['id'], 'governs_collection', 'from', $connections );
		$policy_config = $nodes[ $policy_id ]['config'] ?? [];
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
			'provider' => $provider_contract,
			'fields' => array_values( $fields ),
			'projection_field_ids' => $projection_ids,
			'filter_field_ids' => $plan['filter_field_ids'],
			'sort_field_ids' => $plan['sort_field_ids'],
			'search_field_ids' => $plan['search_field_ids'],
			'default_sort' => $this->default_sort( $config, $plan['sort_field_ids'] ),
			'page_size' => min( 48, max( 1, absint( $config['page_size'] ?? 24 ) ) ),
			'access' => $this->access( $config, $entity ),
			'policy' => $this->policy( $policy_id, $policy_config ),
			'cache' => [
				'enabled' => ! isset( $config['cache']['enabled'] ) || ! empty( $config['cache']['enabled'] ),
				'ttl_seconds' => min( 3600, max( 30, absint( $config['cache']['ttl_seconds'] ?? 300 ) ) ),
			],
			'filter_surface_id' => $filter_id,
			'presentation' => $this->presentation( $collection['id'], $nodes, $connections ),
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
		$config['_connected'] = true;
		$field_ids = $contract['filter_field_ids'];
		$plan = $this->requirements->plan( $contract['provider']['id'] ?? '', [], $config, array_values( $fields ) );
		$facet_ids = $plan['facet_field_ids'];
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
			'apply_mode' => 'submit' === sanitize_key( $config['apply_mode'] ?? '' ) ? 'submit' : 'automatic',
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

	private function explicit_provider( $entity_id, array $nodes, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( 'adapts' !== ( $connection['type'] ?? '' ) || $entity_id !== ( $connection['to'] ?? '' ) ) {
				continue;
			}
			$config = $nodes[ $connection['from'] ]['config'] ?? [];
			if ( array_key_exists( 'collection_provider', $config ) ) {
				return [ 'configured' => true, 'contract' => $config['collection_provider'] ];
			}
		}
		$config = $nodes[ $entity_id ]['config'] ?? [];
		return array_key_exists( 'collection_provider', $config )
			? [ 'configured' => true, 'contract' => $config['collection_provider'] ]
			: [ 'configured' => false, 'contract' => null ];
	}

	private function provider_contract( array $explicit, array $entity, array $required ) {
		$configured = ! empty( $explicit['configured'] );
		$contract = $explicit['contract'] ?? null;
		if ( $configured && ! $this->valid_provider_config( $contract ) ) {
			return new \WP_Error( 'eit_collection_provider_contract_invalid', __( 'Explicit Collection provider configuration is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$provider_id = $configured ? (string) $contract['id'] : $this->provider_id( $entity );
		$provider = $this->registries->collection_providers()->get( $provider_id );
		if ( ! $provider ) {
			return new \WP_Error( 'eit_collection_provider_missing', __( 'The Collection provider is not registered.', 'elementor-implementation-toolkit' ) );
		}
		$snapshot = ExtensionContract::snapshot( $provider );
		if ( is_wp_error( $snapshot ) && 'eit_extension_unavailable' === $snapshot->get_error_code() ) {
			return new \WP_Error( 'eit_collection_provider_unhealthy', __( 'The Collection provider is not available in this environment.', 'elementor-implementation-toolkit' ) );
		}
		if ( is_wp_error( $snapshot ) || ! hash_equals( $provider_id, (string) ( $snapshot['id'] ?? '' ) ) ) {
			return new \WP_Error( 'eit_collection_provider_incompatible', __( 'The Collection provider metadata is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$version = $snapshot['version'];
		$capabilities = $snapshot['capabilities'];
		if ( null === $capabilities || ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) || array_diff( $required, $capabilities ) ) {
			return new \WP_Error( 'eit_collection_provider_incompatible', __( 'The Collection provider cannot execute every operation required by this Collection.', 'elementor-implementation-toolkit' ) );
		}
		return [ 'id' => $provider_id, 'version' => $version, 'capabilities' => $capabilities, 'required_capabilities' => $required, 'selection' => $configured ? 'explicit' : 'automatic' ];
	}

	private function valid_provider_config( $contract ) {
		if ( ! is_array( $contract ) || array_is_list( $contract ) || array_diff( array_keys( $contract ), [ 'id', 'required_capabilities' ] ) ) {
			return false;
		}
		$id = (string) ( $contract['id'] ?? '' );
		return (bool) preg_match( '/^[a-z][a-z0-9_.-]{1,63}$/', $id ) && null !== $this->capabilities( $contract['required_capabilities'] ?? [] );
	}

	private function capabilities( $capabilities ) {
		if ( ! is_array( $capabilities ) || ! array_is_list( $capabilities ) || count( $capabilities ) > 32 ) {
			return null;
		}
		$result = [];
		foreach ( $capabilities as $capability ) {
			if ( ! is_string( $capability ) || ! preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $capability ) || isset( $result[ $capability ] ) ) {
				return null;
			}
			$result[ $capability ] = true;
		}
		$result = array_keys( $result );
		sort( $result, SORT_STRING );
		return $result;
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

	private function is_public_field( array $field ) {
		return ! empty( $field['exposure']['public'] );
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

	private function policy( $policy_id, array $config ) {
		$ownership = sanitize_key( $config['ownership'] ?? 'any' );
		$object_scope = sanitize_key( $config['object_scope'] ?? 'entity' );
		return [
			'id' => $policy_id,
			'capability' => sanitize_key( $config['read_capability'] ?? $config['capability'] ?? 'read' ),
			'ownership' => in_array( $ownership, [ 'own', 'any' ], true ) ? $ownership : 'any',
			'object_scope' => in_array( $object_scope, [ 'entity', 'assigned' ], true ) ? $object_scope : 'entity',
		];
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

	private function presentation( $collection_id, array $nodes, array $connections ) {
		$presentation_id = $this->connected_id( $collection_id, 'presents_collection', 'to', $connections, 'from' );
		$presentation = $nodes[ $presentation_id ] ?? [];
		return $presentation ? [
			'node_id' => $presentation_id,
			'adapter' => sanitize_key( $presentation['config']['adapter'] ?? 'elementor' ),
			'template_id' => absint( $presentation['config']['template_id'] ?? 0 ),
		] : null;
	}
}
