<?php
/**
 * Maps a published Collection contract to the legacy widget's render vocabulary.
 */

namespace EIT\Elementor\FilterController;

use EIT\Collection\CollectionFieldSemantics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionWidgetContract {

	private $semantics;

	public function __construct( CollectionFieldSemantics $semantics = null ) {
		$this->semantics = $semantics ?: new CollectionFieldSemantics();
	}

	public function map( array $contract ) {
		$surface = is_array( $contract['filter_surface'] ?? null ) ? $contract['filter_surface'] : [];
		$fields = array_column( $contract['fields'] ?? [], null, 'id' );
		$filters = [];
		foreach ( $surface['controls'] ?? [] as $index => $control ) {
			$field = $fields[ $control['field_id'] ?? '' ] ?? null;
			if ( $field ) {
				$filters[] = $this->filter( $control, $field, $index );
			}
		}

		return [
			'filters' => $filters,
			'sort_options' => $this->sort_options( $surface['sort_options'] ?? [] ),
			'settings' => [
				'data_provider' => 'collection',
				'configuration_source' => 'collection',
				'preset_resolution_state' => 'collection',
				'per_page' => min( 48, max( 1, absint( $contract['page_size'] ?? 24 ) ) ),
				'sync_url' => ! empty( $surface['url_state'] ) ? 'yes' : '',
				'show_active_chips' => ! empty( $surface['active_chips'] ) ? 'yes' : '',
				'show_sort' => ! empty( $surface['sort_options'] ) ? 'yes' : '',
				'auto_apply' => 'submit' === ( $surface['apply_mode'] ?? '' ) ? '' : 'yes',
					'show_apply' => 'submit' === ( $surface['apply_mode'] ?? '' ) ? 'yes' : '',
					'collection_explain' => ! empty( $contract['explain_available'] ) ? 'yes' : '',
				],
			'facet_field_ids' => array_values( array_map( 'strval', $surface['facet_field_ids'] ?? [] ) ),
		];
	}

	private function filter( array $control, array $field, $index ) {
		$type = $this->type( $control, $field );
		$validation = is_array( $field['validation'] ?? null ) ? $field['validation'] : [];
		$range_bounded = is_numeric( $validation['min'] ?? null ) && is_numeric( $validation['max'] ?? null );
		$minimum = $range_bounded ? (float) $validation['min'] : 0;
		$maximum = $range_bounded ? (float) $validation['max'] : 100;
		if ( $maximum <= $minimum ) {
			$maximum = $minimum + 100;
		}
		$field_id = (string) $field['id'];

		return [
			'id' => sanitize_key( $field_id ) ?: 'collection-filter-' . absint( $index ),
			'label' => sanitize_text_field( $control['label'] ?? $field['name'] ?? __( 'Filter', 'elementor-implementation-toolkit' ) ),
			'type' => $type,
			'key' => $field_id,
			'manualKey' => '',
			'fieldBinding' => $field_id,
			'fieldBindingDynamic' => '',
			'resolvedKey' => $field_id,
			'keySource' => 'field_contract',
			'source' => 'collection',
			'compare' => $this->operator( $type, $control['operators'] ?? [] ),
			'dataType' => $this->semantics->value_type( $field ),
			'placeholder' => $this->placeholder( $type, $field ),
			'options' => $this->options( $control, $field ),
			'radioShowAll' => false,
			'radioAllLabel' => __( 'All', 'elementor-implementation-toolkit' ),
			'rangeMin' => $minimum,
			'rangeMax' => $maximum,
			'rangeStep' => is_numeric( $validation['step'] ?? null ) ? (float) $validation['step'] : 1,
			'rangeBounded' => $range_bounded,
			'layoutWidth' => 100,
			'showLabel' => true,
		];
	}

	private function type( array $control, array $field ) {
		$type = sanitize_key( $control['control'] ?? 'search' );
		if ( 'options' !== $type ) {
			return in_array( $type, [ 'search', 'range', 'date', 'toggle' ], true ) ? $type : 'search';
		}
		return 'single_choice' === ( $field['type'] ?? '' ) ? 'select' : 'checkbox';
	}

	private function operator( $type, array $operators ) {
		$preferred = in_array( $type, [ 'range', 'date' ], true ) ? 'between' : ( 'checkbox' === $type ? 'in' : 'equals' );
		return in_array( $preferred, $operators, true ) ? $preferred : sanitize_key( $operators[0] ?? 'equals' );
	}

	private function placeholder( $type, array $field ) {
		$name = sanitize_text_field( $field['name'] ?? __( 'items', 'elementor-implementation-toolkit' ) );
		return 'search' === $type
			? sprintf( __( 'Search %s', 'elementor-implementation-toolkit' ), $name )
			: sprintf( __( 'All %s', 'elementor-implementation-toolkit' ), $name );
	}

	private function options( array $control, array $field ) {
		$options = $control['options'] ?? [];
		if ( 'boolean' === ( $field['type'] ?? '' ) && ! $options ) {
			$options = [ [ 'value' => '1', 'label' => __( 'Yes', 'elementor-implementation-toolkit' ) ] ];
		}
		return array_values(
			array_map(
				function ( $option ) {
					return [
						'value' => sanitize_text_field( $option['value'] ?? '' ),
						'label' => sanitize_text_field( $option['label'] ?? $option['value'] ?? '' ),
						'visual' => '',
						'count' => null,
					];
				},
				is_array( $options ) ? $options : []
			)
		);
	}

	private function sort_options( array $options ) {
		$result = [ [ 'value' => 'default', 'label' => __( 'Default order', 'elementor-implementation-toolkit' ) ] ];
		foreach ( $options as $option ) {
			foreach ( $option['directions'] ?? [ 'asc', 'desc' ] as $direction ) {
				$direction = 'desc' === $direction ? 'desc' : 'asc';
				$result[] = [
					'value' => (string) ( $option['field_id'] ?? '' ) . ':' . $direction,
					'label' => sprintf(
						'%1$s — %2$s',
						sanitize_text_field( $option['label'] ?? '' ),
						'desc' === $direction ? __( 'descending', 'elementor-implementation-toolkit' ) : __( 'ascending', 'elementor-implementation-toolkit' )
					),
				];
			}
		}
		return $result;
	}
}
