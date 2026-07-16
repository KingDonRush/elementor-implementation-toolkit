<?php
/**
 * Validates Collection and derived Filter Surface decisions.
 */

namespace EIT\Blueprint;

use EIT\Contracts\FieldContractSourceInterface;
use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionContractValidator {

	private $registries;

	public function __construct( RegistryHub $registries = null ) {
		$this->registries = $registries ?: ( new CoreRegistryFactory() )->create();
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
		$this->validate_refs( $config['projection_field_ids'] ?? [], $fields, '', 'collection_projection_invalid', $node_id, $errors );
		$this->validate_refs( $config['sort_field_ids'] ?? [], $fields, 'sort', 'collection_sort_field_invalid', $node_id, $errors );
		$default_sort = (string) ( $config['default_sort']['field_id'] ?? '' );
		if ( '' !== $default_sort ) {
			$this->validate_refs( [ $default_sort ], $fields, 'sort', 'collection_default_sort_invalid', $node_id, $errors );
		}
		$direction = strtolower( (string) ( $config['default_sort']['direction'] ?? 'asc' ) );
		if ( ! in_array( $direction, [ 'asc', 'desc' ], true ) ) {
			$errors[] = $this->error( 'collection_sort_direction_invalid', 'Collection sort direction must be ascending or descending.', $node_id );
		}
	}

	private function validate_filter( $node_id, array $node, array $nodes, array $connections, array &$errors ) {
		$config = $node['config'] ?? [];
		if ( isset( $config['apply_mode'] ) && ! in_array( $config['apply_mode'], [ 'automatic', 'submit' ], true ) ) {
			$errors[] = $this->error( 'filter_surface_apply_mode_invalid', 'Filter Surface apply mode must be automatic or submit.', $node_id );
		}
		$collection_id = $this->connected_id( $node_id, 'filters', 'from', $connections );
		$entity_id = $this->connected_id( $collection_id, 'collection_for', 'from', $connections );
		$fields = $this->entity_fields( $entity_id, $nodes, $connections );
		$selected = $config['fields'] ?? [];
		if ( ! $this->valid_reference_list( $selected, 20 ) ) {
			$errors[] = $this->error( 'filter_surface_fields_invalid', 'Filter Surface fields must be a unique list of at most 20 Field IDs.', $node_id );
		} else {
			$this->validate_refs( $selected, $fields, 'filter', 'filter_surface_field_invalid', $node_id, $errors );
		}
		$facets = $config['facet_fields'] ?? [];
		if ( ! $this->valid_reference_list( $facets, 10 ) || array_diff( $facets, $selected ?: array_keys( $fields ) ) ) {
			$errors[] = $this->error( 'filter_surface_facets_invalid', 'Facet fields must be a subset of at most ten filter fields.', $node_id );
		} else {
			$this->validate_refs( $facets, $fields, 'filter', 'filter_surface_facet_invalid', $node_id, $errors );
		}
	}

	private function validate_refs( $references, array $fields, $capability, $code, $node_id, array &$errors ) {
		if ( ! $this->valid_reference_list( $references, 80 ) ) {
			$errors[] = $this->error( $code, 'Collection field references must be a list.', $node_id );
			return;
		}
		foreach ( $references as $field_id ) {
			$field = $fields[ (string) $field_id ] ?? null;
			if ( ! $field || ( '' !== $capability && ( empty( $field['capabilities'][ $capability ] ) || empty( $field['indexing'][ $capability ] ) ) ) ) {
				$errors[] = $this->error( $code, 'Collection references a Field without the required query capability.', $node_id );
			}
		}
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
		$adapter_id = sanitize_key( $nodes[ $entity_id ]['config']['adapter_id'] ?? '' );
		foreach ( $connections as $connection ) {
			if ( 'adapts' !== ( $connection['type'] ?? '' ) || $entity_id !== ( $connection['to'] ?? '' ) ) {
				continue;
			}
			$adapter_id = sanitize_key( $nodes[ $connection['from'] ]['config']['adapter_id'] ?? '' );
			break;
		}
		return $this->registries->storage_adapters()->get( $adapter_id );
	}

	private function connected_id( $node_id, $type, $side, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( $type === ( $connection['type'] ?? '' ) && $node_id === ( $connection['to'] ?? '' ) ) {
				return $connection[ $side ] ?? '';
			}
		}
		return '';
	}

	private function error( $code, $message, $node_id ) {
		return [ 'path' => 'nodes', 'code' => $code, 'message' => $message, 'node_id' => $node_id ];
	}
}
