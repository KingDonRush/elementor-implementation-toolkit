<?php
/**
 * Builds core registries and opens the contextual code-extension hook.
 */

namespace EIT\Blueprint;

use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoreRegistryFactory {

	public function create() {
		$hub = new RegistryHub();
		$hub->storage_adapters()->register( new CoreStorageAdapter( 'cpt' ) );
		$hub->storage_adapters()->register( new CoreStorageAdapter( 'cct' ) );
		$hub->storage_adapters()->register( new ReadOnlyLegacyStorageAdapter() );
		do_action( 'eit_register_blueprint_extensions', $hub );
		return $hub;
	}
}
