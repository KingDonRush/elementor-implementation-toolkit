<?php
/**
 * Resolves published Collections for the Elementor compatibility widget.
 */

namespace EIT\Elementor\FilterController;

use EIT\Collection\CollectionAccessPolicy;
use EIT\Collection\CollectionContractPresenter;
use EIT\Collection\CollectionSurfaceResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionWidgetBridge {

	public static function options() {
		$options = [ '' => __( 'Select a published Collection', 'elementor-implementation-toolkit' ) ];
		foreach ( ( new CollectionSurfaceResolver() )->all() as $contract ) {
			$access = 'public' === ( $contract['access'] ?? '' )
				? __( 'Public', 'elementor-implementation-toolkit' )
				: __( 'Signed-in', 'elementor-implementation-toolkit' );
			$options[ $contract['collection_id'] ] = sprintf( '%1$s — %2$s', $contract['name'], $access );
		}
		return $options;
	}

	public static function resolve( $collection_id ) {
		$collection_id = strtolower( trim( (string) $collection_id ) );
		if ( '' === $collection_id ) {
			return new \WP_Error( 'eit_collection_widget_unselected', __( 'Select a published Collection in the widget controls.', 'elementor-implementation-toolkit' ) );
		}
		$contract = ( new CollectionSurfaceResolver() )->get( $collection_id );
		if ( ! $contract ) {
			return new \WP_Error( 'eit_collection_widget_missing', __( 'The selected Collection is unavailable or no longer published.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! is_array( $contract['filter_surface'] ?? null ) ) {
			return new \WP_Error( 'eit_collection_widget_filter_missing', __( 'Publish a connected Filter Surface before using this Collection here.', 'elementor-implementation-toolkit' ) );
		}
		$authorized = ( new CollectionAccessPolicy() )->authorize( $contract );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		$browser_contract = ( new CollectionContractPresenter() )->present( $contract );
		$browser_contract['filter_surface']['facet_field_ids'] = array_values(
			array_column(
				array_filter(
					$browser_contract['filter_surface']['controls'],
					function ( $control ) {
						return ! empty( $control['facet'] );
					}
				),
				'field_id'
			)
		);
		return ( new CollectionWidgetContract() )->map( $browser_contract );
	}
}
