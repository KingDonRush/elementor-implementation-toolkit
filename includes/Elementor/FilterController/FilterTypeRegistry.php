<?php
/**
 * Filter Controller type metadata registry.
 */

namespace EIT\Elementor\FilterController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterTypeRegistry {

	public static function definitions() {
		return [
			'search'   => self::definition(
				__( 'Search', 'elementor-implementation-toolkit' ),
				[
					'value_shape'    => 'text',
					'style_families' => [ 'field', 'search' ],
					'source_needed'  => false,
					'controls'       => [ 'placeholder', 'layout', 'label' ],
				]
			),
			'checkbox' => self::definition(
				__( 'Checkboxes', 'elementor-implementation-toolkit' ),
				[
					'value_shape'    => 'multi',
					'style_families' => [ 'option', 'checkbox' ],
					'controls'       => [ 'options', 'layout', 'label', 'field_binding' ],
				]
			),
			'radio'    => self::definition(
				__( 'Radio', 'elementor-implementation-toolkit' ),
				[
					'value_shape'    => 'single',
					'style_families' => [ 'option', 'radio' ],
					'controls'       => [ 'options', 'layout', 'label', 'field_binding' ],
				]
			),
			'select'   => self::definition(
				__( 'Select', 'elementor-implementation-toolkit' ),
				[
					'value_shape'    => 'single',
					'style_families' => [ 'field', 'select' ],
					'controls'       => [ 'options', 'placeholder', 'layout', 'label', 'field_binding' ],
				]
			),
			'chips'    => self::definition(
				__( 'Chips', 'elementor-implementation-toolkit' ),
				[
					'value_shape'    => 'multi',
					'style_families' => [ 'option', 'chips' ],
					'controls'       => [ 'options', 'layout', 'label', 'field_binding' ],
				]
			),
			'toggle'   => self::definition(
				__( 'Toggle', 'elementor-implementation-toolkit' ),
				[
					'value_shape'    => 'boolean',
					'style_families' => [ 'option', 'toggle' ],
					'controls'       => [ 'options', 'layout', 'label', 'field_binding' ],
				]
			),
			'range'    => self::definition(
				__( 'Range / Min Max', 'elementor-implementation-toolkit' ),
				[
					'value_shape'    => 'range',
					'style_families' => [ 'field', 'range' ],
					'controls'       => [ 'range', 'layout', 'label', 'field_binding' ],
				]
			),
			'date'     => self::definition(
				__( 'Date Range', 'elementor-implementation-toolkit' ),
				[
					'value_shape'    => 'date_range',
					'style_families' => [ 'field', 'date' ],
					'controls'       => [ 'date', 'layout', 'label', 'field_binding' ],
				]
			),
			'swatch'   => self::definition(
				__( 'Swatches', 'elementor-implementation-toolkit' ),
				[
					'value_shape'    => 'multi',
					'style_families' => [ 'option', 'swatch' ],
					'controls'       => [ 'options', 'layout', 'label', 'field_binding' ],
				]
			),
			'rating'   => self::definition(
				__( 'Rating', 'elementor-implementation-toolkit' ),
				[
					'value_shape'    => 'threshold',
					'style_families' => [ 'option', 'rating' ],
					'controls'       => [ 'options', 'layout', 'label', 'field_binding' ],
				]
			),
		];
	}

	public static function keys() {
		return array_keys( self::definitions() );
	}

	public static function labels() {
		$labels = [];

		foreach ( self::definitions() as $type => $definition ) {
			$labels[ $type ] = $definition['label'];
		}

		return $labels;
	}

	public static function normalize_type( $type ) {
		$type = sanitize_key( $type );

		return self::has( $type ) ? $type : 'search';
	}

	public static function has( $type ) {
		return isset( self::definitions()[ $type ] );
	}

	public static function get( $type ) {
		$type = self::normalize_type( $type );

		return self::definitions()[ $type ];
	}

	public static function has_style_family( $type, $family ) {
		$definition = self::get( $type );

		return in_array( sanitize_key( $family ), $definition['style_families'], true );
	}

	public static function state_flags_for_types( array $types ) {
		$types = array_map( [ __CLASS__, 'normalize_type' ], $types );

		return [
			'eit_filter_has_field_controls'    => self::types_have_style_family( $types, 'field' ) ? 'yes' : '',
			'eit_filter_has_option_controls'   => self::types_have_style_family( $types, 'option' ) ? 'yes' : '',
			'eit_filter_has_checkbox_controls' => in_array( 'checkbox', $types, true ) ? 'yes' : '',
			'eit_filter_has_chips_controls'    => in_array( 'chips', $types, true ) ? 'yes' : '',
			'eit_filter_has_radio_controls'    => in_array( 'radio', $types, true ) ? 'yes' : '',
			'eit_filter_has_search_controls'   => in_array( 'search', $types, true ) ? 'yes' : '',
			'eit_filter_has_select_controls'   => in_array( 'select', $types, true ) ? 'yes' : '',
			'eit_filter_has_range_controls'    => in_array( 'range', $types, true ) ? 'yes' : '',
			'eit_filter_has_rating_controls'   => in_array( 'rating', $types, true ) ? 'yes' : '',
		];
	}

	public static function editor_metadata() {
		$metadata = [];

		foreach ( self::definitions() as $type => $definition ) {
			$metadata[ $type ] = [
				'label'          => $definition['label'],
				'valueShape'     => $definition['value_shape'],
				'styleFamilies'  => $definition['style_families'],
				'sourceNeeded'   => $definition['source_needed'],
				'controls'       => $definition['controls'],
			];
		}

		return $metadata;
	}

	private static function definition( $label, array $overrides = [] ) {
		return array_merge(
			[
				'label'          => $label,
				'value_shape'    => 'single',
				'style_families' => [ 'option' ],
				'source_needed'  => true,
				'controls'       => [ 'layout', 'label', 'field_binding' ],
			],
			$overrides
		);
	}

	private static function types_have_style_family( array $types, $family ) {
		foreach ( $types as $type ) {
			if ( self::has_style_family( $type, $family ) ) {
				return true;
			}
		}

		return false;
	}
}
