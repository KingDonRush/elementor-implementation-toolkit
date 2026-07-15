<?php
/**
 * Validates Entry Surface workflow references and bounded executable decisions.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryContractValidator {

	const MAX_STEPS = 12;
	const MAX_CONDITIONS = 40;
	const MAX_ACTIONS = 20;

	public function validate( array $nodes, array $connections ) {
		$errors = [];
		foreach ( $nodes as $node_id => $node ) {
			if ( 'entry_surface' !== ( $node['type'] ?? '' ) ) {
				continue;
			}
			$entity_id = $this->connected_id( $node_id, 'entry_for', 'from', $connections );
			$field_ids = $this->entity_field_ids( $entity_id, $nodes, $connections );
			$this->validate_config( $node, $field_ids, $errors );
			$this->validate_policy( $node_id, $nodes, $connections, $errors );
		}
		return $errors;
	}

	private function validate_config( array $node, array $field_ids, array &$errors ) {
		$config = $node['config'] ?? [];
		$operations = $config['operations'] ?? null;
		if ( ! is_array( $operations ) || ! array_is_list( $operations ) || ! $operations ) {
			$errors[] = $this->error( 'operations_required', 'Entry Surface must permit at least one workflow operation.', $node['id'] );
		} elseif ( array_diff( $operations, EntryContractCompiler::OPERATIONS ) || count( $operations ) !== count( array_unique( $operations ) ) ) {
			$errors[] = $this->error( 'operations_invalid', 'Entry Surface operations must be supported and unique.', $node['id'] );
		}

		$status = sanitize_key( $config['initial_status'] ?? 'draft' );
		if ( ! in_array( $status, EntryContractCompiler::STATUSES, true ) ) {
			$errors[] = $this->error( 'initial_status_invalid', 'Entry Surface initial status is invalid.', $node['id'] );
		}
		$this->validate_field_references( $config['field_ids'] ?? [], $field_ids, 'surface_field_invalid', $node['id'], $errors );
		$this->validate_steps( $config['steps'] ?? [], $field_ids, $node['id'], $errors );
		$this->validate_conditions( $config['conditions'] ?? [], $field_ids, $node['id'], $errors );
		$this->validate_actions( $config['actions'] ?? [], $node['id'], $errors );
		$this->validate_guest( $config['guest'] ?? [], $operations, $node['id'], $errors );
	}

	private function validate_steps( $steps, array $field_ids, $node_id, array &$errors ) {
		if ( ! is_array( $steps ) || ! array_is_list( $steps ) || count( $steps ) > self::MAX_STEPS ) {
			$errors[] = $this->error( 'entry_steps_invalid', 'Entry Surface steps must be a list of at most 12 steps.', $node_id );
			return;
		}
		$seen = [];
		foreach ( $steps as $step ) {
			$id = is_array( $step ) ? strtolower( (string) ( $step['id'] ?? '' ) ) : '';
			if ( ! Uuid::is_valid( $id ) || isset( $seen[ $id ] ) || '' === trim( (string) ( $step['name'] ?? '' ) ) ) {
				$errors[] = $this->error( 'entry_step_identity_invalid', 'Every Entry Surface step needs a unique UUID and public name.', $node_id );
				continue;
			}
			$seen[ $id ] = true;
			$this->validate_field_references( $step['field_ids'] ?? [], $field_ids, 'entry_step_field_invalid', $node_id, $errors );
		}
	}

	private function validate_conditions( $conditions, array $field_ids, $node_id, array &$errors ) {
		if ( ! is_array( $conditions ) || ! array_is_list( $conditions ) || count( $conditions ) > self::MAX_CONDITIONS ) {
			$errors[] = $this->error( 'entry_conditions_invalid', 'Entry Surface conditions must be a list of at most 40 rules.', $node_id );
			return;
		}
		$operators = [ 'equals', 'not_equals', 'in', 'not_in', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte' ];
		$effects = [ 'show', 'hide', 'require' ];
		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) || ! Uuid::is_valid( $condition['id'] ?? '' ) || ! in_array( $condition['operator'] ?? '', $operators, true ) || ! in_array( $condition['effect'] ?? '', $effects, true ) ) {
				$errors[] = $this->error( 'entry_condition_invalid', 'Entry Surface condition is not executable.', $node_id );
				continue;
			}
			$this->validate_field_references( [ $condition['source_field_id'] ?? '', $condition['target_field_id'] ?? '' ], $field_ids, 'entry_condition_field_invalid', $node_id, $errors );
		}
	}

	private function validate_actions( $actions, $node_id, array &$errors ) {
		if ( ! is_array( $actions ) || ! array_is_list( $actions ) || count( $actions ) > self::MAX_ACTIONS ) {
			$errors[] = $this->error( 'entry_actions_invalid', 'Entry Surface actions must be a list of at most 20 actions.', $node_id );
			return;
		}
		foreach ( $actions as $action ) {
			$events = is_array( $action ) ? ( $action['events'] ?? [] ) : [];
			if ( ! is_array( $action ) || ! Uuid::is_valid( $action['id'] ?? '' ) || ! in_array( $action['type'] ?? '', EntryContractCompiler::ACTION_TYPES, true ) || ! is_array( $events ) || ! $events || array_diff( $events, EntryContractCompiler::ACTION_EVENTS ) ) {
				$errors[] = $this->error( 'entry_action_invalid', 'Entry Surface action needs a stable ID, supported type and trigger event.', $node_id );
			}
		}
	}

	private function validate_guest( $guest, $operations, $node_id, array &$errors ) {
		if ( ! is_array( $guest ) || empty( $guest['enabled'] ) ) {
			return;
		}
		$status = sanitize_key( $guest['moderation_status'] ?? 'review' );
		if ( ! is_array( $operations ) || ! in_array( 'create', $operations, true ) || ! in_array( $status, [ 'draft', 'review' ], true ) ) {
			$errors[] = $this->error( 'guest_intake_invalid', 'Guest intake requires create and a moderated draft or review status.', $node_id );
		}
	}

	private function validate_policy( $entry_id, array $nodes, array $connections, array &$errors ) {
		$policy_id = $this->connected_id( $entry_id, 'governs_entry', 'from', $connections );
		$policy = $nodes[ $policy_id ] ?? null;
		if ( ! $policy ) {
			return;
		}
		$config = $policy['config'] ?? [];
		foreach ( [ 'capability', 'create_capability', 'update_capability', 'publish_capability', 'archive_capability' ] as $key ) {
			if ( isset( $config[ $key ] ) && ! preg_match( '/^[a-z][a-z0-9_]{1,63}$/', (string) $config[ $key ] ) ) {
				$errors[] = $this->error( 'entry_policy_capability_invalid', 'Entry Policy capability names are invalid.', $entry_id );
			}
		}
		if ( ! in_array( $config['ownership'] ?? 'own', [ 'own', 'any' ], true ) || ! in_array( $config['object_scope'] ?? 'entity', [ 'entity', 'assigned' ], true ) ) {
			$errors[] = $this->error( 'entry_policy_scope_invalid', 'Entry Policy ownership or object scope is invalid.', $entry_id );
		}
	}

	private function validate_field_references( $references, array $field_ids, $code, $node_id, array &$errors ) {
		if ( ! is_array( $references ) ) {
			$errors[] = $this->error( $code, 'Entry Surface field references must be a list.', $node_id );
			return;
		}
		foreach ( $references as $field_id ) {
			if ( ! isset( $field_ids[ (string) $field_id ] ) ) {
				$errors[] = $this->error( $code, 'Entry Surface references a field outside its Entity.', $node_id );
			}
		}
	}

	private function entity_field_ids( $entity_id, array $nodes, array $connections ) {
		$result = [];
		foreach ( $connections as $connection ) {
			if ( 'entity_fields' === ( $connection['type'] ?? '' ) && $entity_id === ( $connection['from'] ?? '' ) ) {
				foreach ( $nodes[ $connection['to'] ]['config']['fields'] ?? [] as $field ) {
					$result[ $field['id'] ?? '' ] = true;
				}
			}
		}
		return $result;
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
