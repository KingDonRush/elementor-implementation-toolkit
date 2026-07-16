<?php
/**
 * Shortcode fallback for a published Collection's semantic HTML.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionRenderer {

	public function shortcode( $attributes ) {
		$attributes = shortcode_atts( [ 'id' => '' ], $attributes, 'eit_collection' );
		return $this->render( $attributes['id'] );
	}

	public function render( $collection_id ) {
		$contract = ( new CollectionSurfaceResolver() )->get( $collection_id );
		if ( ! $contract || is_wp_error( ( new CollectionAccessPolicy() )->authorize( $contract ) ) ) {
			return '';
		}
		$request = ( new CollectionRequestValidator() )->validate( $contract, [] );
		if ( is_wp_error( $request ) ) {
			return '';
		}
		$result = ( new CollectionQueryService() )->execute( $contract, $request );
		if ( is_wp_error( $result ) ) {
			return '<div class="eit-collection-surface" role="alert">' . esc_html__( 'This collection could not be loaded.', 'elementor-implementation-toolkit' ) . '</div>';
		}
		wp_enqueue_script( 'eit-frontend' );
		wp_enqueue_style( 'eit-frontend' );
		return sprintf(
			'<section class="eit-collection-surface eit-toolkit-collection" data-eit-collection-surface="%1$s" aria-label="%2$s"><div data-eit-collection-results tabindex="-1">%3$s</div></section>',
			esc_attr( $contract['collection_id'] ),
			esc_attr( $contract['name'] ),
			$result['html'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted renderer escapes data and may contain Elementor document markup.
		);
	}
}
