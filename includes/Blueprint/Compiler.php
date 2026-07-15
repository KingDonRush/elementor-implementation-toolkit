<?php
/**
 * Deterministically compiles a valid Blueprint into runtime artifacts.
 */

namespace EIT\Blueprint;

use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Compiler {

	private $validator;
	private $canonicalizer;
	private $recommendation;
	private $registries;
	private $entry_contracts;

	public function __construct(
		BlueprintValidator $validator = null,
		Canonicalizer $canonicalizer = null,
		StorageRecommendation $recommendation = null,
		RegistryHub $registries = null
	) {
		$this->registries = $registries ?: ( new CoreRegistryFactory() )->create();
		$this->validator = $validator ?: new BlueprintValidator( null, $this->registries->field_primitives() );
		$this->canonicalizer = $canonicalizer ?: new Canonicalizer();
		$this->recommendation = $recommendation ?: new StorageRecommendation();
		$this->entry_contracts = new EntryContractCompiler();
	}

	public function compile( array $blueprint ) {
		$validation = $this->validator->validate( $blueprint );
		if ( ! $validation->is_valid() ) {
			return new CompilationResult( [ 'errors' => $validation->errors() ] );
		}

		$blueprint_checksum = $this->canonicalizer->checksum( $blueprint );
		$nodes = $this->index_nodes( $blueprint['nodes'] );
		$connections = $blueprint['connections'];
		$artifacts = [];
		$bindings = [];
		$recommendations = [];
		$compiled_entities = [];
		$errors = [];

		foreach ( $nodes as $node ) {
			if ( 'entity' !== $node['type'] ) {
				continue;
			}
			$fields = $this->entity_fields( $node['id'], $nodes, $connections );
			$adapter_node = $this->entity_adapter( $node['id'], $nodes, $connections );
			$recommendation_input = $node;
			if ( $adapter_node ) {
				$recommendation_input['config']['adapter_id'] = $adapter_node['config']['adapter_id'] ?? '';
			}
			$recommendation = $this->recommendation->recommend( $recommendation_input );
			$recommendations[ $node['id'] ] = $recommendation;
			if ( ! $recommendation['valid'] ) {
				$errors[] = $this->error( 'storage_override_reason', 'Storage override requires a documented reason.', $node['id'] );
				continue;
			}

			$adapter_id = $this->adapter_id( $node, $recommendation['selected'], $adapter_node );
			$adapter = $this->registries->storage_adapters()->get( $adapter_id );
			if ( ! $adapter ) {
				$errors[] = $this->error( 'storage_adapter_missing', 'Selected storage adapter is not registered.', $node['id'] );
				continue;
			}
			$health = $adapter->health_check();
			if ( empty( $health['ok'] ) ) {
				$errors[] = $this->error( 'storage_adapter_unhealthy', 'Selected storage adapter failed its health check.', $node['id'] );
				continue;
			}

			$compiled = $adapter->compile(
				$node,
				$fields,
				[ 'blueprint_id' => $blueprint['id'], 'slug' => $blueprint['slug'] ?? '' ]
			);
			if ( is_wp_error( $compiled ) ) {
				$errors[] = $this->error( $compiled->get_error_code(), $compiled->get_error_message(), $node['id'] );
				continue;
			}

			$entity_payload = array_merge(
				$compiled,
				[
					'entity_id' => $node['id'],
					'name' => $node['name'],
					'recommendation' => $recommendation,
					'adapter' => [ 'id' => $adapter_id, 'version' => $adapter->get_version(), 'capabilities' => $adapter->get_capabilities() ],
				]
			);
			$artifacts[] = $this->artifact( $blueprint['id'], $blueprint_checksum, 'entity_definition', $node['id'], $entity_payload );
			$compiled_entities[ $node['id'] ] = $entity_payload;
			$artifacts[] = $this->artifact( $blueprint['id'], $blueprint_checksum, 'storage_contract', $node['id'], [ 'strategy' => $recommendation['selected'], 'adapter' => $adapter_id, 'fields' => array_column( $fields, 'id' ) ] );
			$artifacts[] = $this->artifact( $blueprint['id'], $blueprint_checksum, 'capability_contract', $node['id'], $this->capabilities( $fields, $adapter ) );

			foreach ( $fields as $field ) {
				$bindings[] = [
					'field_id' => $field['id'],
					'entity_id' => $node['id'],
					'adapter' => $adapter_id,
					'storage_key' => $field['storage']['key'],
					'aliases' => array_values( array_unique( $field['storage']['aliases'] ?? [] ) ),
					'migration' => $field['storage']['migration'] ?? null,
				];
			}
		}

		foreach ( $nodes as $node ) {
			$artifact = $this->compile_non_entity( $blueprint, $blueprint_checksum, $node, $nodes, $connections, $compiled_entities );
			if ( $artifact ) {
				$artifacts[] = $artifact;
			}
		}
		if ( $errors ) {
			return new CompilationResult( [ 'errors' => $errors, 'recommendations' => $recommendations ] );
		}

		usort( $artifacts, [ $this, 'sort_artifacts' ] );
		usort( $bindings, [ $this, 'sort_bindings' ] );
		$compiler_checksum = $this->semantic_hash( [ 'artifacts' => $artifacts, 'bindings' => $bindings ] );
		return new CompilationResult(
			[
				'valid' => true,
				'blueprint_checksum' => $blueprint_checksum,
				'checksum' => $compiler_checksum,
				'artifacts' => $artifacts,
				'bindings' => $bindings,
				'recommendations' => $recommendations,
			]
		);
	}

	private function compile_non_entity( array $blueprint, $checksum, array $node, array $nodes, array $connections, array $compiled_entities ) {
		$kinds = [
			'field_group' => 'field_contracts',
			'relation' => 'relation_contract',
			'entry_surface' => 'entry_contract',
			'collection' => 'collection_contract',
			'filter_surface' => 'filter_contract',
			'presentation' => 'presentation_contract',
			'route' => 'route_contract',
			'policy' => 'policy_contract',
			'adapter' => 'adapter_contract',
		];
		if ( ! isset( $kinds[ $node['type'] ] ) ) {
			return null;
		}
		$payload = [
			'node_id' => $node['id'],
			'name' => $node['name'],
			'config' => $node['config'] ?? [],
			'connections' => $this->node_connections( $node['id'], $connections ),
		];
		if ( 'entry_surface' === $node['type'] ) {
			$payload = $this->entry_contracts->compile( $node, $nodes, $connections, $compiled_entities );
		}
		if ( 'relation' === $node['type'] ) {
			$payload['source_entity_id'] = $this->connected_node( $node['id'], 'relation_source', 'from', $connections );
			$payload['target_entity_id'] = $this->connected_node( $node['id'], 'relation_target', 'to', $connections );
			$payload['storage'] = 'normalized_relation_values';
		}
		if ( 'field_group' === $node['type'] ) {
			$payload['repeatable_storage'] = 'normalized_multivalue_values';
		}
		return $this->artifact( $blueprint['id'], $checksum, $kinds[ $node['type'] ], $node['id'], $payload );
	}

	private function entity_fields( $entity_id, array $nodes, array $connections ) {
		$fields = [];
		foreach ( $connections as $connection ) {
			if ( 'entity_fields' !== $connection['type'] || $entity_id !== $connection['from'] ) {
				continue;
			}
			$group = $nodes[ $connection['to'] ];
			foreach ( $group['config']['fields'] ?? [] as $field ) {
				$fields[] = $field;
			}
		}
		return $fields;
	}

	private function adapter_id( array $entity, $strategy, array $adapter_node = null ) {
		if ( 'adapter' !== $strategy ) {
			return $strategy;
		}
		if ( $adapter_node ) {
			return sanitize_key( $adapter_node['config']['adapter_id'] ?? '' );
		}
		$config = $entity['config'] ?? [];
		return sanitize_key( $config['adapter_id'] ?? ( 'woocommerce' === ( $config['owner'] ?? '' ) ? 'woocommerce' : 'external' ) );
	}

	private function entity_adapter( $entity_id, array $nodes, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( 'adapts' === $connection['type'] && $entity_id === $connection['to'] ) {
				return $nodes[ $connection['from'] ] ?? null;
			}
		}
		return null;
	}

	private function capabilities( array $fields, $adapter ) {
		$contract = [ 'adapter' => $adapter->get_capabilities(), 'fields' => [] ];
		foreach ( $fields as $field ) {
			$contract['fields'][ $field['id'] ] = [
				'search' => ! empty( $field['capabilities']['search'] ) && ! empty( $field['indexing']['search'] ),
				'filter' => ! empty( $field['capabilities']['filter'] ) && ! empty( $field['indexing']['filter'] ),
				'sort' => ! empty( $field['capabilities']['sort'] ) && ! empty( $field['indexing']['sort'] ),
				'elementor' => $field['elementor'],
			];
		}
		return $contract;
	}

	private function artifact( $blueprint_id, $blueprint_checksum, $kind, $node_id, array $payload ) {
		$checksum = $this->semantic_hash( $payload );
		return [
			'id' => hash( 'sha256', $blueprint_id . '|' . $blueprint_checksum . '|' . $kind . '|' . $node_id ),
			'node_id' => $node_id,
			'kind' => $kind,
			'checksum' => $checksum,
			'payload' => $payload,
		];
	}

	private function semantic_hash( $value ) {
		return hash( 'sha256', wp_json_encode( $this->sort_recursive( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ $this, 'sort_recursive' ], $value );
		}
		ksort( $value );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->sort_recursive( $child );
		}
		return $value;
	}

	private function index_nodes( array $nodes ) {
		$index = [];
		foreach ( $nodes as $node ) {
			$index[ $node['id'] ] = $node;
		}
		return $index;
	}

	private function node_connections( $node_id, array $connections ) {
		return array_values(
			array_filter(
				$connections,
				function ( $connection ) use ( $node_id ) {
					return $node_id === $connection['from'] || $node_id === $connection['to'];
				}
			)
		);
	}

	private function connected_node( $node_id, $type, $side, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( $type === $connection['type'] && $node_id === ( 'from' === $side ? $connection['to'] : $connection['from'] ) ) {
				return $connection[ $side ];
			}
		}
		return null;
	}

	private function error( $code, $message, $node_id ) {
		return [ 'code' => $code, 'message' => $message, 'node_id' => $node_id ];
	}

	private function sort_artifacts( $left, $right ) {
		return strcmp( $left['kind'] . '|' . $left['node_id'], $right['kind'] . '|' . $right['node_id'] );
	}

	private function sort_bindings( $left, $right ) {
		return strcmp( $left['field_id'], $right['field_id'] );
	}
}
