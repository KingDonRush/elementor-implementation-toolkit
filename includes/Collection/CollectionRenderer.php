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
		$contract = ( new CollectionSurfaceResolver() )->get( $attributes['id'] );
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
			'<div class="eit-collection-surface" data-eit-collection="%1$s">%2$s</div>',
			esc_attr( $contract['collection_id'] ),
			wp_kses_post( $result['html'] )
		);
	}
}
