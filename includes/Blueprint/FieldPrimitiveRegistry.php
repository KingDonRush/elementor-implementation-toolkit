<?php
/**
 * Semantic primitive registry used by schema, forms, filters and Elementor.
 */

namespace EIT\Blueprint;

use EIT\Contracts\FieldPrimitiveInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FieldPrimitiveRegistry {

	private $primitives = [];

	public function __construct( $register_defaults = true ) {
		if ( $register_defaults ) {
			$this->register_defaults();
		}
	}

	public function register( FieldPrimitiveInterface $primitive ) {
		$id = strtolower( trim( (string) $primitive->get_id() ) );
		if ( ! preg_match( '/^[a-z][a-z0-9_]{1,47}$/', $id ) ) {
			throw new \InvalidArgumentException( 'Field primitive ID is invalid.' );
		}
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', (string) $primitive->get_version() ) ) {
			throw new \InvalidArgumentException( 'Field primitive version must use semantic versioning.' );
		}
		if ( isset( $this->primitives[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal SDK exception, not rendered output.
			throw new \LogicException( 'Field primitive is already registered: ' . $id );
		}
		$this->primitives[ $id ] = $primitive;
		return $primitive;
	}

	public function has( $id ) {
		return isset( $this->primitives[ strtolower( (string) $id ) ] );
	}

	public function get( $id ) {
		return $this->primitives[ strtolower( (string) $id ) ] ?? null;
	}

	public function all() {
		ksort( $this->primitives );
		return $this->primitives;
	}

	public function health() {
		$health = [];
		foreach ( $this->all() as $id => $primitive ) {
			$result = $primitive->health_check();
			$health[ $id ] = is_array( $result ) ? $result : [ 'ok' => false, 'message' => 'Invalid health response.' ];
		}
		return $health;
	}

	private function register_defaults() {
		foreach ( $this->default_definitions() as $id => $definition ) {
			$this->register( new BuiltinFieldPrimitive( $id, $definition ) );
		}
	}

	private function default_definitions() {
		return [
			'short_text'      => $this->definition( 'scalar', [ 'text' ], [ 'text' ], true, true, true ),
			'long_text'       => $this->definition( 'scalar', [ 'textarea' ], [ 'text' ], true, false, false ),
			'rich_text'       => $this->definition( 'scalar', [ 'rich_text' ], [ 'text' ], true, false, false ),
			'integer'         => $this->definition( 'scalar', [ 'number' ], [ 'number', 'text' ], true, true, true ),
			'decimal'         => $this->definition( 'scalar', [ 'number' ], [ 'number', 'text' ], true, true, true ),
			'money'           => $this->definition( 'object', [ 'money' ], [ 'number', 'text' ], true, true, true ),
			'percentage'      => $this->definition( 'scalar', [ 'number' ], [ 'number', 'text' ], true, true, true ),
			'calculated'      => $this->definition( 'scalar', [ 'calculated' ], [ 'number', 'text' ], false, true, true ),
			'boolean'         => $this->definition( 'scalar', [ 'toggle' ], [ 'text' ], true, true, true ),
			'single_choice'   => $this->definition( 'scalar', [ 'select', 'radio' ], [ 'text' ], true, true, true ),
			'multiple_choice' => $this->definition( 'list', [ 'checkboxes', 'chips' ], [ 'text' ], true, true, false ),
			'date'            => $this->definition( 'scalar', [ 'date' ], [ 'text' ], true, true, true ),
			'time'            => $this->definition( 'scalar', [ 'time' ], [ 'text' ], true, true, true ),
			'datetime'        => $this->definition( 'scalar', [ 'datetime' ], [ 'text' ], true, true, true ),
			'schedule'        => $this->definition( 'list', [ 'schedule' ], [ 'text' ], true, true, true ),
			'availability'    => $this->definition( 'object', [ 'availability' ], [ 'text' ], true, true, true ),
			'image'           => $this->definition( 'object', [ 'media' ], [ 'image', 'url' ], false, false, false ),
			'gallery'         => $this->definition( 'list', [ 'gallery' ], [ 'gallery' ], false, false, false ),
			'file'            => $this->definition( 'object', [ 'file' ], [ 'url', 'text' ], false, false, false ),
			'email'           => $this->definition( 'scalar', [ 'email' ], [ 'text', 'url' ], true, true, true ),
			'phone'           => $this->definition( 'scalar', [ 'phone' ], [ 'text', 'url' ], true, true, true ),
			'url'             => $this->definition( 'scalar', [ 'url' ], [ 'url', 'text' ], true, true, true ),
			'color'           => $this->definition( 'scalar', [ 'color' ], [ 'color', 'text' ], false, true, true ),
			'address'         => $this->definition( 'object', [ 'address' ], [ 'text' ], true, true, false ),
			'geopoint'        => $this->definition( 'object', [ 'geopoint' ], [ 'text' ], true, true, true ),
			'taxonomy'        => $this->definition( 'list', [ 'taxonomy' ], [ 'text', 'url' ], true, true, true ),
			'relation'        => $this->definition( 'list', [ 'relation' ], [ 'text', 'url' ], true, true, false ),
			'repeatable_group' => $this->definition( 'list', [ 'repeater' ], [ 'text' ], false, false, false ),
		];
	}

	private function definition( $shape, array $components, array $elementor, $search, $filter, $sort ) {
		return [
			'shape'        => $shape,
			'components'   => $components,
			'elementor'    => $elementor,
			'capabilities' => [ 'search' => $search, 'filter' => $filter, 'sort' => $sort ],
		];
	}
}
