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
}
