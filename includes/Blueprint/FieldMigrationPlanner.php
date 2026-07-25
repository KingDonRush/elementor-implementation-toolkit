<?php
/**
 * Converts compatible published field changes into executable migration steps.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FieldMigrationPlanner {

	private $transformer;

	public function __construct( ?MigrationValueTransformer $transformer = null ) {
		$this->transformer = $transformer ?: new MigrationValueTransformer();
	}

	public function plan( array $active_artifacts, array $active_bindings, array $next_artifacts, array $next_bindings ) {
		$before_fields = $this->field_index( $active_artifacts );
		$after_fields = $this->field_index( $next_artifacts );
		$before_bindings = array_column( $active_bindings, null, 'field_id' );
		$after_bindings = array_column( $next_bindings, null, 'field_id' );
		$before_collisions = $this->cct_column_collisions( $before_fields, $before_bindings );
		$after_collisions = $this->cct_column_collisions( $after_fields, $after_bindings );
		$operations = [];
		$blockers = [];

		foreach ( array_intersect( array_keys( $before_fields ), array_keys( $after_fields ), array_keys( $before_bindings ), array_keys( $after_bindings ) ) as $field_id ) {
			$before = $before_fields[ $field_id ];
			$after = $after_fields[ $field_id ];
			$source_key = sanitize_key( $before_bindings[ $field_id ]['storage_key'] ?? '' );
			$target_key = sanitize_key( $after_bindings[ $field_id ]['storage_key'] ?? '' );
			$semantics_changed = $before['semantics'] !== $after['semantics'];
			if ( ! $semantics_changed && $source_key === $target_key ) {
				continue;
			}
			if ( isset( $before_collisions[ $field_id ] ) || isset( $after_collisions[ $field_id ] ) ) {
				$error = new \WP_Error( 'eit_migration_identifier_collision', __( 'CCT field keys resolve to an ambiguous physical column.', 'elementor-implementation-toolkit' ) );
				$blockers[] = $this->blocker( $error, $field_id, $before, $after, $source_key, $target_key );
				continue;
			}

			$problem = $this->migration_problem( $field_id, $before, $after, $source_key, $target_key, $after_bindings[ $field_id ], $semantics_changed );
			if ( is_wp_error( $problem ) ) {
				$blockers[] = $this->blocker( $problem, $field_id, $before, $after, $source_key, $target_key );
				continue;
			}
			$source = array_merge( [ 'key' => $source_key ], $before['semantics'] );
			$target = array_merge( [ 'key' => $target_key ], $after['semantics'] );
			if ( 'cct' === $after['adapter'] ) {
				$source['physical'] = CctPhysicalColumnContract::expected( $source_key, $before['semantics'] );
				$target['physical'] = CctPhysicalColumnContract::expected( $target_key, $after['semantics'] );
				if ( is_wp_error( $source['physical'] ) || is_wp_error( $target['physical'] ) || $source['physical']['column'] === $target['physical']['column'] ) {
					$error = is_wp_error( $source['physical'] ) ? $source['physical'] : ( is_wp_error( $target['physical'] ) ? $target['physical'] : new \WP_Error( 'eit_migration_identifier_collision', __( 'CCT migration keys resolve to the same physical column.', 'elementor-implementation-toolkit' ) ) );
					$blockers[] = $this->blocker( $error, $field_id, $before, $after, $source_key, $target_key );
					continue;
				}
			}
			$operation = [
				'blueprint_scope' => 'field',
				'entity_id' => $after['entity_id'],
				'field_id' => (string) $field_id,
				'adapter' => $after['adapter'],
				'strategy' => $after['strategy'],
				'storage_slug' => $after['storage_slug'],
				'source' => $source,
				'target' => $target,
				'transform' => $problem,
				'preserve_source' => true,
			];
			$operation['id'] = hash( 'sha256', wp_json_encode( $operation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			$operations[] = $operation;
		}

		usort( $operations, fn( $left, $right ) => strcmp( $left['id'], $right['id'] ) );
		return [ 'operations' => $operations, 'blockers' => $blockers ];
	}

	private function cct_column_collisions( array $fields, array $bindings ) {
		$owners = [];
		$collisions = [];
		foreach ( array_intersect( array_keys( $fields ), array_keys( $bindings ) ) as $field_id ) {
			if ( 'cct' !== ( $fields[ $field_id ]['adapter'] ?? '' ) ) {
				continue;
			}
			$scope = implode( '|', [ $fields[ $field_id ]['strategy'], $fields[ $field_id ]['storage_slug'] ] );
			$keys = array_merge( [ $bindings[ $field_id ]['storage_key'] ?? '' ], is_array( $bindings[ $field_id ]['aliases'] ?? null ) ? $bindings[ $field_id ]['aliases'] : [] );
			foreach ( array_unique( array_filter( array_map( 'sanitize_key', $keys ) ) ) as $key ) {
				$identity = $scope . '|' . CctPhysicalColumnContract::expected( $key, $fields[ $field_id ]['semantics'] )['column'];
				if ( isset( $owners[ $identity ] ) && $owners[ $identity ] !== (string) $field_id ) {
					$collisions[ $field_id ] = true;
					$collisions[ $owners[ $identity ] ] = true;
				}
				$owners[ $identity ] = (string) $field_id;
			}
		}
		return $collisions;
	}

	private function migration_problem( $field_id, array $before, array $after, $source_key, $target_key, array $binding, $semantics_changed ) {
		if ( '' === $source_key || '' === $target_key || $before['entity_id'] !== $after['entity_id'] || $before['strategy'] !== $after['strategy'] || $before['storage_slug'] !== $after['storage_slug'] ) {
			return new \WP_Error( 'eit_migration_identity_unsupported', __( 'Field migration cannot cross Entity storage identities.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! in_array( $after['adapter'], [ 'cpt', 'cct' ], true ) || $after['adapter'] !== $before['adapter'] ) {
			return new \WP_Error( 'eit_migration_adapter_unsupported', __( 'The selected storage adapter does not provide staged field migrations.', 'elementor-implementation-toolkit' ) );
		}
		if ( in_array( $after['semantics']['type'], [ 'taxonomy', 'relation', 'repeatable_group', 'calculated' ], true ) || in_array( $before['semantics']['type'], [ 'taxonomy', 'relation', 'repeatable_group', 'calculated' ], true ) ) {
			return new \WP_Error( 'eit_migration_field_unsupported', __( 'This normalized or derived field requires a dedicated migration adapter.', 'elementor-implementation-toolkit' ) );
		}
		if ( $semantics_changed && $source_key === $target_key ) {
			return new \WP_Error( 'eit_migration_new_storage_required', __( 'A field type or shape change must compile to new physical storage.', 'elementor-implementation-toolkit' ) );
		}
		$aliases = array_map( 'sanitize_key', is_array( $binding['aliases'] ?? null ) ? $binding['aliases'] : [] );
		if ( $source_key !== $target_key && ! in_array( $source_key, $aliases, true ) ) {
			return new \WP_Error( 'eit_migration_source_alias_required', __( 'The previous storage key must remain as a compatibility alias.', 'elementor-implementation-toolkit' ) );
		}
		return $this->transformer->strategy( $before['semantics'], $after['semantics'] );
	}

	private function field_index( array $artifacts ) {
		$index = [];
		foreach ( $artifacts as $artifact ) {
			if ( 'entity_definition' !== ( $artifact['kind'] ?? '' ) ) {
				continue;
			}
			$payload = $artifact['payload'] ?? [];
			foreach ( $payload['fields'] ?? [] as $field ) {
				$field_id = (string) ( $field['id'] ?? '' );
				if ( '' === $field_id ) {
					continue;
				}
				$index[ $field_id ] = [
					'entity_id' => (string) ( $payload['entity_id'] ?? $artifact['node_id'] ?? '' ),
					'adapter' => sanitize_key( $payload['adapter']['id'] ?? '' ),
					'strategy' => sanitize_key( $payload['strategy'] ?? '' ),
					'storage_slug' => sanitize_key( $payload['definition']['slug'] ?? '' ),
					'semantics' => [ 'type' => sanitize_key( $field['type'] ?? '' ), 'shape' => sanitize_key( $field['shape'] ?? '' ) ],
				];
			}
		}
		return $index;
	}

	private function blocker( \WP_Error $error, $field_id, array $before, array $after, $source_key, $target_key ) {
		return [
			'code' => $error->get_error_code(),
			'node_id' => $after['entity_id'] ?? $before['entity_id'] ?? '',
			'field_id' => (string) $field_id,
			'from' => array_merge( [ 'storage_key' => $source_key ], $before['semantics'] ?? [] ),
			'to' => array_merge( [ 'storage_key' => $target_key ], $after['semantics'] ?? [] ),
			'message' => $error->get_error_message(),
		];
	}
}
