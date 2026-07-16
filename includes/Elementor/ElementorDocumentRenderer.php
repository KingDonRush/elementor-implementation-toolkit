<?php
/**
 * Renders an Elementor document through the public Core document manager.
 */

namespace EIT\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorDocumentRenderer {

	private $documents = [];

	public function render( $template_id ) {
		$document = $this->document( $template_id );
		if ( ! $document || ! method_exists( $document, 'print_content' ) ) {
			return '';
		}
		ob_start();
		try {
			$document->print_content();
			return (string) ob_get_clean();
		} catch ( \Throwable $error ) {
			ob_end_clean();
			do_action( 'eit_elementor_document_render_error', absint( $template_id ), $error );
			return '';
		}
	}

	private function document( $template_id ) {
		$template_id = absint( $template_id );
		if ( isset( $this->documents[ $template_id ] ) ) {
			return $this->documents[ $template_id ];
		}
		$plugin = class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::$instance : null;
		$manager = is_object( $plugin ) ? $plugin->documents : null;
		$this->documents[ $template_id ] = is_object( $manager ) && method_exists( $manager, 'get' ) ? $manager->get( $template_id ) : null;
		return $this->documents[ $template_id ];
	}
}
