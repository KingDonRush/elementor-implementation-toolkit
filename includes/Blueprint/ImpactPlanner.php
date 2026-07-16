<?php
/**
 * Compares active and proposed artifacts before any runtime mutation.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImpactPlanner {

	public function plan( array $active_artifacts, array $active_bindings, array $next_artifacts, array $next_bindings ) {
		$active = $this->artifact_index( $active_artifacts );
		$next = $this->artifact_index( $next_artifacts );
		$added = array_values( array_diff( array_keys( $next ), array_keys( $active ) ) );
		$removed = array_values( array_diff( array_keys( $active ), array_keys( $next ) ) );
		$changed = [];
		foreach ( array_intersect( array_keys( $active ), array_keys( $next ) ) as $key ) {
			if ( ! hash_equals( (string) $active[ $key ]['checksum'], (string) $next[ $key ]['checksum'] ) ) {
				$changed[] = $key;
			}
		}

		$binding_changes = $this->binding_changes( $active_bindings, $next_bindings );
		$blockers = [];
		foreach ( $binding_changes as $change ) {
			if ( 'storage_key_changed' === $change['change'] ) {
				$blockers[] = [
					'code' => 'published_storage_key_locked',
					'field_id' => $change['field_id'],
					'message' => 'Published storage keys require a copy, transform, count/checksum validation and later reconciliation.',
				];
			}
		}
		$blockers = array_merge( $blockers, $this->destructive_artifact_changes( $active, $next ) );
		$blockers = array_merge( $blockers, $this->identity_rebinding_changes( $active, $next ) );
		$blockers = array_merge( $blockers, $this->storage_rebinding_changes( $active, $active_bindings, $next, $next_bindings ) );

		return [
			'blocked' => ! empty( $blockers ),
			'blockers' => $blockers,
			'artifacts' => [ 'added' => $added, 'changed' => $changed, 'removed' => $removed ],
			'bindings' => $binding_changes,
			'affected_node_ids' => $this->affected_nodes( $active, $next, array_merge( $added, $changed, $removed ) ),
			'summary' => [ 'added' => count( $added ), 'changed' => count( $changed ), 'removed' => count( $removed ), 'binding_changes' => count( $binding_changes ) ],
		];
	}

	private function artifact_index( array $artifacts ) {
		$index = [];
		foreach ( $artifacts as $artifact ) {
			$key = (string) ( $artifact['kind'] ?? '' ) . '|' . (string) ( $artifact['node_id'] ?? '' );
			$index[ $key ] = $artifact;
		}
		return $index;
	}

	private function binding_changes( array $active_bindings, array $next_bindings ) {
		$active = [];
		$next = [];
		foreach ( $active_bindings as $binding ) {
			$active[ $binding['field_id'] ] = $binding;
		}
		foreach ( $next_bindings as $binding ) {
			$next[ $binding['field_id'] ] = $binding;
		}
		$changes = [];
		foreach ( array_diff( array_keys( $next ), array_keys( $active ) ) as $field_id ) {
			$changes[] = [ 'field_id' => $field_id, 'change' => 'added' ];
		}
		foreach ( array_diff( array_keys( $active ), array_keys( $next ) ) as $field_id ) {
			$changes[] = [ 'field_id' => $field_id, 'change' => 'removed_data_preserved' ];
		}
		foreach ( array_intersect( array_keys( $active ), array_keys( $next ) ) as $field_id ) {
			if ( $active[ $field_id ]['storage_key'] !== $next[ $field_id ]['storage_key'] ) {
				$changes[] = [
					'field_id' => $field_id,
					'change' => 'storage_key_changed',
					'from' => $active[ $field_id ]['storage_key'],
					'to' => $next[ $field_id ]['storage_key'],
				];
			}
		}
		return $changes;
	}

	private function affected_nodes( array $active, array $next, array $keys ) {
		$ids = [];
		foreach ( $keys as $key ) {
			$artifact = $next[ $key ] ?? $active[ $key ] ?? [];
			if ( ! empty( $artifact['node_id'] ) ) {
				$ids[] = $artifact['node_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function destructive_artifact_changes( array $active, array $next ) {
		$blockers = [];
		foreach ( array_intersect( array_keys( $active ), array_keys( $next ) ) as $key ) {
			if ( ! str_starts_with( $key, 'entity_definition|' ) ) {
				continue;
			}
			$before = $active[ $key ]['payload'] ?? [];
			$after = $next[ $key ]['payload'] ?? [];
			$node_id = (string) ( $after['entity_id'] ?? $before['entity_id'] ?? '' );
			$before_identity = $this->storage_identity( $before );
			$after_identity = $this->storage_identity( $after );
			if ( $before_identity !== $after_identity ) {
				$blockers[] = [
					'code' => 'published_entity_storage_locked',
					'node_id' => $node_id,
					'from' => $before_identity,
					'to' => $after_identity,
					'message' => 'Published entity storage identity requires a staged copy, validation and explicit switch.',
				];
			}

			$before_fields = array_column( $before['fields'] ?? [], null, 'id' );
			$after_fields = array_column( $after['fields'] ?? [], null, 'id' );
			foreach ( array_intersect( array_keys( $before_fields ), array_keys( $after_fields ) ) as $field_id ) {
				$from = $this->field_semantics( $before_fields[ $field_id ] );
				$to = $this->field_semantics( $after_fields[ $field_id ] );
				if ( $from === $to ) {
					continue;
				}
				$blockers[] = [
					'code' => 'published_field_semantics_locked',
					'node_id' => $node_id,
					'field_id' => (string) $field_id,
					'from' => $from,
					'to' => $to,
					'message' => 'Published field type or shape requires a staged copy, transform, count/checksum validation and explicit switch.',
				];
			}
		}
		return $blockers;
	}

	private function storage_identity( array $payload ) {
		return [
			'strategy' => sanitize_key( $payload['strategy'] ?? '' ),
			'adapter' => sanitize_key( $payload['adapter']['id'] ?? '' ),
			'slug' => sanitize_key( $payload['definition']['slug'] ?? '' ),
		];
	}

	private function field_semantics( array $field ) {
		return [
			'type' => sanitize_key( $field['type'] ?? '' ),
			'shape' => sanitize_key( $field['shape'] ?? '' ),
			'taxonomy' => sanitize_key( $field['taxonomy']['slug'] ?? '' ),
		];
	}

	private function identity_rebinding_changes( array $active, array $next ) {
		$before = $this->entity_owners( $active );
		$after = $this->entity_owners( $next );
		$blockers = [];
		foreach ( array_intersect( array_keys( $before ), array_keys( $after ) ) as $identity ) {
			if ( $before[ $identity ] === $after[ $identity ] ) {
				continue;
			}
			$blockers[] = [
				'code' => 'published_entity_identity_rebound',
				'from_node_id' => $before[ $identity ],
				'to_node_id' => $after[ $identity ],
				'identity' => json_decode( $identity, true ),
				'message' => 'Published storage identity cannot be rebound to a replacement Entity UUID.',
			];
		}
		return $blockers;
	}

	private function storage_rebinding_changes( array $active, array $active_bindings, array $next, array $next_bindings ) {
		$before = $this->storage_owners( $active, $active_bindings );
		$after = $this->storage_owners( $next, $next_bindings );
		$blockers = [];
		foreach ( array_intersect( array_keys( $before ), array_keys( $after ) ) as $storage ) {
			if ( $before[ $storage ] === $after[ $storage ] ) {
				continue;
			}
			$blockers[] = [
				'code' => 'published_storage_identity_rebound',
				'from_field_id' => $before[ $storage ],
				'to_field_id' => $after[ $storage ],
				'message' => 'Published physical storage cannot be rebound to a replacement Field UUID.',
			];
		}
		return $blockers;
	}

	private function entity_owners( array $artifacts ) {
		$owners = [];
		foreach ( $artifacts as $key => $artifact ) {
			if ( ! str_starts_with( $key, 'entity_definition|' ) ) {
				continue;
			}
			$payload = $artifact['payload'] ?? [];
			$owners[ wp_json_encode( $this->storage_identity( $payload ) ) ] = (string) ( $payload['entity_id'] ?? $artifact['node_id'] ?? '' );
		}
		return $owners;
	}

	private function storage_owners( array $artifacts, array $bindings ) {
		$identities = [];
		foreach ( $this->entity_owners( $artifacts ) as $identity => $entity_id ) {
			$identities[ $entity_id ] = $identity;
		}
		$owners = [];
		foreach ( $bindings as $binding ) {
			$entity_id = (string) ( $binding['entity_id'] ?? '' );
			$identity = $identities[ $entity_id ] ?? wp_json_encode( [ 'entity_id' => $entity_id ] );
			$storage = $identity . '|' . (string) ( $binding['storage_key'] ?? '' );
			$owners[ $storage ] = (string) ( $binding['field_id'] ?? '' );
		}
		return $owners;
	}
}
