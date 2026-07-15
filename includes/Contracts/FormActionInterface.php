<?php
/**
 * Public contract for Entry Surface actions.
 */

namespace EIT\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface FormActionInterface {

	public function get_id();

	public function get_version();

	public function get_capabilities();

	public function execute( array $action, array $context = [] );

	public function health_check();
}
