<?php
/**
 * Compiles Presentation nodes into stable Elementor connector contracts.
 */

namespace EIT\Elementor;

use EIT\Contracts\PresentationAdapterInterface;
use EIT\Elementor\Contracts\ElementorTemplateCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorPresentationAdapter implements PresentationAdapterInterface {

	private $templates;

	public function __construct( ElementorTemplateCatalog $templates = null ) {
		$this->templates = $templates ?: new ElementorTemplateCatalog();
	}

	public function get_id() {
		return 'elementor';
	}

	public function get_version() {
		return '1.0.0';
	}

	public function get_capabilities() {
		return [
			'field_widget',
			'collection_surface_widget',
			'filter_surface_widget',
			'entry_surface_widget',
			'action_widget',
			'public_document_manager',
			'typed_dynamic_tags_optional',
		];
	}

	public function compile( array $presentation, array $context = [] ) {
		$template_id = absint( $presentation['config']['template_id'] ?? 0 );
		if ( $template_id && ! $this->templates->is_public( $template_id ) ) {
			return new \WP_Error( 'eit_elementor_template_unavailable', __( 'The selected Elementor template must exist and be published.', 'elementor-implementation-toolkit' ) );
		}
		$sources = [];
		$connector = 'field';
		foreach ( $context['connections'] ?? [] as $connection ) {
			$type = sanitize_key( $connection['type'] ?? '' );
			if ( ! in_array( $type, [ 'presents_entity', 'presents_entry', 'presents_collection', 'composes' ], true ) ) {
				continue;
			}
			$source_id = $presentation['id'] === ( $connection['to'] ?? '' ) ? ( $connection['from'] ?? '' ) : ( $connection['to'] ?? '' );
			$sources[] = [ 'connection' => $type, 'node_id' => (string) $source_id ];
			if ( 'presents_entry' === $type ) {
				$connector = 'entry_surface';
			} elseif ( 'presents_collection' === $type ) {
				$connector = 'collection_surface';
			}
		}

		return [
			'node_id' => $presentation['id'],
			'name' => $presentation['name'],
			'adapter' => [
				'id' => $this->get_id(),
				'version' => $this->get_version(),
				'capabilities' => $this->get_capabilities(),
			],
			'connector' => $connector,
			'sources' => $sources,
			'template_id' => $template_id,
		];
	}

	public function render( array $contract, array $context = [] ) {
		return '';
	}

	public function health_check() {
		$available = did_action( 'elementor/loaded' ) > 0 || defined( 'ELEMENTOR_VERSION' );
		return [
			'ok' => $available,
			'version' => $this->get_version(),
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
		];
	}
}
