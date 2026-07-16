<?php
/**
 * Builds core registries and opens the contextual code-extension hook.
 */

namespace EIT\Blueprint;

use EIT\Collection\CctCollectionProvider;
use EIT\Collection\CptCollectionProvider;
use EIT\Collection\LegacyDomCollectionProvider;
use EIT\Collection\WooCollectionProvider;
use EIT\Elementor\ElementorPresentationAdapter;
use EIT\Entry\CoreFormAction;
use EIT\Registry\RegistryHub;
use EIT\Woo\WooStorageAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoreRegistryFactory {

	public function create() {
		$hub = new RegistryHub();
		$hub->storage_adapters()->register( new CoreStorageAdapter( 'cpt' ) );
		$hub->storage_adapters()->register( new CoreStorageAdapter( 'cct' ) );
		$hub->storage_adapters()->register( new ReadOnlyLegacyStorageAdapter() );
		$hub->storage_adapters()->register( new WooStorageAdapter() );
		$hub->collection_providers()->register( new CptCollectionProvider() );
		$hub->collection_providers()->register( new CctCollectionProvider() );
		$hub->collection_providers()->register( new WooCollectionProvider() );
		$hub->collection_providers()->register( new LegacyDomCollectionProvider() );
		$hub->presentation_adapters()->register( new ElementorPresentationAdapter() );
		foreach ( [ 'redirect', 'email', 'notification', 'webhook' ] as $action_type ) {
			$hub->form_actions()->register( new CoreFormAction( $action_type ) );
		}
		do_action( 'eit_register_blueprint_extensions', $hub );
		return $hub;
	}
}
