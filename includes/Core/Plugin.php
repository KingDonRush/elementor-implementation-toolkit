<?php
/**
 * Main plugin coordinator.
 */

namespace EIT\Core;

use EIT\Admin\AdminPages;
use EIT\CPT\CptManager;
use EIT\Elementor\ElementorIntegration;
use EIT\Rest\FilterControllerEndpoint;
use EIT\Support\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	public function run() {
		( new CptManager() )->init_hooks();
		( new Assets() )->init_hooks();
		( new AdminPages() )->init_hooks();
		( new FilterControllerEndpoint() )->init_hooks();
		( new ElementorIntegration() )->init_hooks();
	}
}
