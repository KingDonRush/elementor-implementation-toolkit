<?php
/**
 * Public Elementor manager compatibility canary for Collection presentations.
 */

namespace EIT\Elementor\Loop;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctLoopIntegration {

	private $health = [ 'ok' => false, 'checked' => false ];

	public function init_hooks() {
		add_action( 'elementor/init', [ $this, 'inspect_public_managers' ], 100 );
	}

	public function inspect_public_managers() {
		$plugin = class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::$instance : null;
		$documents = is_object( $plugin ) ? $plugin->documents : null;
		$widgets = is_object( $plugin ) ? $plugin->widgets_manager : null;
		$this->health = [
			'ok' => is_object( $documents ) && method_exists( $documents, 'get' ) && is_object( $widgets ),
			'checked' => true,
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'capabilities' => [ 'public_documents_manager', 'public_widgets_manager' ],
		];
		do_action( 'eit_elementor_public_bridge_canary', $this->health );
	}

	public function health_check() {
		return $this->health;
	}
}
