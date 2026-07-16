<?php
/**
 * Binds each Relation node to one source Entity relation Field Contract.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RelationContractValidator {

	const CARDINALITIES = [ 'one_to_one', 'one_to_many', 'many_to_one', 'many_to_many' ];

	public function validate( array $nodes, array $connections ) {
		$errors = [];
		$fields = $this->relation_fields( $nodes, $connections );
		$bound = [];
		foreach ( $nodes as $node_id => $node ) {
			if ( 'relation' !== ( $node['type'] ?? '' ) ) {
				continue;
			}
			$config = $node['config'] ?? [];
			$source_id = $this->source_id( $node_id, $connections );
			$field_id = strtolower( (string) ( $config['field_id'] ?? '' ) );
			if ( ! Uuid::is_valid( $field_id ) || ! isset( $fields[ $source_id ][ $field_id ] ) ) {
				$errors[] = $this->error( 'relation_field_invalid', 'Relation must bind one relation field owned by its source Entity.', $node_id );
			} elseif ( isset( $bound[ $source_id ][ $field_id ] ) ) {
				$errors[] = $this->error( 'relation_field_duplicate', 'A source relation field can be governed by only one Relation node.', $node_id );
			} else {
				$bound[ $source_id ][ $field_id ] = $node_id;
			}
			if ( ! in_array( $config['cardinality'] ?? '', self::CARDINALITIES, true ) ) {
				$errors[] = $this->error( 'relation_cardinality_invalid', 'Relation cardinality must be one-to-one, one-to-many, many-to-one or many-to-many.', $node_id );
			}
			if ( ! in_array( $config['ownership'] ?? 'any', [ 'own', 'any' ], true ) || ! in_array( $config['object_scope'] ?? 'entity', [ 'entity', 'assigned' ], true ) ) {
				$errors[] = $this->error( 'relation_scope_invalid', 'Relation ownership or object scope is invalid.', $node_id );
			}
			$target_id = $this->connected_id( $node_id, 'relation_target', 'to', 'from', $connections );
			$collection_id = $this->connected_id( $node_id, 'relation_options', 'to', 'from', $connections );
			$collection_entity_id = $this->connected_id( $collection_id, 'collection_for', 'from', 'to', $connections );
			if ( $collection_id && ( ! isset( $nodes[ $collection_id ] ) || $target_id !== $collection_entity_id ) ) {
				$errors[] = $this->error( 'relation_options_target_mismatch', 'Relation option Collection must query the exact target Entity.', $node_id );
			}
			$policy_scope = $this->collection_policy_scope( $collection_id, $nodes, $connections );
			$relation_scope = [
				'ownership' => $config['ownership'] ?? 'any',
				'object_scope' => $config['object_scope'] ?? 'entity',
			];
			if ( $policy_scope !== $relation_scope ) {
				$errors[] = $this->error( 'relation_options_policy_mismatch', 'Relation and option Collection must enforce the same ownership and object scope.', $node_id );
			}
		}
		foreach ( $fields as $entity_id => $entity_fields ) {
			foreach ( $entity_fields as $field_id => $field ) {
				if ( ! isset( $bound[ $entity_id ][ $field_id ] ) ) {
					$errors[] = $this->error( 'relation_field_unbound', 'Every relation field must be governed by a connected Relation node.', $field['group_id'] ?? $entity_id );
				}
			}
		}
		return $errors;
	}

	private function relation_fields( array $nodes, array $connections ) {
		$fields = [];
		foreach ( $connections as $connection ) {
			if ( 'entity_fields' !== ( $connection['type'] ?? '' ) ) {
				continue;
			}
			$group = $nodes[ $connection['to'] ] ?? [];
			foreach ( $group['config']['fields'] ?? [] as $field ) {
				if ( 'relation' === ( $field['type'] ?? '' ) && ! empty( $field['id'] ) ) {
					$field['group_id'] = $group['id'] ?? '';
					$fields[ $connection['from'] ][ $field['id'] ] = $field;
				}
			}
		}
		return $fields;
	}

	private function source_id( $relation_id, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( 'relation_source' === ( $connection['type'] ?? '' ) && $relation_id === ( $connection['to'] ?? '' ) ) {
				return $connection['from'] ?? '';
			}
		}
		return '';
	}

	private function connected_id( $node_id, $type, $return_side, $match_side, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( $type === ( $connection['type'] ?? '' ) && $node_id === ( $connection[ $match_side ] ?? '' ) ) {
				return $connection[ $return_side ] ?? '';
			}
		}
		return '';
	}

	private function collection_policy_scope( $collection_id, array $nodes, array $connections ) {
		$policy_id = $this->connected_id( $collection_id, 'governs_collection', 'from', 'to', $connections );
		$config = $nodes[ $policy_id ]['config'] ?? [];
		return [
			'ownership' => $config['ownership'] ?? 'any',
			'object_scope' => $config['object_scope'] ?? 'entity',
		];
	}

	private function error( $code, $message, $node_id ) {
		return [ 'path' => 'nodes.' . $node_id . '.config', 'code' => $code, 'message' => $message, 'node_id' => $node_id ];
	}
}
