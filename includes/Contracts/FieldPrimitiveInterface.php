<?php
/**
 * Public contract for semantic field primitives.
 */

namespace EIT\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface FieldPrimitiveInterface {

	public function get_id();

	public function get_version();

	public function get_definition();

	public function normalize( $value, array $contract = [] );

	public function validate( $value, array $contract = [] );

	public function health_check();
}
