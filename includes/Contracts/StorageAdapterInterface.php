<?php
/**
 * Public contract for storage adapters.
 */

namespace EIT\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface StorageAdapterInterface {

	public function get_id();

	public function get_version();

	public function get_capabilities();

	public function compile( array $entity, array $fields, array $context = [] );

	public function prepare( array $artifact, array $context = [] );

	public function health_check();
}
