<?php
/**
 * Resolves the current content type through public WordPress and Elementor APIs.
 */

namespace EIT\Elementor\Contracts;

use EIT\CCT\CurrentItemContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorContextResolver {

	private $current_item_type;
	private $current_post_id;
	private $post_type;
	private $document;

	public function __construct( $current_item_type = null, $current_post_id = null, $post_type = null, $document = null ) {
		$this->current_item_type = $current_item_type ?: fn() => CurrentItemContext::type();
		$this->current_post_id = $current_post_id ?: fn() => get_the_ID();
		$this->post_type = $post_type ?: fn( $post_id ) => get_post_type( $post_id );
		$this->document = $document ?: fn( $post_id ) => $this->elementor_document( $post_id );
	}

	public function type() {
		$current_type = sanitize_key( (string) call_user_func( $this->current_item_type ) );
		if ( '' !== $current_type ) {
			return $current_type;
		}

		$post_id = absint( call_user_func( $this->current_post_id ) );
		$post_type = sanitize_key( (string) call_user_func( $this->post_type, $post_id ) );
		if ( 'elementor_library' !== $post_type ) {
			return $post_type;
		}

		return $this->theme_builder_type( call_user_func( $this->document, $post_id ) );
	}

	private function theme_builder_type( $document ) {
		$preview_id = absint( $this->document_setting( $document, 'preview_id' ) );
		if ( $preview_id ) {
			$preview_post_type = sanitize_key( (string) call_user_func( $this->post_type, $preview_id ) );
			if ( '' !== $preview_post_type && 'elementor_library' !== $preview_post_type ) {
				return $preview_post_type;
			}
		}

		$preview_type = sanitize_text_field( (string) $this->document_setting( $document, 'preview_type' ) );
		list( $scope, $object_type ) = array_pad( explode( '/', $preview_type, 2 ), 2, '' );
		return 'single' === $scope ? sanitize_key( $object_type ) : '';
	}

	private function document_setting( $document, $key ) {
		if ( is_array( $document ) ) {
			return $document[ $key ] ?? '';
		}
		return is_object( $document ) && method_exists( $document, 'get_settings' )
			? $document->get_settings( $key )
			: '';
	}

	private function elementor_document( $post_id ) {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! \Elementor\Plugin::$instance || ! \Elementor\Plugin::$instance->documents ) {
			return null;
		}

		$documents = \Elementor\Plugin::$instance->documents;
		$current = method_exists( $documents, 'get_current' ) ? $documents->get_current() : null;
		if ( $current ) {
			return $current;
		}
		return method_exists( $documents, 'get_doc_or_auto_save' )
			? $documents->get_doc_or_auto_save( $post_id, get_current_user_id() )
			: null;
	}
}
