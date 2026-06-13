<?php
/**
 * CCT subsystem bootstrap.
 */

namespace EIT\CCT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctModule {

	public function init_hooks() {
		add_action( 'plugins_loaded', [ SchemaManager::class, 'maybe_upgrade' ], 5 );
	}
}
