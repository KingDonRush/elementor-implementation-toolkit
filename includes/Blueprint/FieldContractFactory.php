<?php
/**
 * Expands a concise field intention into the complete public field contract.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FieldContractFactory {

	private $primitives;

	public function __construct( FieldPrimitiveRegistry $primitives ) {
		$this->primitives = $primitives;
	}

	public function make( $id, $name, $type, array $overrides = [] ) {
		$primitive = $this->primitives->get( $type );
		if ( ! $primitive ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal SDK exception, not rendered output.
			throw new \InvalidArgumentException( 'Unknown field primitive: ' . (string) $type );
		}
		$definition = $primitive->get_definition();
		$storage_key = 'eit_' . substr( str_replace( '-', '', strtolower( (string) $id ) ), 0, 16 );
		$base = [
			'id'           => (string) $id,
			'name'         => trim( (string) $name ),
			'type'         => (string) $type,
			'shape'        => $definition['shape'],
			'validation'   => [ 'required' => false ],
			'exposure'     => [ 'public' => false, 'roles' => [] ],
			'storage'      => [ 'key' => $storage_key, 'aliases' => [] ],
			'indexing'     => [ 'search' => false, 'filter' => false, 'sort' => false ],
			'components'   => $definition['components'],
			'elementor'    => $definition['elementor'],
			'capabilities' => $definition['capabilities'],
		];
		return array_replace_recursive( $base, $overrides );
	}
}
