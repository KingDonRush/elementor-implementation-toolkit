<?php
/**
 * Validates the executable eit.dev/v1 Blueprint contract.
 */

namespace EIT\Blueprint;

use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlueprintValidator {

	const API_VERSION = 'eit.dev/v1';
	const KIND = 'Blueprint';
	const MAX_NODES = 200;
	const MAX_CONNECTIONS = 400;
	const MAX_FIELDS_PER_GROUP = 80;

	private $nodes;
	private $primitives;
	private $canonicalizer;
	private $entry_contracts;
	private $collection_contracts;
	private $route_contracts;
	private $relation_contracts;

	public function __construct(
		NodeTypeRegistry $nodes = null,
		FieldPrimitiveRegistry $primitives = null,
		Canonicalizer $canonicalizer = null,
		RegistryHub $registries = null,
		RouteContractValidator $route_contracts = null,
		RelationContractValidator $relation_contracts = null
	) {
		$this->nodes = $nodes ?: new NodeTypeRegistry();
		$this->primitives = $primitives ?: new FieldPrimitiveRegistry();
		$this->canonicalizer = $canonicalizer ?: new Canonicalizer();
		$this->entry_contracts = new EntryContractValidator( $registries );
		$this->collection_contracts = new CollectionContractValidator( $registries );
		$this->route_contracts = $route_contracts ?: new RouteContractValidator();
		$this->relation_contracts = $relation_contracts ?: new RelationContractValidator();
	}

	public function validate( array $blueprint ) {
		$errors = [];
		$this->validate_header( $blueprint, $errors );
		$node_index = $this->validate_nodes( $blueprint['nodes'] ?? null, $errors );
		$connections = $this->validate_connections( $blueprint['connections'] ?? null, $node_index, $errors );
		$this->validate_orphans( $node_index, $connections, $errors );
		$this->validate_cardinality( $node_index, $connections, $errors );
		$this->validate_storage_bindings( $node_index, $connections, $errors );
		$errors = array_merge( $errors, $this->route_contracts->validate( $node_index, $connections ) );
		$errors = array_merge( $errors, $this->relation_contracts->validate( $node_index, $connections ) );
		$errors = array_merge( $errors, $this->entry_contracts->validate( $node_index, $connections ) );
		$errors = array_merge( $errors, $this->collection_contracts->validate( $node_index, $connections ) );
		$this->validate_cycles( $node_index, $connections, $errors );

		if ( isset( $blueprint['checksum'] ) && ! hash_equals( (string) $blueprint['checksum'], $this->canonicalizer->checksum( $blueprint ) ) ) {
			$errors[] = $this->error( 'checksum', 'checksum_mismatch', 'Blueprint checksum does not match its semantic document.' );
		}

		return new ValidationResult( $errors );
	}

	private function validate_header( array $blueprint, array &$errors ) {
		if ( self::API_VERSION !== ( $blueprint['api_version'] ?? '' ) ) {
			$errors[] = $this->error( 'api_version', 'unsupported_api_version', 'Blueprint api_version must be eit.dev/v1.' );
		}
		if ( self::KIND !== ( $blueprint['kind'] ?? '' ) ) {
			$errors[] = $this->error( 'kind', 'invalid_kind', 'Blueprint kind must be Blueprint.' );
		}
		if ( ! Uuid::is_valid( $blueprint['id'] ?? '' ) ) {
			$errors[] = $this->error( 'id', 'invalid_uuid', 'Blueprint ID must be a UUID.' );
		}
		if ( empty( trim( (string) ( $blueprint['name'] ?? '' ) ) ) ) {
			$errors[] = $this->error( 'name', 'required', 'Blueprint name is required.' );
		}
		if ( ! is_int( $blueprint['version'] ?? null ) || ( $blueprint['version'] ?? 0 ) < 1 ) {
			$errors[] = $this->error( 'version', 'invalid_version', 'Blueprint version must be a positive integer.' );
		}
	}

	private function validate_nodes( $nodes, array &$errors ) {
		if ( ! is_array( $nodes ) || ! array_is_list( $nodes ) ) {
			$errors[] = $this->error( 'nodes', 'invalid_list', 'Blueprint nodes must be a list.' );
			return [];
		}
		if ( count( $nodes ) > self::MAX_NODES ) {
			$errors[] = $this->error( 'nodes', 'node_limit', 'Blueprint exceeds the 200-node limit.' );
		}

		$index = [];
		$field_ids = [];
		foreach ( array_slice( $nodes, 0, self::MAX_NODES ) as $offset => $node ) {
			$path = 'nodes.' . $offset;
			if ( ! is_array( $node ) ) {
				$errors[] = $this->error( $path, 'invalid_node', 'Node must be an object.' );
				continue;
			}
			$id = strtolower( (string) ( $node['id'] ?? '' ) );
			$type = (string) ( $node['type'] ?? '' );
			if ( ! Uuid::is_valid( $id ) ) {
				$errors[] = $this->error( $path . '.id', 'invalid_uuid', 'Node ID must be a UUID.' );
				continue;
			}
			if ( isset( $index[ $id ] ) ) {
				$errors[] = $this->error( $path . '.id', 'duplicate_node_id', 'Node ID must be unique.', $id );
				continue;
			}
			if ( ! $this->nodes->has( $type ) ) {
				$errors[] = $this->error( $path . '.type', 'unknown_node_type', 'Node type is not registered.', $id );
			}
			if ( isset( $node['lane'] ) && $this->nodes->lane( $type ) !== $node['lane'] ) {
				$errors[] = $this->error( $path . '.lane', 'invalid_lane', 'Node lane does not match its executable type.', $id );
			}
			if ( empty( trim( (string) ( $node['name'] ?? '' ) ) ) ) {
				$errors[] = $this->error( $path . '.name', 'required', 'Node name is required.', $id );
			}
			if ( 'adapter' === $type && empty( trim( (string) ( $node['config']['adapter_id'] ?? '' ) ) ) ) {
				$errors[] = $this->error( $path . '.config.adapter_id', 'adapter_id_required', 'Adapter node must select a registered adapter.', $id );
			}
			$index[ $id ] = $node;
			if ( 'field_group' === $type ) {
				$this->validate_fields( $node, $path, $field_ids, $errors );
			}
		}
		return $index;
	}

	private function validate_fields( array $node, $path, array &$field_ids, array &$errors ) {
		$fields = $node['config']['fields'] ?? null;
		if ( ! is_array( $fields ) || ! array_is_list( $fields ) || empty( $fields ) ) {
			$errors[] = $this->error( $path . '.config.fields', 'invalid_fields', 'Field Group must contain a field list.', $node['id'] );
			return;
		}
		if ( count( $fields ) > self::MAX_FIELDS_PER_GROUP ) {
			$errors[] = $this->error( $path . '.config.fields', 'field_limit', 'Field Group exceeds the 80-field limit.', $node['id'] );
		}

		foreach ( array_slice( $fields, 0, self::MAX_FIELDS_PER_GROUP ) as $offset => $field ) {
			$field_path = $path . '.config.fields.' . $offset;
			if ( ! is_array( $field ) ) {
				$errors[] = $this->error( $field_path, 'invalid_field', 'Field contract must be an object.', $node['id'] );
				continue;
			}
			$id = strtolower( (string) ( $field['id'] ?? '' ) );
			if ( ! Uuid::is_valid( $id ) ) {
				$errors[] = $this->error( $field_path . '.id', 'invalid_uuid', 'Field ID must be a UUID.', $node['id'] );
				continue;
			}
			if ( isset( $field_ids[ $id ] ) ) {
				$errors[] = $this->error( $field_path . '.id', 'duplicate_field_id', 'Field ID must be unique across the Blueprint.', $node['id'] );
			}
			$field_ids[ $id ] = true;
			$this->validate_field_contract( $field, $field_path, $node['id'], $errors );
		}
	}

	private function validate_field_contract( array $field, $path, $node_id, array &$errors ) {
		$type = (string) ( $field['type'] ?? '' );
		$primitive = $this->primitives->get( $type );
		if ( ! $primitive ) {
			$errors[] = $this->error( $path . '.type', 'unknown_field_type', 'Field primitive is not registered.', $node_id );
			return;
		}
		$health = $primitive->health_check();
		if ( ! is_array( $health ) || empty( $health['ok'] ) ) {
			$errors[] = $this->error( $path . '.type', 'field_primitive_unhealthy', 'Field primitive failed its health check.', $node_id );
			return;
		}
		$compiled_primitive = is_array( $field['primitive'] ?? null ) ? $field['primitive'] : [];
		if ( $compiled_primitive && ( $type !== ( $compiled_primitive['id'] ?? '' ) || (string) $primitive->get_version() !== ( $compiled_primitive['version'] ?? '' ) ) ) {
			$errors[] = $this->error( $path . '.primitive', 'field_primitive_version_mismatch', 'Field primitive metadata differs from the registered implementation.', $node_id );
		}
		if ( empty( trim( (string) ( $field['name'] ?? '' ) ) ) ) {
			$errors[] = $this->error( $path . '.name', 'required', 'Field public name is required.', $node_id );
		}

		$definition = $primitive->get_definition();
		if ( ( $field['shape'] ?? '' ) !== ( $definition['shape'] ?? '' ) ) {
			$errors[] = $this->error( $path . '.shape', 'invalid_shape', 'Field shape is incompatible with its primitive.', $node_id );
		}
		foreach ( [ 'validation', 'exposure', 'storage', 'indexing', 'components', 'elementor', 'capabilities' ] as $contract_key ) {
			if ( ! isset( $field[ $contract_key ] ) || ! is_array( $field[ $contract_key ] ) ) {
				$errors[] = $this->error( $path . '.' . $contract_key, 'missing_contract', 'Field contract section is required.', $node_id );
			}
		}

		$storage_key = (string) ( $field['storage']['key'] ?? '' );
		if ( ! preg_match( '/^[a-z_][a-z0-9_-]{0,190}$/', $storage_key ) ) {
			$errors[] = $this->error( $path . '.storage.key', 'invalid_storage_key', 'Compiled storage key is invalid.', $node_id );
		}
		$capabilities = $definition['capabilities'] ?? [];
		foreach ( [ 'search', 'filter', 'sort' ] as $capability ) {
			if ( ! empty( $field['indexing'][ $capability ] ) && empty( $capabilities[ $capability ] ) ) {
				$errors[] = $this->error( $path . '.indexing.' . $capability, 'unsupported_capability', 'Primitive cannot provide the requested query capability.', $node_id );
			}
		}
		$this->validate_numeric_constraints( $field, $path, $node_id, $errors );
	}

	private function validate_numeric_constraints( array $field, $path, $node_id, array &$errors ) {
		if ( ! in_array( $field['type'] ?? '', [ 'integer', 'decimal', 'money', 'percentage', 'calculated' ], true ) ) {
			return;
		}
		$validation = $field['validation'] ?? [];
		foreach ( [ 'min', 'max', 'step' ] as $key ) {
			if ( array_key_exists( $key, $validation ) && ! is_numeric( $validation[ $key ] ) ) {
				$errors[] = $this->error( $path . '.validation.' . $key, 'numeric_constraint_invalid', 'Numeric query limits must be numbers.', $node_id );
			}
		}
		if ( is_numeric( $validation['min'] ?? null ) && is_numeric( $validation['max'] ?? null ) && (float) $validation['min'] > (float) $validation['max'] ) {
			$errors[] = $this->error( $path . '.validation', 'numeric_range_invalid', 'Numeric minimum cannot be greater than maximum.', $node_id );
		}
		if ( is_numeric( $validation['step'] ?? null ) && (float) $validation['step'] <= 0 ) {
			$errors[] = $this->error( $path . '.validation.step', 'numeric_step_invalid', 'Numeric step must be greater than zero.', $node_id );
		}
	}

	private function validate_connections( $connections, array $nodes, array &$errors ) {
		if ( ! is_array( $connections ) || ! array_is_list( $connections ) ) {
			$errors[] = $this->error( 'connections', 'invalid_list', 'Blueprint connections must be a list.' );
			return [];
		}
		if ( count( $connections ) > self::MAX_CONNECTIONS ) {
			$errors[] = $this->error( 'connections', 'connection_limit', 'Blueprint exceeds the 400-connection limit.' );
		}

		$valid = [];
		$ids = [];
		foreach ( array_slice( $connections, 0, self::MAX_CONNECTIONS ) as $offset => $connection ) {
			$path = 'connections.' . $offset;
			if ( ! is_array( $connection ) ) {
				$errors[] = $this->error( $path, 'invalid_connection', 'Connection must be an object.' );
				continue;
			}
			$id = strtolower( (string) ( $connection['id'] ?? '' ) );
			$from = strtolower( (string) ( $connection['from'] ?? '' ) );
			$to = strtolower( (string) ( $connection['to'] ?? '' ) );
			$type = (string) ( $connection['type'] ?? '' );
			if ( ! Uuid::is_valid( $id ) || isset( $ids[ $id ] ) ) {
				$errors[] = $this->error( $path . '.id', isset( $ids[ $id ] ) ? 'duplicate_connection_id' : 'invalid_uuid', 'Connection ID must be a unique UUID.', null, $id );
				continue;
			}
			$ids[ $id ] = true;
			if ( ! isset( $nodes[ $from ], $nodes[ $to ] ) ) {
				$errors[] = $this->error( $path, 'orphan_reference', 'Connection references a node that does not exist.', null, $id );
				continue;
			}
			if ( ! $this->nodes->connection_is_valid( $type, $nodes[ $from ]['type'], $nodes[ $to ]['type'] ) ) {
				$errors[] = $this->error( $path . '.type', 'invalid_connection_type', 'Connection is incompatible with its source and target node types.', null, $id );
				continue;
			}
			$valid[] = $connection;
		}
		return $valid;
	}

	private function validate_orphans( array $nodes, array $connections, array &$errors ) {
		$degree = array_fill_keys( array_keys( $nodes ), 0 );
		foreach ( $connections as $connection ) {
			++$degree[ $connection['from'] ];
			++$degree[ $connection['to'] ];
		}
		foreach ( $degree as $node_id => $count ) {
			if ( 0 === $count ) {
				$errors[] = $this->error( 'nodes', 'orphan_node', 'Node is not connected to the executable system.', $node_id );
			}
		}
	}

	private function validate_cardinality( array $nodes, array $connections, array &$errors ) {
		$counts = [];
		$owners = [
			'entry_surface' => [ 'entry_for', 'Entry Surface must belong to exactly one Entity.' ],
			'collection' => [ 'collection_for', 'Collection must belong to exactly one Entity.' ],
			'filter_surface' => [ 'filters', 'Filter Surface must belong to exactly one Collection.' ],
			'route' => [ 'routes', 'Route must belong to exactly one Presentation.' ],
		];
		foreach ( $connections as $connection ) {
			$counts[ $connection['to'] ][ $connection['type'] ] = ( $counts[ $connection['to'] ][ $connection['type'] ] ?? 0 ) + 1;
			$counts[ $connection['from'] ][ $connection['type'] ] = ( $counts[ $connection['from'] ][ $connection['type'] ] ?? 0 ) + 1;
		}
		foreach ( $nodes as $id => $node ) {
			if ( 'field_group' === $node['type'] && 1 !== ( $counts[ $id ]['entity_fields'] ?? 0 ) ) {
				$errors[] = $this->error( 'connections', 'field_group_owner', 'Field Group must belong to exactly one Entity.', $id );
			}
			if ( 'relation' === $node['type'] && ( 1 !== ( $counts[ $id ]['relation_source'] ?? 0 ) || 1 !== ( $counts[ $id ]['relation_target'] ?? 0 ) ) ) {
				$errors[] = $this->error( 'connections', 'relation_endpoints', 'Relation must declare exactly one source and one target Entity.', $id );
			}
			if ( 'relation' === $node['type'] && 1 !== ( $counts[ $id ]['relation_options'] ?? 0 ) ) {
				$errors[] = $this->error( 'connections', 'relation_options_required', 'Relation must use exactly one target Collection as its authorized option source.', $id );
			}
			if ( isset( $owners[ $node['type'] ] ) && 1 !== ( $counts[ $id ][ $owners[ $node['type'] ][0] ] ?? 0 ) ) {
				$errors[] = $this->error( 'connections', 'single_owner_required', $owners[ $node['type'] ][1], $id );
			}
			if ( 'entry_surface' === $node['type'] && 1 !== ( $counts[ $id ]['governs_entry'] ?? 0 ) ) {
				$errors[] = $this->error( 'connections', 'entry_policy_required', 'Entry Surface must be governed by exactly one Policy.', $id );
			}
			if ( 'adapter' === $node['type'] && 1 !== ( $counts[ $id ]['adapts'] ?? 0 ) ) {
				$errors[] = $this->error( 'connections', 'adapter_target_required', 'Adapter must connect to exactly one Entity.', $id );
			}
			if ( 'entity' === $node['type'] && 1 < ( $counts[ $id ]['adapts'] ?? 0 ) ) {
				$errors[] = $this->error( 'connections', 'multiple_entity_adapters', 'Entity cannot be owned by more than one Adapter.', $id );
			}
		}
	}

	private function validate_storage_bindings( array $nodes, array $connections, array &$errors ) {
		foreach ( $nodes as $entity_id => $entity ) {
			if ( 'entity' !== ( $entity['type'] ?? '' ) ) {
				continue;
			}
			$bindings = [];
			$columns = [];
			foreach ( $connections as $connection ) {
				if ( 'entity_fields' !== $connection['type'] || $entity_id !== $connection['from'] ) {
					continue;
				}
				foreach ( $nodes[ $connection['to'] ]['config']['fields'] ?? [] as $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}
					$field_id = (string) ( $field['id'] ?? '' );
					$aliases = $field['storage']['aliases'] ?? [];
					$keys = array_merge( [ $field['storage']['key'] ?? '' ], is_array( $aliases ) ? $aliases : [] );
					foreach ( array_unique( array_filter( array_map( 'strval', $keys ) ) ) as $key ) {
						$normalized = strtolower( $key );
						if ( isset( $bindings[ $normalized ] ) && $bindings[ $normalized ] !== $field_id ) {
							$errors[] = $this->error( 'nodes', 'duplicate_storage_binding', 'Field storage keys and aliases must be unique inside an Entity.', $entity_id );
						}
						$bindings[ $normalized ] = $field_id;
					}
					$column = preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) ( $field['storage']['key'] ?? '' ) ) );
					if ( isset( $columns[ $column ] ) && $columns[ $column ] !== $field_id ) {
						$errors[] = $this->error( 'nodes', 'storage_column_collision', 'Field storage keys compile to the same portable column name.', $entity_id );
					}
					$columns[ $column ] = $field_id;
				}
			}
		}
	}

	private function validate_cycles( array $nodes, array $connections, array &$errors ) {
		$graph = array_fill_keys( array_keys( $nodes ), [] );
		foreach ( $connections as $connection ) {
			if ( $this->nodes->connection_is_acyclic( $connection['type'] ) ) {
				$graph[ $connection['from'] ][] = $connection['to'];
			}
		}
		$state = [];
		foreach ( array_keys( $graph ) as $node_id ) {
			if ( $this->visit( $node_id, $graph, $state ) ) {
				$errors[] = $this->error( 'connections', 'dependency_cycle', 'Blueprint contains a dependency cycle.', $node_id );
				return;
			}
		}
	}

	private function visit( $node_id, array $graph, array &$state ) {
		if ( 1 === ( $state[ $node_id ] ?? 0 ) ) {
			return true;
		}
		if ( 2 === ( $state[ $node_id ] ?? 0 ) ) {
			return false;
		}
		$state[ $node_id ] = 1;
		foreach ( $graph[ $node_id ] as $target ) {
			if ( $this->visit( $target, $graph, $state ) ) {
				return true;
			}
		}
		$state[ $node_id ] = 2;
		return false;
	}

	private function error( $path, $code, $message, $node_id = null, $connection_id = null ) {
		return array_filter(
			[
				'path'          => $path,
				'code'          => $code,
				'message'       => $message,
				'node_id'       => $node_id,
				'connection_id' => $connection_id,
			],
			function ( $value ) {
				return null !== $value;
			}
		);
	}
}
