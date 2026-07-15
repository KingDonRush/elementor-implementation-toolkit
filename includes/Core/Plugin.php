<?php
/**
 * Main plugin coordinator.
 */

namespace EIT\Core;

use EIT\Admin\AdminPages;
use EIT\Blueprint\BlueprintModule;
use EIT\CCT\CctModule;
use EIT\CPT\CptManager;
use EIT\Elementor\ElementorIntegration;
use EIT\Infrastructure\InfrastructureModule;
use EIT\Rest\FilterControllerEndpoint;
use EIT\Rest\FilterPresetEndpoint;
use EIT\Support\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	public function run() {
		( new InfrastructureModule() )->init_hooks();
		( new BlueprintModule() )->init_hooks();
		( new CctModule() )->init_hooks();
		( new CptManager() )->init_hooks();
		( new Assets() )->init_hooks();
		( new AdminPages() )->init_hooks();
		( new FilterControllerEndpoint() )->init_hooks();
		( new FilterPresetEndpoint() )->init_hooks();
		( new ElementorIntegration() )->init_hooks();
	}
}
