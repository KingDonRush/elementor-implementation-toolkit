<?php
/**
 * Uses a compiled Elementor Presentation when available, with semantic fallback.
 */

namespace EIT\Collection;

use EIT\CCT\CurrentItemContext;
use EIT\Elementor\Contracts\ElementorTemplateCatalog;
use EIT\Elementor\ElementorDocumentRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionPresentationRenderer {

	private $fallback;
	private $templates;
	private $documents;

	public function __construct(
		?CollectionHtmlRenderer $fallback = null,
		?ElementorTemplateCatalog $templates = null,
		?ElementorDocumentRenderer $documents = null
	) {
		$this->fallback = $fallback ?: new CollectionHtmlRenderer();
		$this->templates = $templates ?: new ElementorTemplateCatalog();
		$this->documents = $documents ?: new ElementorDocumentRenderer();
	}

	public function render( array $items, array $fields, array $contract = [] ) {
		if ( ! $items ) {
			return $this->fallback->render( $items, $fields );
		}
		$template_id = absint( $contract['presentation']['template_id'] ?? 0 );
		if ( ! $template_id || ! $this->templates->is_public( $template_id ) ) {
			return $this->fallback->render( $items, $fields );
		}
		$markup = [];
		foreach ( $items as $item ) {
			$rendered = $this->render_item( $template_id, $item, $contract );
			if ( '' === trim( $rendered ) ) {
				return $this->fallback->render( $items, $fields );
			}
			$markup[] = sprintf(
				'<article class="eit-collection-item eit-collection-item--elementor" data-eit-collection-item data-item-id="%1$s">%2$s</article>',
				esc_attr( $item['id'] ?? '' ),
				$rendered
			);
		}
		return '<div class="eit-collection-items eit-collection-items--elementor" data-eit-collection-items>' . implode( '', $markup ) . '</div>';
	}

	private function render_item( $template_id, array $item, array $contract ) {
		$type = $contract['entity']['definition']['slug'] ?? $contract['entity']['adapter']['id'] ?? 'collection';
		CurrentItemContext::push( $type, $item );
		try {
			return $this->documents->render( $template_id );
		} finally {
			CurrentItemContext::pop();
		}
	}
}
