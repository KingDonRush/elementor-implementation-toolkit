<?php
/**
 * Deterministic health rules for legacy filter presets.
 */

namespace EIT\Admin;

use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetDiagnosticRules {

	public function analyze( array $preset ) {
		$diagnostics = [];
		$filters = is_array( $preset['filters'] ?? null ) ? $preset['filters'] : [];
		$unknown_keys = array_diff( array_keys( $preset ), $this->expected_preset_keys() );

		if ( ! empty( $unknown_keys ) ) {
			$this->add(
				$diagnostics,
				'error',
				__( 'Unsupported preset fields', 'elementor-implementation-toolkit' ),
				sprintf( __( 'Unknown fields are saved in this preset: %s.', 'elementor-implementation-toolkit' ), implode( ', ', $unknown_keys ) )
			);
		}

		if ( empty( trim( (string) ( $preset['target_selector'] ?? '' ) ) ) ) {
			$this->add(
				$diagnostics,
				'warning',
				__( 'Missing target selector', 'elementor-implementation-toolkit' ),
				__( 'The preset can still be linked from a widget, but the admin library cannot identify a default listing target.', 'elementor-implementation-toolkit' )
			);
		} else {
			$this->add(
				$diagnostics,
				'info',
				__( 'Selector not verified', 'elementor-implementation-toolkit' ),
				__( 'Selectors are stored as text. Confirm the actual DOM in Elementor or on the frontend page.', 'elementor-implementation-toolkit' )
			);
		}

		if ( empty( $filters ) ) {
			$this->add(
				$diagnostics,
				'warning',
				__( 'No filters configured', 'elementor-implementation-toolkit' ),
				__( 'This preset is a draft until at least one filter is saved.', 'elementor-implementation-toolkit' )
			);
		}

		foreach ( $filters as $index => $filter ) {
			if ( ! is_array( $filter ) ) {
				$this->add(
					$diagnostics,
					'error',
					__( 'Invalid filter row', 'elementor-implementation-toolkit' ),
					sprintf( __( 'Filter row %d is not a valid object.', 'elementor-implementation-toolkit' ), $index + 1 )
				);
				continue;
			}
			$this->diagnose_filter( $diagnostics, $filter, $index );
		}

		return $diagnostics;
	}

	private function diagnose_filter( array &$diagnostics, array $filter, $index ) {
		$row = $index + 1;
		$type = sanitize_key( $filter['type'] ?? 'search' );
		$unknown_keys = array_diff( array_keys( $filter ), $this->expected_filter_keys() );

		if ( ! empty( $unknown_keys ) ) {
			$this->add(
				$diagnostics,
				'error',
				__( 'Unsupported filter fields', 'elementor-implementation-toolkit' ),
				sprintf( __( 'Filter %1$d contains unknown fields: %2$s.', 'elementor-implementation-toolkit' ), $row, implode( ', ', $unknown_keys ) )
			);
		}

		$this->validate_enum(
			$diagnostics,
			$type,
			array_keys( FilterPresets::filter_types() ),
			__( 'Unknown filter type', 'elementor-implementation-toolkit' ),
			sprintf( __( 'Filter %1$d uses unsupported type "%2$s".', 'elementor-implementation-toolkit' ), $row, $type )
		);
		$this->validate_enum(
			$diagnostics,
			sanitize_key( $filter['source'] ?? 'visible_text' ),
			array_keys( FilterPresets::source_types() ),
			__( 'Unknown data source', 'elementor-implementation-toolkit' ),
			sprintf( __( 'Filter %d uses a source that the runtime does not recognize.', 'elementor-implementation-toolkit' ), $row )
		);
		$this->validate_enum(
			$diagnostics,
			sanitize_key( $filter['compare'] ?? 'contains' ),
			array_keys( FilterPresets::compare_types() ),
			__( 'Unknown compare operator', 'elementor-implementation-toolkit' ),
			sprintf( __( 'Filter %d uses a compare operator that the runtime does not recognize.', 'elementor-implementation-toolkit' ), $row )
		);
		$this->validate_enum(
			$diagnostics,
			sanitize_key( $filter['data_type'] ?? 'string' ),
			array_keys( FilterPresets::data_types() ),
			__( 'Unknown data type', 'elementor-implementation-toolkit' ),
			sprintf( __( 'Filter %d uses a data type that the runtime does not recognize.', 'elementor-implementation-toolkit' ), $row )
		);

		if ( in_array( $type, FilterPresetInspector::option_based_filter_types(), true ) && 0 === FilterPresetInspector::option_count( $filter['options'] ?? '' ) ) {
			$this->add(
				$diagnostics,
				'warning',
				__( 'Empty filter options', 'elementor-implementation-toolkit' ),
				sprintf( __( 'Filter %d needs options before users can choose anything.', 'elementor-implementation-toolkit' ), $row )
			);
		}

		if ( 'search' !== $type && ! $this->has_field_binding( $filter ) ) {
			$this->add(
				$diagnostics,
				'warning',
				__( 'Missing data key', 'elementor-implementation-toolkit' ),
				sprintf( __( 'Filter %d has no field, taxonomy, or data key.', 'elementor-implementation-toolkit' ), $row )
			);
		}

		if ( 'range' === $type ) {
			$this->diagnose_range( $diagnostics, $filter, $row );
		}

		if ( empty( $filter['enabled'] ) ) {
			$this->add(
				$diagnostics,
				'info',
				__( 'Disabled filter', 'elementor-implementation-toolkit' ),
				sprintf( __( 'Filter %d is saved but disabled.', 'elementor-implementation-toolkit' ), $row )
			);
		}
	}

	private function validate_enum( array &$diagnostics, $value, array $allowed, $title, $message ) {
		if ( ! in_array( $value, $allowed, true ) ) {
			$this->add( $diagnostics, 'error', $title, $message );
		}
	}

	private function diagnose_range( array &$diagnostics, array $filter, $row ) {
		$min = $filter['range_min'] ?? 0;
		$max = $filter['range_max'] ?? 100;
		$step = $filter['range_step'] ?? 1;

		if ( is_numeric( $min ) && is_numeric( $max ) && (float) $min > (float) $max ) {
			$this->add(
				$diagnostics,
				'error',
				__( 'Invalid range bounds', 'elementor-implementation-toolkit' ),
				sprintf( __( 'Filter %d has a minimum value greater than the maximum value.', 'elementor-implementation-toolkit' ), $row )
			);
		}

		if ( is_numeric( $step ) && (float) $step <= 0 ) {
			$this->add(
				$diagnostics,
				'warning',
				__( 'Invalid range step', 'elementor-implementation-toolkit' ),
				sprintf( __( 'Filter %d should use a positive range step.', 'elementor-implementation-toolkit' ), $row )
			);
		}
	}

	private function has_field_binding( array $filter ) {
		foreach ( [ 'field_binding', 'field_binding_dynamic', 'key', 'resolved_key' ] as $field ) {
			if ( '' !== trim( (string) ( $filter[ $field ] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function add( array &$diagnostics, $severity, $title, $message ) {
		$diagnostics[] = compact( 'severity', 'title', 'message' );
	}

	private function expected_preset_keys() {
		return [
			'id', 'name', 'slug', 'description', 'provider_mode', 'target_selector', 'item_selector',
			'apply_mode', 'search_debounce_ms', 'sync_url', 'per_page', 'show_result_count',
			'result_count_text', 'show_active_chips', 'show_sort', 'sort_label', 'sort_options',
			'apply_text', 'reset_text', 'empty_text', 'pagination_type', 'previous_text', 'next_text',
			'filters', 'created_from', 'created_at', 'updated_at',
		];
	}

	private function expected_filter_keys() {
		return [
			'enabled', 'label', 'type', 'field_binding', 'field_binding_dynamic', 'key', 'resolved_key',
			'key_source', 'source', 'query_var', 'compare', 'data_type', 'placeholder', 'options',
			'radio_show_all', 'radio_all_label', 'range_min', 'range_max', 'range_step', 'layout_width',
			'default_value', 'empty_behavior', 'show_count', 'show_label',
		];
	}
}
