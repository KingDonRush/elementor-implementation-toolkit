<?php
/**
 * Builds core registries and opens the contextual code-extension hook.
 */

namespace EIT\Blueprint;

use EIT\Entry\CoreFormAction;
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
		foreach ( [ 'redirect', 'email', 'notification', 'webhook' ] as $action_type ) {
			$hub->form_actions()->register( new CoreFormAction( $action_type ) );
		}
		do_action( 'eit_register_blueprint_extensions', $hub );
		return $hub;
	}
}
