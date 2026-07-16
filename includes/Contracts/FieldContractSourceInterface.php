<?php
/**
 * Optional contract for adapters that own a closed semantic field catalog.
 */

namespace EIT\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface FieldContractSourceInterface {

	public function get_field_contracts( array $entity = [], array $context = [] );
}
