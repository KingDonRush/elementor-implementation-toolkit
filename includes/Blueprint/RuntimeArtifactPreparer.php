<?php
/**
 * Verifies adapter-owned infrastructure before activating compiled artifacts.
 */

namespace EIT\Blueprint;

use EIT\Registry\ExtensionContract;
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
			$compiled = is_array( $artifact['payload']['adapter'] ?? null ) ? $artifact['payload']['adapter'] : [];
			$verified = ExtensionContract::verify( $adapter, $compiled );
			if ( is_wp_error( $verified ) && 'eit_extension_contract_mismatch' !== $verified->get_error_code() ) {
				return new \WP_Error( 'eit_storage_adapter_unhealthy', __( 'Compiled storage adapter failed its runtime canary.', 'elementor-implementation-toolkit' ) );
			}
			if ( is_wp_error( $verified ) ) {
				return new \WP_Error( 'eit_storage_adapter_contract_mismatch', __( 'Storage adapter version or capabilities differ from the published contract.', 'elementor-implementation-toolkit' ) );
			}
			try {
				$result = $adapter->prepare( $artifact, $context );
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'eit_storage_prepare_failed', __( 'Storage adapter could not prepare its compiled artifact.', 'elementor-implementation-toolkit' ) );
			}
			if ( is_wp_error( $result ) || true !== $result ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'eit_storage_prepare_failed', __( 'Storage adapter could not prepare its compiled artifact.', 'elementor-implementation-toolkit' ) );
			}
		}
		return true;
	}

}
