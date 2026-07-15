<?php
/**
 * Compiles an Entry Surface into a self-contained governed runtime contract.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryContractCompiler {

	const OPERATIONS = [ 'create', 'update', 'submit_review', 'publish', 'archive', 'restore' ];
	const STATUSES = [ 'draft', 'review', 'publish', 'archived' ];
	const ACTION_TYPES = [ 'redirect', 'email', 'notification', 'webhook' ];
	const ACTION_EVENTS = [ 'created', 'updated', 'submitted_for_review', 'published', 'archived', 'restored' ];

	public function compile( array $entry, array $nodes, array $connections, array $entities ) {
		$entity_id = $this->connected_id( $entry['id'], 'entry_for', 'from', $connections );
		$policy_id = $this->connected_id( $entry['id'], 'governs_entry', 'from', $connections );
		$entity = $entities[ $entity_id ] ?? [];
		$policy = $nodes[ $policy_id ] ?? [];
		$config = $entry['config'] ?? [];
		$groups = $this->field_groups( $entity_id, $nodes, $connections );
		$fields = $this->selected_fields( $groups, $config['field_ids'] ?? [] );

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
			'actions' => array_values( $config['actions'] ?? [] ),
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
