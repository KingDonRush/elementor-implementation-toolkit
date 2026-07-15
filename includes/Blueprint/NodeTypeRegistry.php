<?php
/**
 * Canonical node lanes and typed connection grammar.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NodeTypeRegistry {

	private $nodes = [
		'entity'         => 'data',
		'field_group'    => 'data',
		'relation'       => 'data',
		'entry_surface'  => 'experience',
		'collection'     => 'experience',
		'filter_surface' => 'experience',
		'presentation'   => 'presentation',
		'route'          => 'presentation',
		'policy'         => 'governance',
		'adapter'        => 'governance',
	];

	private $connections = [
		'entity_fields'       => [ 'entity', 'field_group', true ],
		'relation_source'     => [ 'entity', 'relation', false ],
		'relation_target'     => [ 'relation', 'entity', false ],
		'entry_for'           => [ 'entity', 'entry_surface', true ],
		'collection_for'      => [ 'entity', 'collection', true ],
		'filters'             => [ 'collection', 'filter_surface', true ],
		'presents_entity'     => [ 'entity', 'presentation', true ],
		'presents_entry'      => [ 'entry_surface', 'presentation', true ],
		'presents_collection' => [ 'collection', 'presentation', true ],
		'composes'            => [ 'presentation', 'presentation', true ],
		'routes'              => [ 'presentation', 'route', true ],
		'governs_entity'      => [ 'policy', 'entity', false ],
		'governs_entry'       => [ 'policy', 'entry_surface', false ],
		'governs_collection'  => [ 'policy', 'collection', false ],
		'adapts'              => [ 'adapter', 'entity', false ],
	];

	public function has( $type ) {
		return isset( $this->nodes[ (string) $type ] );
	}

	public function lane( $type ) {
		return $this->nodes[ (string) $type ] ?? null;
	}

	public function nodes() {
		return $this->nodes;
	}

	public function connections() {
		return $this->connections;
	}

	public function connection_is_valid( $connection_type, $from_type, $to_type ) {
		$definition = $this->connections[ (string) $connection_type ] ?? null;
		return $definition && $definition[0] === $from_type && $definition[1] === $to_type;
	}

	public function connection_is_acyclic( $connection_type ) {
		return ! empty( $this->connections[ (string) $connection_type ][2] );
	}
}
