<?php
/**
 * Runtime schema upgrade hook for Blueprint infrastructure only.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InfrastructureModule {

	public function init_hooks() {
		add_action( 'plugins_loaded', [ SchemaManager::class, 'maybe_upgrade' ], 4 );
	}
}
