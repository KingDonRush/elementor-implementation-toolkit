<?php
/**
 * Compiles an Entry Surface into a self-contained governed runtime contract.
 */

namespace EIT\Blueprint;

use EIT\Registry\ExtensionContract;
use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryContractCompiler {

	const OPERATIONS = [ 'create', 'update', 'submit_review', 'publish', 'archive', 'restore' ];
	const STATUSES = [ 'draft', 'review', 'publish', 'archived' ];
	const ACTION_TYPES = [ 'redirect', 'email', 'webhook' ];
	const ACTION_EVENTS = [ 'created', 'updated', 'submitted_for_review', 'published', 'archived', 'restored' ];
	private $registries;
	private $collections;

	public function __construct( ?RegistryHub $registries = null, ?CollectionContractCompiler $collections = null ) {
		$this->registries = $registries ?: ( new CoreRegistryFactory() )->create();
		$this->collections = $collections ?: new CollectionContractCompiler( $this->registries );
	}

	public function compile( array $entry, array $nodes, array $connections, array $entities ) {
		$entity_id = $this->connected_id( $entry['id'], 'entry_for', 'from', $connections );
		$policy_id = $this->connected_id( $entry['id'], 'governs_entry', 'from', $connections );
		$entity = $entities[ $entity_id ] ?? [];
		$policy = $nodes[ $policy_id ] ?? [];
		$config = $entry['config'] ?? [];
		$groups = $this->field_groups( $entity_id, $nodes, $connections );
		if ( 'woocommerce' === ( $entity['adapter']['id'] ?? '' ) ) {
			$groups = [
				[
					'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'adapter-entry-fields:' . $entity_id ),
					'name' => __( 'WooCommerce product fields', 'elementor-implementation-toolkit' ),
					'fields' => array_values( array_filter( $entity['fields'] ?? [], fn( $field ) => empty( $field['validation']['read_only'] ) ) ),
				],
			];
		}
		$fields = $this->selected_fields( $groups, $config['field_ids'] ?? [] );
		$fields = $this->compile_relation_targets( $fields, $entity_id, $nodes, $connections, $entities );
		$actions = $this->actions( $config['actions'] ?? [] );
		if ( is_wp_error( $actions ) ) {
			return $actions;
		}

		return [
			'node_id' => $entry['id'],
			'surface_id' => $entry['id'],
			'name' => $entry['name'],
			'entity_id' => $entity_id,
			'entity' => [
				'strategy' => $entity['strategy'] ?? '',
				'mode' => $entity['definition']['entity_mode'] ?? ( $nodes[ $entity_id ]['config']['mode'] ?? 'structured' ),
				'definition' => $entity['definition'] ?? [],
				'adapter' => $entity['adapter'] ?? [],
			],
			'fields' => $fields,
			'title_field_id' => $this->title_field_id( $fields, $config['title_field_id'] ?? '' ),
			'groups' => $this->selected_groups( $groups, $fields ),
			'steps' => $this->steps( $config, $fields, $entry['id'] ),
			'conditions' => array_values( $config['conditions'] ?? [] ),
			'calculations' => $this->calculations( $fields ),
			'workflow' => $this->workflow( $config ),
			'actions' => $actions,
			'autosave' => [
				'enabled' => ! empty( $config['autosave']['enabled'] ),
				'interval_seconds' => min( 300, max( 15, absint( $config['autosave']['interval_seconds'] ?? 60 ) ) ),
			],
			'guest' => $this->guest( $config['guest'] ?? [] ),
			'policy' => $this->policy( $policy_id, $policy['config'] ?? [] ),
		];
	}

	private function field_groups( $entity_id, array $nodes, array $connections ) {
		$groups = [];
		foreach ( $connections as $connection ) {
			if ( 'entity_fields' !== ( $connection['type'] ?? '' ) || $entity_id !== ( $connection['from'] ?? '' ) ) {
				continue;
			}
			$node = $nodes[ $connection['to'] ] ?? null;
			if ( $node ) {
				$groups[] = [ 'id' => $node['id'], 'name' => $node['name'], 'fields' => array_values( $node['config']['fields'] ?? [] ) ];
			}
		}
		return $groups;
	}

	private function selected_fields( array $groups, $selected_ids ) {
		$selected_ids = is_array( $selected_ids ) ? array_values( array_filter( array_map( 'strval', $selected_ids ) ) ) : [];
		$indexed = [];
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				$indexed[ $field['id'] ] = $field;
			}
		}
		if ( ! $selected_ids ) {
			return array_values( $indexed );
		}
		$fields = [];
		foreach ( $selected_ids as $field_id ) {
			if ( isset( $indexed[ $field_id ] ) ) {
				$fields[] = $indexed[ $field_id ];
			}
		}
		return $fields;
	}

	private function selected_groups( array $groups, array $fields ) {
		$selected = array_fill_keys( array_column( $fields, 'id' ), true );
		$result = [];
		foreach ( $groups as $group ) {
			$field_ids = array_values( array_filter( array_column( $group['fields'], 'id' ), fn( $id ) => isset( $selected[ $id ] ) ) );
			if ( $field_ids ) {
				$result[] = [ 'id' => $group['id'], 'name' => $group['name'], 'field_ids' => $field_ids ];
			}
		}
		return $result;
	}

	private function compile_relation_targets( array $fields, $entity_id, array $nodes, array $connections, array $entities ) {
		foreach ( $fields as &$field ) {
			if ( 'relation' !== ( $field['type'] ?? '' ) ) {
				continue;
			}
			$relation = $this->relation_for_field( $field['id'], $entity_id, $nodes, $connections );
			if ( ! $relation ) {
				continue;
			}
			$target_id = $this->connection_target( $relation['id'], 'relation_target', $connections );
			$target = $entities[ $target_id ] ?? [];
			$config = $relation['config'] ?? [];
			$collection_id = $this->connection_target( $relation['id'], 'relation_options', $connections );
			$collection = isset( $nodes[ $collection_id ] )
				? $this->collections->compile_collection( $nodes[ $collection_id ], $nodes, $connections, $entities )
				: null;
			$field['relation'] = [
				'id' => $relation['id'],
				'cardinality' => in_array( $config['cardinality'] ?? '', RelationContractValidator::CARDINALITIES, true ) ? $config['cardinality'] : 'many_to_many',
				'target_entity_id' => $target_id,
				'options_collection_id' => $collection_id,
				'search_enabled' => $this->collection_supports_search( $collection ),
				'target' => [
					'strategy' => $target['strategy'] ?? '',
					'definition' => $target['definition'] ?? [],
					'adapter' => $target['adapter'] ?? [],
				],
				'ownership' => 'own' === ( $config['ownership'] ?? 'any' ) ? 'own' : 'any',
				'object_scope' => 'assigned' === ( $config['object_scope'] ?? 'entity' ) ? 'assigned' : 'entity',
			];
		}
		unset( $field );
		return $fields;
	}

	private function collection_supports_search( $collection ) {
		return is_array( $collection )
			&& ! empty( $collection['search_field_ids'] )
			&& in_array( 'search', $collection['provider']['capabilities'] ?? [], true );
	}

	private function actions( $actions ) {
		$result = [];
		foreach ( is_array( $actions ) ? $actions : [] as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = strtolower( trim( (string) ( $action['type'] ?? '' ) ) );
			$extension = $this->registries->form_actions()->get( $type );
			if ( ! $extension ) {
				return new \WP_Error( 'entry_action_missing', __( 'Entry Surface action type is not registered.', 'elementor-implementation-toolkit' ) );
			}
			$contract = ExtensionContract::snapshot( $extension );
			if ( is_wp_error( $contract ) || ! hash_equals( $type, (string) ( $contract['id'] ?? '' ) ) || ! in_array( 'durable_job', $contract['capabilities'] ?? [], true ) ) {
				return new \WP_Error( 'entry_action_runtime_contract_invalid', __( 'Entry Surface action failed its runtime contract.', 'elementor-implementation-toolkit' ) );
			}
			$action['type'] = $type;
			$action['events'] = array_values( $action['events'] ?? [] );
			$action['config'] = is_array( $action['config'] ?? null ) ? $action['config'] : [];
			$action['extension'] = [
				'id' => $contract['id'],
				'version' => $contract['version'],
				'capabilities' => $contract['capabilities'],
			];
			$result[] = $action;
		}
		return $result;
	}

	private function relation_for_field( $field_id, $entity_id, array $nodes, array $connections ) {
		foreach ( $nodes as $node ) {
			if ( 'relation' !== ( $node['type'] ?? '' ) || $field_id !== ( $node['config']['field_id'] ?? '' ) ) {
				continue;
			}
			foreach ( $connections as $connection ) {
				if ( 'relation_source' === ( $connection['type'] ?? '' ) && $entity_id === ( $connection['from'] ?? '' ) && $node['id'] === ( $connection['to'] ?? '' ) ) {
					return $node;
				}
			}
		}
		return null;
	}

	private function connection_target( $node_id, $type, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( $type === ( $connection['type'] ?? '' ) && $node_id === ( $connection['from'] ?? '' ) ) {
				return $connection['to'] ?? '';
			}
		}
		return '';
	}

	private function title_field_id( array $fields, $configured ) {
		foreach ( $fields as $field ) {
			if ( (string) $configured === ( $field['id'] ?? '' ) ) {
				return $field['id'];
			}
		}
		foreach ( $fields as $field ) {
			if ( 'short_text' === ( $field['type'] ?? '' ) ) {
				return $field['id'];
			}
		}
		return '';
	}

	private function steps( array $config, array $fields, $surface_id ) {
		$steps = array_values( $config['steps'] ?? [] );
		if ( $steps ) {
			return $steps;
		}
		return [
			[
				'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-step:' . $surface_id ),
				'name' => __( 'Details', 'elementor-implementation-toolkit' ),
				'field_ids' => array_column( $fields, 'id' ),
			],
		];
	}

	private function calculations( array $fields ) {
		$result = [];
		foreach ( $fields as $field ) {
			$expression = trim( (string) ( $field['validation']['expression'] ?? '' ) );
			if ( 'calculated' === ( $field['type'] ?? '' ) && '' !== $expression ) {
				$result[] = [ 'field_id' => $field['id'], 'expression' => $expression ];
			}
		}
		return $result;
	}

	private function workflow( array $config ) {
		$operations = array_values( array_intersect( self::OPERATIONS, array_unique( array_map( 'sanitize_key', $config['operations'] ?? [] ) ) ) );
		return [
			'operations' => $operations,
			'initial_status' => sanitize_key( $config['initial_status'] ?? 'draft' ),
			'moderation_status' => sanitize_key( $config['moderation_status'] ?? 'review' ),
		];
	}

	private function guest( array $guest ) {
		return [
			'enabled' => ! empty( $guest['enabled'] ),
			'moderation_status' => sanitize_key( $guest['moderation_status'] ?? 'review' ),
			'minimum_seconds' => min( 60, max( 2, absint( $guest['minimum_seconds'] ?? 3 ) ) ),
			'rate_limit_per_hour' => min( 30, max( 1, absint( $guest['rate_limit_per_hour'] ?? 5 ) ) ),
			'upload_max_bytes' => min( 10485760, max( 0, absint( $guest['upload_max_bytes'] ?? 0 ) ) ),
			'upload_mime_types' => array_values( array_unique( array_map( 'sanitize_mime_type', $guest['upload_mime_types'] ?? [] ) ) ),
		];
	}

	private function policy( $policy_id, array $config ) {
		$capability = sanitize_key( $config['capability'] ?? 'edit_posts' );
		return [
			'id' => $policy_id,
			'capabilities' => [
				'create' => sanitize_key( $config['create_capability'] ?? $capability ),
				'update' => sanitize_key( $config['update_capability'] ?? $capability ),
				'publish' => sanitize_key( $config['publish_capability'] ?? 'publish_posts' ),
				'archive' => sanitize_key( $config['archive_capability'] ?? $capability ),
			],
			'ownership' => sanitize_key( $config['ownership'] ?? 'own' ),
			'object_scope' => sanitize_key( $config['object_scope'] ?? 'entity' ),
		];
	}

	private function connected_id( $node_id, $type, $side, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( $type === ( $connection['type'] ?? '' ) && $node_id === ( $connection['to'] ?? '' ) ) {
				return $connection[ $side ] ?? '';
			}
		}
		return '';
	}
}
