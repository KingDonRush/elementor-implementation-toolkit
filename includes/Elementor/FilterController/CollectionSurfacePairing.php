<?php
/**
 * Resolves a Filter Surface from the authoritative Collection widget on a document.
 */

namespace EIT\Elementor\FilterController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionSurfacePairing {

	private $document_elements;

	public function __construct( $document_elements = null ) {
		$this->document_elements = $document_elements ?: fn() => $this->current_document_elements();
	}

	public function resolve_current( $compatibility_collection_id = '' ) {
		return $this->resolve( (array) call_user_func( $this->document_elements ), $compatibility_collection_id );
	}

	public function resolve( array $elements, $compatibility_collection_id = '' ) {
		$compatibility_collection_id = strtolower( trim( (string) $compatibility_collection_id ) );
		$candidates = $this->candidates( $elements );

		if ( ! $candidates ) {
			return '' !== $compatibility_collection_id
				? $this->pair( $compatibility_collection_id, '', 'compatibility' )
				: $this->error( 'eit_collection_pair_missing', __( 'Place a Toolkit Collection Surface on this document.', 'elementor-implementation-toolkit' ) );
		}

		if ( 1 === count( $candidates ) ) {
			$candidate = $candidates[0];
			if ( '' === $candidate['collection_id'] ) {
				return $this->error( 'eit_collection_pair_unconfigured', __( 'The Collection Surface must select a published Collection.', 'elementor-implementation-toolkit' ) );
			}
			if ( '' !== $compatibility_collection_id && $compatibility_collection_id !== $candidate['collection_id'] ) {
				return $this->error( 'eit_collection_pair_mismatch', __( 'The Filter Surface override does not match the Collection Surface on this document.', 'elementor-implementation-toolkit' ) );
			}
			return $this->pair( $candidate['collection_id'], $candidate['widget_id'], 'document' );
		}

		if ( '' === $compatibility_collection_id ) {
			return $this->error( 'eit_collection_pair_ambiguous', __( 'This document has several Collection Surfaces. Choose the specific Collection in the Filter Surface compatibility control.', 'elementor-implementation-toolkit' ) );
		}

		$matches = array_values( array_filter( $candidates, fn( $candidate ) => $compatibility_collection_id === $candidate['collection_id'] ) );
		if ( 1 !== count( $matches ) ) {
			return $this->error( 'eit_collection_pair_mismatch', __( 'The Filter Surface override must match exactly one Collection Surface on this document.', 'elementor-implementation-toolkit' ) );
		}
		return $this->pair( $compatibility_collection_id, $matches[0]['widget_id'], 'disambiguated' );
	}

	private function candidates( array $elements ) {
		$candidates = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( 'eit-toolkit-collection-surface' === ( $element['widgetType'] ?? '' ) ) {
				$candidates[] = [
					'widget_id' => sanitize_text_field( $element['id'] ?? '' ),
					'collection_id' => strtolower( trim( (string) ( $element['settings']['collection_id'] ?? '' ) ) ),
				];
			}
			$candidates = array_merge( $candidates, $this->candidates( (array) ( $element['elements'] ?? [] ) ) );
		}
		return $candidates;
	}

	private function pair( $collection_id, $widget_id, $mode ) {
		return [
			'collection_id' => (string) $collection_id,
			'widget_id' => (string) $widget_id,
			'mode' => (string) $mode,
		];
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, $message );
	}

	private function current_document_elements() {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! \Elementor\Plugin::$instance || ! \Elementor\Plugin::$instance->documents ) {
			return [];
		}

		$documents = \Elementor\Plugin::$instance->documents;
		$document = method_exists( $documents, 'get_current' ) ? $documents->get_current() : null;
		if ( ! $document ) {
			$post_id = $this->editor_post_id() ?: get_the_ID();
			$document = $post_id && method_exists( $documents, 'get_doc_or_auto_save' )
				? $documents->get_doc_or_auto_save( $post_id, get_current_user_id() )
				: null;
		}
		return $document && method_exists( $document, 'get_elements_data' )
			? (array) $document->get_elements_data()
			: [];
	}

	private function editor_post_id() {
		$editor = \Elementor\Plugin::$instance->editor ?? null;
		return $editor && method_exists( $editor, 'get_post_id' ) ? absint( $editor->get_post_id() ) : 0;
	}
}
