<?php
/**
 * Verifies adapter-owned infrastructure before activating compiled artifacts.
 */

namespace EIT\Blueprint;

use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RuntimeArtifactPreparer {

	private $registries;

	public function __construct( RegistryHub $registries = null ) {
		$this->registries = $registries ?: ( new CoreRegistryFactory() )->create();
	}

	public function prepare( array $artifacts, array $context = [] ) {
		foreach ( $artifacts as $artifact ) {
			if ( 'entity_definition' !== ( $artifact['kind'] ?? '' ) ) {
				continue;
			}
			$adapter_id = $artifact['payload']['adapter']['id'] ?? '';
			$adapter = $this->registries->storage_adapters()->get( $adapter_id );
			if ( ! $adapter ) {
				return new \WP_Error( 'eit_storage_adapter_missing', __( 'Compiled storage adapter is not registered.', 'elementor-implementation-toolkit' ) );
			}
			$result = $adapter->prepare( $artifact, $context );
			if ( is_wp_error( $result ) || true !== $result ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'eit_storage_prepare_failed', __( 'Storage adapter could not prepare its compiled artifact.', 'elementor-implementation-toolkit' ) );
			}
		}
		return true;
	}
}
