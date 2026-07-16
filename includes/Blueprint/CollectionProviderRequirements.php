<?php
/**
 * Derives provider obligations from the executable Collection contract.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionProviderRequirements {

	public function plan( $provider_id, array $collection, array $surface, array $fields, array $declared = [] ) {
		$fields = array_filter( array_column( array_values( $fields ), null, 'id' ), [ $this, 'is_public_field' ] );
		$filter_ids = ! empty( $surface['_connected'] ) ? $this->selected_fields( $fields, $surface['fields'] ?? [], 'filter' ) : [];
		$sort_ids = $this->selected_fields( $fields, $collection['sort_field_ids'] ?? [], 'sort' );
		$search_ids = $this->capability_fields( $fields, 'search' );
		$facet_ids = $filter_ids ? $this->facet_fields( $provider_id, $surface, $filter_ids, $fields ) : [];
		$required = array_values( array_unique( array_merge( $declared, [ 'pagination' ] ) ) );

		foreach ( [ 'filter' => $filter_ids, 'search' => $search_ids, 'sort' => $sort_ids, 'facets' => $facet_ids ] as $operation => $field_ids ) {
			if ( $field_ids ) {
				$required[] = $this->provider_capability( $provider_id, $operation );
			}
		}
		$required = array_values( array_unique( $required ) );
		sort( $required, SORT_STRING );

		return [
			'filter_field_ids' => $filter_ids,
			'sort_field_ids' => $sort_ids,
			'search_field_ids' => $search_ids,
			'facet_field_ids' => $facet_ids,
			'required_capabilities' => $required,
		];
	}

	public function facet_supported( $provider_id, array $field ) {
		return 'cct_indexed' !== $provider_id || 'multiple_choice' !== ( $field['type'] ?? '' );
	}

	private function selected_fields( array $fields, $selected, $capability ) {
		$available = $this->capability_fields( $fields, $capability );
		$selected = is_array( $selected ) ? array_values( array_filter( array_map( 'strval', $selected ) ) ) : [];
		return $selected ? array_values( array_intersect( $selected, $available ) ) : $available;
	}

	private function capability_fields( array $fields, $capability ) {
		$result = [];
		foreach ( $fields as $field ) {
			if ( ! empty( $field['capabilities'][ $capability ] ) && ! empty( $field['indexing'][ $capability ] ) ) {
				$result[] = $field['id'];
			}
		}
		return $result;
	}

	private function facet_fields( $provider_id, array $surface, array $filter_ids, array $fields ) {
		$configured = is_array( $surface['facet_fields'] ?? null ) ? $surface['facet_fields'] : [];
		$candidates = array_key_exists( 'facet_fields', $surface )
			? array_values( array_intersect( $configured, $filter_ids ) )
			: array_values(
				array_filter(
					$filter_ids,
					fn( $field_id ) => in_array( $fields[ $field_id ]['type'] ?? '', [ 'boolean', 'single_choice', 'multiple_choice', 'taxonomy', 'relation' ], true )
				)
			);
		$candidates = array_values( array_filter( $candidates, fn( $field_id ) => $this->facet_supported( $provider_id, $fields[ $field_id ] ) ) );
		return array_slice( $candidates, 0, 'cct_indexed' === $provider_id ? 10 : 3 );
	}

	private function provider_capability( $provider_id, $operation ) {
		if ( 'filter' === $operation ) {
			return 'woocommerce' === $provider_id ? 'catalog_filters' : 'field_id_filters';
		}
		if ( 'sort' === $operation ) {
			return [ 'cct_indexed' => 'indexed_sort', 'woocommerce' => 'catalog_sort' ][ $provider_id ] ?? 'typed_sort';
		}
		return $operation;
	}

	private function is_public_field( array $field ) {
		return ! empty( $field['exposure']['public'] );
	}
}
