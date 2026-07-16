<?php
/**
 * Read-only catalog of active contracts exposed to Elementor controls.
 */

namespace EIT\Elementor\Contracts;

use EIT\CCT\CurrentItemContext;
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

	public function __construct( BlueprintStore $blueprints = null, ArtifactStore $artifacts = null ) {
		$this->blueprints = $blueprints ?: new BlueprintStore();
		$this->artifacts = $artifacts ?: new ArtifactStore();
	}

	public function field( $field_id ) {
		$field_id = strtolower( trim( (string) $field_id ) );
		return $this->fields()[ $field_id ] ?? null;
	}

	public function field_options( array $categories = [] ) {
		$options = [ '' => __( 'Select a published Field', 'elementor-implementation-toolkit' ) ];
		$context_type = $this->current_context_type();
		foreach ( $this->fields() as $field_id => $context ) {
			$field = $context['field'];
			if ( $categories && ! array_intersect( $categories, $field['elementor'] ?? [] ) ) {
				continue;
			}
			if ( $context_type && ! $this->matches_context( $context['entity'], $context_type ) ) {
				continue;
			}
			$options[ $field_id ] = sprintf( '%1$s — %2$s', $context['entity']['name'], $field['name'] );
		}
		return $options;
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

	private function current_context_type() {
		$type = CurrentItemContext::type();
		if ( $type ) {
			return $type;
		}
		$post_type = get_post_type( get_the_ID() );
		return 'elementor_library' === $post_type ? '' : sanitize_key( $post_type );
	}

	private function matches_context( array $entity, $context_type ) {
		if ( 'woocommerce' === ( $entity['adapter']['id'] ?? '' ) ) {
			return in_array( $context_type, [ 'product', 'woocommerce' ], true );
		}
		return $context_type === sanitize_key( $entity['definition']['slug'] ?? '' );
	}
}
