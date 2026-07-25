<?php
/**
 * Deterministically compiles a valid Blueprint into runtime artifacts.
 */

namespace EIT\Blueprint;

use EIT\Contracts\FieldContractSourceInterface;
use EIT\Registry\ExtensionContract;
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
	private $collection_contracts;

	public function __construct(
		?BlueprintValidator $validator = null,
		?Canonicalizer $canonicalizer = null,
		?StorageRecommendation $recommendation = null,
		?RegistryHub $registries = null
	) {
		$this->registries = $registries ?: ( new CoreRegistryFactory() )->create();
		$this->validator = $validator ?: new BlueprintValidator( null, $this->registries->field_primitives(), null, $this->registries );
		$this->canonicalizer = $canonicalizer ?: new Canonicalizer();
		$this->recommendation = $recommendation ?: new StorageRecommendation();
		$this->entry_contracts = new EntryContractCompiler( $this->registries );
		$this->collection_contracts = new CollectionContractCompiler( $this->registries );
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
			$adapter_contract = $this->adapter_contract( $adapter, $adapter_id );
			if ( is_wp_error( $adapter_contract ) ) {
				$errors[] = $this->error( $adapter_contract->get_error_code(), $adapter_contract->get_error_message(), $node['id'] );
				continue;
			}
			if ( $adapter instanceof FieldContractSourceInterface ) {
				try {
					$fields = $adapter->get_field_contracts(
						$node,
						[ 'blueprint_id' => $blueprint['id'], 'adapter_node' => $adapter_node ]
					);
				} catch ( \Throwable $error ) {
					$errors[] = $this->error( 'adapter_field_contracts_failed', 'Adapter Field Contracts could not be loaded.', $node['id'] );
					continue;
				}
				if ( ! is_array( $fields ) || ! array_is_list( $fields ) ) {
					$errors[] = $this->error( 'adapter_field_contracts_invalid', 'Adapter Field Contracts must be a list.', $node['id'] );
					continue;
				}
			}

			try {
				$compiled = $adapter->compile(
					$node,
					$fields,
					[ 'blueprint_id' => $blueprint['id'], 'slug' => $blueprint['slug'] ?? '' ]
				);
			} catch ( \Throwable $error ) {
				$errors[] = $this->error( 'storage_adapter_compile_failed', 'Storage adapter could not compile the Entity.', $node['id'] );
				continue;
			}
			if ( is_wp_error( $compiled ) ) {
				$errors[] = $this->error( $compiled->get_error_code(), $compiled->get_error_message(), $node['id'] );
				continue;
			}

			$entity_payload = array_merge(
				$compiled,
				[
					'entity_id' => $node['id'],
					'name' => $node['name'],
					'fields' => $fields,
					'recommendation' => $recommendation,
					'adapter' => array_merge( [ 'id' => $adapter_id ], $adapter_contract ),
				]
			);
			$artifacts[] = $this->artifact( $blueprint['id'], $blueprint_checksum, 'entity_definition', $node['id'], $entity_payload );
			$compiled_entities[ $node['id'] ] = $entity_payload;
			$artifacts[] = $this->artifact( $blueprint['id'], $blueprint_checksum, 'storage_contract', $node['id'], [ 'strategy' => $recommendation['selected'], 'adapter' => $adapter_id, 'fields' => array_column( $fields, 'id' ) ] );
			$artifacts[] = $this->artifact( $blueprint['id'], $blueprint_checksum, 'capability_contract', $node['id'], $this->capabilities( $fields, $adapter_contract['capabilities'] ) );

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
			if ( is_wp_error( $artifact ) ) {
				$errors[] = $this->error( $artifact->get_error_code(), $artifact->get_error_message(), $node['id'] );
				continue;
			}
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
		if ( 'collection' === $node['type'] ) {
			$payload = $this->collection_contracts->compile_collection( $node, $nodes, $connections, $compiled_entities );
		}
		if ( 'filter_surface' === $node['type'] ) {
			$payload = $this->collection_contracts->compile_filter( $node, $nodes, $connections, $compiled_entities );
		}
		if ( 'presentation' === $node['type'] ) {
			$adapter_id = sanitize_key( $node['config']['adapter'] ?? 'elementor' );
			$adapter = $this->registries->presentation_adapters()->get( $adapter_id );
			if ( ! $adapter ) {
				return new \WP_Error( 'eit_presentation_adapter_missing', __( 'The selected Presentation adapter is not registered.', 'elementor-implementation-toolkit' ) );
			}
			$adapter_contract = ExtensionContract::snapshot( $adapter );
			if ( is_wp_error( $adapter_contract ) || ! hash_equals( $adapter_id, (string) ( $adapter_contract['id'] ?? '' ) ) ) {
				if ( ! is_wp_error( $adapter_contract ) ) {
					return new \WP_Error( 'eit_presentation_adapter_incompatible', __( 'The selected Presentation adapter identity changed after registration.', 'elementor-implementation-toolkit' ) );
				}
				$code = 'eit_extension_unavailable' === $adapter_contract->get_error_code() ? 'eit_presentation_adapter_unhealthy' : 'eit_presentation_adapter_incompatible';
				return new \WP_Error( $code, __( 'The selected Presentation adapter failed its runtime contract.', 'elementor-implementation-toolkit' ) );
			}
			try {
				$payload = $adapter->compile(
					$node,
					[
						'blueprint_id' => $blueprint['id'],
						'connections' => $this->node_connections( $node['id'], $connections ),
						'nodes' => $nodes,
					]
				);
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'eit_presentation_adapter_compile_failed', __( 'The selected Presentation adapter could not compile this node.', 'elementor-implementation-toolkit' ) );
			}
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
			if ( ! is_array( $payload ) ) {
				return new \WP_Error( 'eit_presentation_adapter_contract_invalid', __( 'The selected Presentation adapter returned an invalid contract.', 'elementor-implementation-toolkit' ) );
			}
			$payload['adapter'] = $adapter_contract;
		}
		if ( 'relation' === $node['type'] ) {
			$payload['field_id'] = strtolower( (string) ( $node['config']['field_id'] ?? '' ) );
			$payload['cardinality'] = sanitize_key( $node['config']['cardinality'] ?? '' );
			$payload['source_entity_id'] = $this->connected_node( $node['id'], 'relation_source', 'from', $connections );
			$payload['target_entity_id'] = $this->connected_node( $node['id'], 'relation_target', 'to', $connections );
			$payload['options_collection_id'] = $this->connected_node( $node['id'], 'relation_options', 'to', $connections );
			$payload['storage'] = 'normalized_relation_values';
		}
		if ( 'route' === $node['type'] ) {
			$raw_path = trim( (string) ( $node['config']['path'] ?? '' ) );
			$existing = (bool) filter_var( $raw_path, FILTER_VALIDATE_URL );
			$payload = [
				'route_id' => $node['id'],
				'name' => $node['name'],
				'presentation_id' => $this->connected_node( $node['id'], 'routes', 'from', $connections ),
				'path' => $existing ? esc_url_raw( $raw_path ) : trim( $raw_path, '/' ),
				'kind' => $existing ? 'existing_document' : 'virtual',
				'exposure' => sanitize_key( $node['config']['exposure'] ?? 'public' ),
			];
		}
		if ( 'field_group' === $node['type'] ) {
			$payload['repeatable_storage'] = 'normalized_multivalue_values';
		}
		if ( is_wp_error( $payload ) ) {
			return $payload;
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

	private function adapter_id( array $entity, $strategy, ?array $adapter_node = null ) {
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

	private function adapter_contract( $adapter, $expected_id ) {
		$contract = ExtensionContract::snapshot( $adapter );
		if ( is_wp_error( $contract ) || ! hash_equals( (string) $expected_id, (string) ( $contract['id'] ?? '' ) ) ) {
			if ( ! is_wp_error( $contract ) ) {
				return new \WP_Error( 'storage_adapter_incompatible', 'Selected storage adapter identity changed after registration.' );
			}
			$code = 'eit_extension_unavailable' === $contract->get_error_code() ? 'storage_adapter_unhealthy' : 'storage_adapter_incompatible';
			return new \WP_Error( $code, 'Selected storage adapter failed its runtime contract.' );
		}
		unset( $contract['id'] );
		return $contract;
	}

	private function capabilities( array $fields, array $adapter_capabilities ) {
		$contract = [ 'adapter' => $adapter_capabilities, 'fields' => [] ];
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
