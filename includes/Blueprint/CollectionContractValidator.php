<?php
/**
 * Validates Collection and derived Filter Surface decisions.
 */

namespace EIT\Blueprint;

use EIT\Contracts\FieldContractSourceInterface;
use EIT\Registry\ExtensionContract;
use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionContractValidator {

	private $registries;
	private $requirements;

	public function __construct( RegistryHub $registries = null, CollectionProviderRequirements $requirements = null ) {
		$this->registries = $registries ?: ( new CoreRegistryFactory() )->create();
		$this->requirements = $requirements ?: new CollectionProviderRequirements();
	}

	public function validate( array $nodes, array $connections ) {
		$errors = [];
		foreach ( $nodes as $node_id => $node ) {
			if ( 'collection' === ( $node['type'] ?? '' ) ) {
				$this->validate_collection( $node_id, $node, $nodes, $connections, $errors );
			} elseif ( 'filter_surface' === ( $node['type'] ?? '' ) ) {
				$this->validate_filter( $node_id, $node, $nodes, $connections, $errors );
			}
		}
		return $errors;
	}

	private function validate_collection( $node_id, array $node, array $nodes, array $connections, array &$errors ) {
		$config = $node['config'] ?? [];
		$page_size = absint( $config['page_size'] ?? 24 );
		if ( $page_size < 1 || $page_size > 48 ) {
			$errors[] = $this->error( 'collection_page_size_invalid', 'Collection page size must be between one and 48.', $node_id );
		}
		$access = sanitize_key( $config['access'] ?? '' );
		if ( '' !== $access && ! in_array( $access, [ 'public', 'authenticated' ], true ) ) {
			$errors[] = $this->error( 'collection_access_invalid', 'Collection access must be public or authenticated.', $node_id );
		}
		$ttl = absint( $config['cache']['ttl_seconds'] ?? 300 );
		if ( isset( $config['cache']['ttl_seconds'] ) && ( $ttl < 30 || $ttl > 3600 ) ) {
			$errors[] = $this->error( 'collection_cache_ttl_invalid', 'Collection cache lifetime must be between 30 and 3600 seconds.', $node_id );
		}
		$entity_id = $this->connected_id( $node_id, 'collection_for', 'from', $connections );
		$entity = $nodes[ $entity_id ] ?? [];
		if ( 'public' === $access && empty( $entity['config']['public'] ) ) {
			$errors[] = $this->error( 'collection_public_entity_required', 'A public Collection requires a public Entity.', $node_id );
		}
		$fields = $this->entity_fields( $entity_id, $nodes, $connections );
		$explicit = $this->explicit_provider( $entity_id, $nodes, $connections );
		$provider_id = $this->provider_id( $entity_id, $nodes, $connections, $explicit );
		$filter_id = $this->connection_target( $node_id, 'filters', $connections );
		$filter_config = $nodes[ $filter_id ]['config'] ?? [];
		$filter_config['_connected'] = (bool) $filter_id;
		$declared = ! empty( $explicit['configured'] ) && $this->valid_provider_config( $explicit['contract'] ?? null )
			? $this->capabilities( $explicit['contract']['required_capabilities'] ?? [] )
			: [];
		$plan = $this->requirements->plan( $provider_id, $config, $filter_config, array_values( $fields ), $declared );
		$this->validate_provider( $provider_id, $node_id, $explicit, $plan['required_capabilities'], $errors );
		$this->validate_refs( $config['projection_field_ids'] ?? [], $fields, '', 'collection_projection_invalid', $node_id, $errors, true );
		$this->validate_refs( $config['sort_field_ids'] ?? [], $fields, 'sort', 'collection_sort_field_invalid', $node_id, $errors, true );
		$default_sort = (string) ( $config['default_sort']['field_id'] ?? '' );
		if ( '' !== $default_sort ) {
			$this->validate_refs( [ $default_sort ], $fields, 'sort', 'collection_default_sort_invalid', $node_id, $errors, true );
		}
		$direction = strtolower( (string) ( $config['default_sort']['direction'] ?? 'asc' ) );
		if ( ! in_array( $direction, [ 'asc', 'desc' ], true ) ) {
			$errors[] = $this->error( 'collection_sort_direction_invalid', 'Collection sort direction must be ascending or descending.', $node_id );
		}
		$this->validate_policy( $node_id, $access ?: ( ! empty( $entity['config']['public'] ) ? 'public' : 'authenticated' ), $nodes, $connections, $errors );
	}

	private function validate_filter( $node_id, array $node, array $nodes, array $connections, array &$errors ) {
		$config = $node['config'] ?? [];
		if ( isset( $config['apply_mode'] ) && ! in_array( $config['apply_mode'], [ 'automatic', 'submit' ], true ) ) {
			$errors[] = $this->error( 'filter_surface_apply_mode_invalid', 'Filter Surface apply mode must be automatic or submit.', $node_id );
		}
		$collection_id = $this->connected_id( $node_id, 'filters', 'from', $connections );
		$entity_id = $this->connected_id( $collection_id, 'collection_for', 'from', $connections );
		$fields = $this->entity_fields( $entity_id, $nodes, $connections );
		$provider_id = $this->provider_id( $entity_id, $nodes, $connections, $this->explicit_provider( $entity_id, $nodes, $connections ) );
		$selected = $config['fields'] ?? [];
		if ( ! $this->valid_reference_list( $selected, 20 ) ) {
			$errors[] = $this->error( 'filter_surface_fields_invalid', 'Filter Surface fields must be a unique list of at most 20 Field IDs.', $node_id );
		} else {
			$this->validate_refs( $selected, $fields, 'filter', 'filter_surface_field_invalid', $node_id, $errors, true );
		}
		$facets = $config['facet_fields'] ?? [];
		if ( ! $this->valid_reference_list( $facets, 10 ) || array_diff( $facets, $selected ?: array_keys( $fields ) ) ) {
			$errors[] = $this->error( 'filter_surface_facets_invalid', 'Facet fields must be a subset of at most ten filter fields.', $node_id );
		} else {
			$this->validate_refs( $facets, $fields, 'filter', 'filter_surface_facet_invalid', $node_id, $errors, true );
			foreach ( $facets as $field_id ) {
				if ( isset( $fields[ $field_id ] ) && ! $this->requirements->facet_supported( $provider_id, $fields[ $field_id ] ) ) {
					$errors[] = $this->error( 'filter_surface_facet_unsupported', 'This provider cannot produce indexed counts for the selected facet Field.', $node_id );
				}
			}
		}
	}

	private function validate_refs( $references, array $fields, $capability, $code, $node_id, array &$errors, $require_public = false ) {
		if ( ! $this->valid_reference_list( $references, 80 ) ) {
			$errors[] = $this->error( $code, 'Collection field references must be a list.', $node_id );
			return;
		}
		foreach ( $references as $field_id ) {
			$field = $fields[ (string) $field_id ] ?? null;
			if ( ! $field || ( $require_public && empty( $field['exposure']['public'] ) ) || ( '' !== $capability && ( empty( $field['capabilities'][ $capability ] ) || empty( $field['indexing'][ $capability ] ) ) ) ) {
				$errors[] = $this->error( $code, 'Collection references a Field without the required query capability.', $node_id );
			}
		}
	}

	private function validate_policy( $collection_id, $access, array $nodes, array $connections, array &$errors ) {
		$policy_id = $this->connected_id( $collection_id, 'governs_collection', 'from', $connections );
		if ( ! $policy_id || ! isset( $nodes[ $policy_id ] ) ) {
			return;
		}
		$config = $nodes[ $policy_id ]['config'] ?? [];
		$ownership = $config['ownership'] ?? 'any';
		$object_scope = $config['object_scope'] ?? 'entity';
		if ( ! in_array( $ownership, [ 'own', 'any' ], true ) || ! in_array( $object_scope, [ 'entity', 'assigned' ], true ) ) {
			$errors[] = $this->error( 'collection_policy_scope_invalid', 'Collection Policy ownership or object scope is invalid.', $collection_id );
		}
		if ( 'public' === $access && ( 'own' === $ownership || 'assigned' === $object_scope ) ) {
			$errors[] = $this->error( 'collection_public_policy_scope_invalid', 'A public Collection cannot use user-specific ownership or assignment scope.', $collection_id );
		}
	}

	private function validate_provider( $provider_id, $collection_id, array $explicit, array $required, array &$errors ) {
		$contract = $explicit['contract'] ?? null;
		if ( ! empty( $explicit['configured'] ) && ! $this->valid_provider_config( $contract ) ) {
			$errors[] = $this->error( 'collection_provider_contract_invalid', 'Explicit Collection provider configuration accepts only a stable ID and required capabilities.', $collection_id );
			return;
		}
		$provider = $this->registries->collection_providers()->get( $provider_id );
		if ( ! $provider ) {
			$errors[] = $this->error( 'collection_provider_missing', 'Collection provider is not registered.', $collection_id );
			return;
		}
		$snapshot = ExtensionContract::snapshot( $provider );
		if ( is_wp_error( $snapshot ) && 'eit_extension_unavailable' === $snapshot->get_error_code() ) {
			$errors[] = $this->error( 'collection_provider_unhealthy', 'Explicit Collection provider failed its health check.', $collection_id );
			return;
		}
		if ( is_wp_error( $snapshot ) || ! hash_equals( (string) $provider_id, (string) ( $snapshot['id'] ?? '' ) ) ) {
			$errors[] = $this->error( 'collection_provider_incompatible', 'Collection provider metadata is incompatible.', $collection_id );
			return;
		}
		$version = $snapshot['version'];
		$capabilities = $snapshot['capabilities'];
		if ( null === $capabilities || ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) || array_diff( $required, $capabilities ) ) {
			$errors[] = $this->error( 'collection_provider_incompatible', 'Collection provider cannot execute every operation required by this Collection.', $collection_id );
		}
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

	private function valid_reference_list( $references, $limit ) {
		if ( ! is_array( $references ) || ! array_is_list( $references ) || count( $references ) > $limit ) {
			return false;
		}
		$strings = [];
		foreach ( $references as $reference ) {
			if ( ! is_string( $reference ) || '' === trim( $reference ) ) {
				return false;
			}
			$strings[] = $reference;
		}
		return count( $strings ) === count( array_unique( $strings ) );
	}

	private function entity_fields( $entity_id, array $nodes, array $connections ) {
		$adapter = $this->entity_adapter( $entity_id, $nodes, $connections );
		if ( $adapter instanceof FieldContractSourceInterface ) {
			return array_column( $adapter->get_field_contracts( $nodes[ $entity_id ] ?? [] ), null, 'id' );
		}
		$fields = [];
		foreach ( $connections as $connection ) {
			if ( 'entity_fields' !== ( $connection['type'] ?? '' ) || $entity_id !== ( $connection['from'] ?? '' ) ) {
				continue;
			}
			foreach ( $nodes[ $connection['to'] ]['config']['fields'] ?? [] as $field ) {
				$fields[ $field['id'] ?? '' ] = $field;
			}
		}
		return $fields;
	}

	private function entity_adapter( $entity_id, array $nodes, array $connections ) {
		$adapter_id = $this->entity_adapter_id( $entity_id, $nodes, $connections );
		return $this->registries->storage_adapters()->get( $adapter_id );
	}

	private function entity_adapter_id( $entity_id, array $nodes, array $connections ) {
		$adapter_id = sanitize_key( $nodes[ $entity_id ]['config']['adapter_id'] ?? '' );
		foreach ( $connections as $connection ) {
			if ( 'adapts' !== ( $connection['type'] ?? '' ) || $entity_id !== ( $connection['to'] ?? '' ) ) {
				continue;
			}
			$adapter_id = sanitize_key( $nodes[ $connection['from'] ]['config']['adapter_id'] ?? '' );
			break;
		}
		return $adapter_id;
	}

	private function provider_id( $entity_id, array $nodes, array $connections, array $explicit ) {
		if ( ! empty( $explicit['configured'] ) && $this->valid_provider_config( $explicit['contract'] ?? null ) ) {
			return (string) $explicit['contract']['id'];
		}
		$adapter_id = $this->entity_adapter_id( $entity_id, $nodes, $connections );
		if ( 'woocommerce' === $adapter_id ) {
			return 'woocommerce';
		}
		if ( '' !== $adapter_id && ! in_array( $adapter_id, [ 'cpt', 'cct' ], true ) ) {
			return 'legacy_dom';
		}
		$recommendation = ( new StorageRecommendation() )->recommend( $nodes[ $entity_id ] ?? [] );
		return 'cct' === ( $recommendation['selected'] ?? '' ) ? 'cct_indexed' : 'wp_query';
	}

	private function connected_id( $node_id, $type, $side, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( $type === ( $connection['type'] ?? '' ) && $node_id === ( $connection['to'] ?? '' ) ) {
				return $connection[ $side ] ?? '';
			}
		}
		return '';
	}

	private function connection_target( $node_id, $type, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( $type === ( $connection['type'] ?? '' ) && $node_id === ( $connection['from'] ?? '' ) ) {
				return $connection['to'] ?? '';
			}
		}
		return '';
	}

	private function error( $code, $message, $node_id ) {
		return [ 'path' => 'nodes', 'code' => $code, 'message' => $message, 'node_id' => $node_id ];
	}
}
