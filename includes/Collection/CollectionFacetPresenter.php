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
				$entry = $field_counts[ $value ] ?? 0;
				$count = (int) ( is_array( $entry ) ? ( $entry['count'] ?? 0 ) : $entry );
				$label = is_array( $entry ) ? ( $entry['label'] ?? $value ) : ( $options[ (string) $value ] ?? $this->fallback_label( $value, $fields[ $field_id ] ) );
				$values[] = [
					'value' => (string) $value,
					'label' => sanitize_text_field( $label ),
					'count' => max( 0, $count ),
					'available' => $count > 0,
				];
			}
			$result[] = [ 'field_id' => $field_id, 'label' => $fields[ $field_id ]['name'], 'values' => $values ];
		}
		return $result;
	}

	private function fallback_label( $value, array $field ) {
		if ( 'boolean' === ( $field['type'] ?? '' ) ) {
			return in_array( (string) $value, [ '1', 'true' ], true )
				? __( 'Yes', 'elementor-implementation-toolkit' )
				: __( 'No', 'elementor-implementation-toolkit' );
		}
		return (string) $value;
	}
}
