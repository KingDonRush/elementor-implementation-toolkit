<?php
/**
 * Public contract for presentation adapters.
 */

namespace EIT\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PresentationAdapterInterface {

	public function get_id();

	public function get_version();

	public function get_capabilities();

	public function compile( array $presentation, array $context = [] );

	public function render( array $contract, array $context = [] );

	public function health_check();
}
