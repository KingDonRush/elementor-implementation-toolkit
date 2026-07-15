<?php
/**
 * Data-driven implementation of a built-in semantic field primitive.
 */

namespace EIT\Blueprint;

use EIT\Contracts\FieldPrimitiveInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BuiltinFieldPrimitive implements FieldPrimitiveInterface {

	private $id;
	private $definition;

	public function __construct( $id, array $definition ) {
		$this->id = (string) $id;
		$this->definition = $definition;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_version() {
		return '1.0.0';
	}

	public function get_definition() {
		return $this->definition;
	}

	public function normalize( $value, array $contract = [] ) {
		$shape = $contract['shape'] ?? $this->definition['shape'];
		if ( 'list' === $shape ) {
			return is_array( $value ) ? array_values( $value ) : ( null === $value || '' === $value ? [] : [ $value ] );
		}
		if ( 'object' === $shape ) {
			return is_array( $value ) ? $value : [];
		}
		return $value;
	}

	public function validate( $value, array $contract = [] ) {
		$required = ! empty( $contract['validation']['required'] );
		$is_empty = null === $value || '' === $value || ( is_array( $value ) && [] === $value );
		return $required && $is_empty ? [ 'required' ] : [];
	}

	public function health_check() {
		return [ 'ok' => true, 'version' => $this->get_version() ];
	}
}
