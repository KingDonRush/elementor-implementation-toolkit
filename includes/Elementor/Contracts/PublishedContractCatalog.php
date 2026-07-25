<?php
/**
 * Read-only catalog of active contracts exposed to Elementor controls.
 */

namespace EIT\Elementor\Contracts;

use EIT\Entry\EntrySurfaceResolver;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PublishedContractCatalog {

	private $artifacts;
	private $blueprints;
	private $fields;
	private $context;

	public function __construct( ?BlueprintStore $blueprints = null, ?ArtifactStore $artifacts = null, ?ElementorContextResolver $context = null ) {
		$this->blueprints = $blueprints ?: new BlueprintStore();
		$this->artifacts = $artifacts ?: new ArtifactStore();
		$this->context = $context ?: new ElementorContextResolver();
	}

	public function field( $field_id, $entity_id = '' ) {
		$field_id = strtolower( trim( (string) $field_id ) );
		$context = $this->fields()[ $field_id ] ?? null;
		$resolved_entity_id = $this->resolved_entity_id( $entity_id );
		return $context && $resolved_entity_id === $this->entity_id( $context['entity'] ) ? $context : null;
	}

	public function field_options( array $categories = [], $entity_id = '' ) {
		$options = [ '' => __( 'Select a published Field', 'elementor-implementation-toolkit' ) ];
		$entity_id = $this->resolved_entity_id( $entity_id );
		if ( '' === $entity_id ) {
			return $options;
		}
		foreach ( $this->fields() as $field_id => $context ) {
			$field = $context['field'];
			if ( $categories && ! array_intersect( $categories, $field['elementor'] ?? [] ) ) {
				continue;
			}
			if ( $entity_id !== $this->entity_id( $context['entity'] ) ) {
				continue;
			}
			$options[ $field_id ] = (string) $field['name'];
		}
		return $options;
	}

	public function entity_options( array $categories = [] ) {
		$options = [ '' => __( 'Select an Entity', 'elementor-implementation-toolkit' ) ];
		foreach ( $this->entity_catalog( $categories ) as $entity_id => $entity ) {
			$options[ $entity_id ] = $entity['label'];
		}
		return $options;
	}

	public function editor_field_options( array $categories = [], $entity_id = '' ) {
		$options = $this->field_options( $categories, $entity_id );
		if ( 1 < count( $options ) || '' !== $this->resolved_entity_id( $entity_id ) ) {
			return $options;
		}
		foreach ( $this->fields() as $field_id => $context ) {
			$field = $context['field'];
			if ( $categories && ! array_intersect( $categories, $field['elementor'] ?? [] ) ) {
				continue;
			}
			$options[ $field_id ] = sprintf( '%1$s — %2$s', $this->entity_label( $context['entity'] ), $field['name'] );
		}
		return $options;
	}

	public function editor_field_catalog() {
		return [
			'contextEntityId' => $this->context_entity_id(),
			'entities' => $this->entity_catalog(),
		];
	}

	public function context_entity_id() {
		$context_type = $this->context->type();
		if ( '' === $context_type ) {
			return '';
		}
		$matches = [];
		foreach ( $this->fields() as $context ) {
			$entity_id = $this->entity_id( $context['entity'] );
			if ( $entity_id && $this->matches_context( $context['entity'], $context_type ) ) {
				$matches[ $entity_id ] = true;
			}
		}
		return 1 === count( $matches ) ? (string) array_key_first( $matches ) : '';
	}

	public function entry_options() {
		$options = [ '' => __( 'Select a published Entry Surface', 'elementor-implementation-toolkit' ) ];
		foreach ( ( new EntrySurfaceResolver() )->all() as $contract ) {
			$options[ $contract['surface_id'] ] = $contract['name'];
		}
		return $options;
	}

	private function fields() {
		if ( null !== $this->fields ) {
			return $this->fields;
		}
		$this->fields = [];
		foreach ( $this->blueprints->all() as $blueprint ) {
			$version_id = absint( $blueprint['active_version_id'] ?? 0 );
			if ( ! $version_id ) {
				continue;
			}
			$entities = $this->entities( $version_id );
			foreach ( $entities as $entity_id => $entity ) {
				foreach ( $entity['fields'] ?? [] as $field ) {
					$this->add_field( $field, $entity, $blueprint['id'], $version_id );
				}
			}
			foreach ( $this->artifacts->for_version( $version_id, 'field_contracts' ) as $artifact ) {
				$entity_id = $this->field_group_entity( $artifact['payload']['connections'] ?? [] );
				if ( ! isset( $entities[ $entity_id ] ) ) {
					continue;
				}
				foreach ( $artifact['payload']['config']['fields'] ?? [] as $field ) {
					$this->add_field( $field, $entities[ $entity_id ], $blueprint['id'], $version_id );
				}
			}
		}
		ksort( $this->fields );
		return $this->fields;
	}

	private function entities( $version_id ) {
		$entities = [];
		foreach ( $this->artifacts->for_version( $version_id, 'entity_definition' ) as $artifact ) {
			$entities[ $artifact['node_id'] ] = $artifact['payload'];
		}
		return $entities;
	}

	private function add_field( $field, array $entity, $blueprint_id, $version_id ) {
		$field_id = strtolower( (string) ( $field['id'] ?? '' ) );
		if ( '' === $field_id || isset( $this->fields[ $field_id ] ) ) {
			return;
		}
		$this->fields[ $field_id ] = [
			'field' => $field,
			'entity' => $entity,
			'blueprint_id' => (string) $blueprint_id,
			'version_id' => absint( $version_id ),
		];
	}

	private function field_group_entity( array $connections ) {
		foreach ( $connections as $connection ) {
			if ( 'entity_fields' === ( $connection['type'] ?? '' ) ) {
				return (string) ( $connection['from'] ?? '' );
			}
		}
		return '';
	}

	private function resolved_entity_id( $explicit_entity_id = '' ) {
		$explicit_entity_id = strtolower( trim( (string) $explicit_entity_id ) );
		$context_entity_id = $this->context_entity_id();
		if ( '' !== $context_entity_id ) {
			return '' === $explicit_entity_id || $explicit_entity_id === $context_entity_id ? $context_entity_id : '';
		}
		return isset( $this->entity_catalog()[ $explicit_entity_id ] ) ? $explicit_entity_id : '';
	}

	private function entity_catalog( array $categories = [] ) {
		$catalog = [];
		foreach ( $this->fields() as $context ) {
			$field = $context['field'];
			if ( $categories && ! array_intersect( $categories, $field['elementor'] ?? [] ) ) {
				continue;
			}
			$entity = $context['entity'];
			$entity_id = $this->entity_id( $entity );
			if ( '' === $entity_id ) {
				continue;
			}
			if ( ! isset( $catalog[ $entity_id ] ) ) {
				$catalog[ $entity_id ] = [
					'label' => $this->entity_label( $entity ),
					'fields' => [],
				];
			}
			$catalog[ $entity_id ]['fields'][ $field['id'] ] = [
				'label' => (string) $field['name'],
				'categories' => array_values( $field['elementor'] ?? [] ),
			];
		}
		ksort( $catalog );
		return $catalog;
	}

	private function entity_id( array $entity ) {
		return strtolower( trim( (string) ( $entity['entity_id'] ?? '' ) ) );
	}

	private function entity_label( array $entity ) {
		$name = sanitize_text_field( $entity['name'] ?? '' );
		$slug = sanitize_key( $entity['definition']['slug'] ?? '' );
		return $slug ? sprintf( '%1$s — %2$s', $name, $slug ) : $name;
	}

	private function matches_context( array $entity, $context_type ) {
		if ( 'woocommerce' === ( $entity['adapter']['id'] ?? '' ) ) {
			return in_array( $context_type, [ 'product', 'woocommerce' ], true );
		}
		return $context_type === sanitize_key( $entity['definition']['slug'] ?? '' );
	}
}
