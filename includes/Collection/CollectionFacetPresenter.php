<?php
/**
 * Converts provider counts into labeled Filter Surface facet state.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionFacetPresenter {

	public function present( array $counts, array $request, array $contract, array $fields ) {
		$controls = array_column( $contract['filter_surface']['controls'] ?? [], null, 'field_id' );
		$result = [];
		foreach ( $request['facets'] ?? [] as $field_id ) {
			if ( ! isset( $fields[ $field_id ] ) ) {
				continue;
			}
			$options = array_column( $controls[ $field_id ]['options'] ?? [], 'label', 'value' );
			$values = [];
			$field_counts = $counts[ $field_id ] ?? [];
			$facet_values = array_values( array_unique( array_merge( array_map( 'strval', array_keys( $options ) ), array_map( 'strval', array_keys( $field_counts ) ) ) ) );
			foreach ( $facet_values as $value ) {
				$count = (int) ( $field_counts[ $value ] ?? 0 );
				$values[] = [
					'value' => (string) $value,
					'label' => sanitize_text_field( $options[ (string) $value ] ?? (string) $value ),
					'count' => max( 0, $count ),
					'available' => $count > 0,
				];
			}
			$result[] = [ 'field_id' => $field_id, 'label' => $fields[ $field_id ]['name'], 'values' => $values ];
		}
		return $result;
	}
}
