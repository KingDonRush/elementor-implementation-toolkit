<?php
/**
 * Public contract for Collection providers.
 */

namespace EIT\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface CollectionProviderInterface {

	public function get_id();

	public function get_version();

	public function get_capabilities();

	public function query( array $contract, array $request, array $context = [] );

	public function health_check();
}
