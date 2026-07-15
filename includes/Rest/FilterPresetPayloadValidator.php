<?php
/**
 * Validates the nested contract used by filter preset REST writes.
 */

namespace EIT\Rest;

use EIT\Support\FilterPresets;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetPayloadValidator {

	private $preset_keys = [
		'name', 'slug', 'description', 'target_selector', 'item_selector', 'apply_mode',
		'search_debounce_ms', 'sync_url', 'per_page', 'show_result_count',
		'result_count_text', 'show_active_chips', 'show_sort', 'sort_label',
		'sort_options', 'apply_text', 'reset_text', 'empty_text', 'pagination_type',
		'previous_text', 'next_text', 'filters',
	];

	private $filter_keys = [
		'enabled', 'label', 'type', 'field_binding', 'field_binding_dynamic', 'key',
		'resolved_key', 'key_source', 'source', 'query_var', 'compare', 'data_type',
		'placeholder', 'options', 'radio_show_all', 'radio_all_label', 'range_min',
		'range_max', 'range_step', 'layout_width', 'default_value', 'empty_behavior',
		'show_count', 'show_label',
	];

	private $source_widget_keys = [ 'element_id', 'document_id' ];

	public function validate_preset( $preset ) {
		if ( ! is_array( $preset ) ) {
			return $this->field_error( 'preset', __( 'Preset must be an object.', 'elementor-implementation-toolkit' ) );
		}

		$unknown = $this->unknown_keys( $preset, $this->preset_keys );
		if ( ! empty( $unknown ) ) {
			return $this->field_error( 'preset', __( 'Unknown preset fields.', 'elementor-implementation-toolkit' ), $unknown );
		}

		if ( empty( trim( (string) ( $preset['name'] ?? '' ) ) ) ) {
			return $this->field_error( 'preset.name', __( 'Preset name is required.', 'elementor-implementation-toolkit' ) );
		}

		if ( isset( $preset['filters'] ) ) {
			$filters = $this->validate_filters( $preset['filters'] );
			if ( is_wp_error( $filters ) ) {
				return $filters;
			}
			$preset['filters'] = $filters;
		}

		return $preset;
	}

	public function validate_source_widget( $source_widget ) {
		if ( ! is_array( $source_widget ) ) {
			return $this->field_error( 'source_widget', __( 'Source widget must be an object.', 'elementor-implementation-toolkit' ) );
		}

		$unknown = $this->unknown_keys( $source_widget, $this->source_widget_keys );
		if ( ! empty( $unknown ) ) {
			return $this->field_error( 'source_widget', __( 'Unknown source widget fields.', 'elementor-implementation-toolkit' ), $unknown );
		}

		return [
			'source'      => 'elementor_widget',
			'saved_via'   => 'elementor_editor',
			'document_id' => absint( $source_widget['document_id'] ?? 0 ),
			'element_id'  => sanitize_text_field( $source_widget['element_id'] ?? '' ),
		];
	}

	private function validate_filters( $filters ) {
		if ( ! is_array( $filters ) ) {
			return $this->field_error( 'preset.filters', __( 'Filters must be an array.', 'elementor-implementation-toolkit' ) );
		}
		if ( count( $filters ) > FilterPresets::MAX_FILTERS ) {
			return $this->field_error(
				'preset.filters',
				sprintf( __( 'A preset can contain at most %d filters.', 'elementor-implementation-toolkit' ), FilterPresets::MAX_FILTERS )
			);
		}

		$types = array_keys( FilterPresets::filter_types() );
		$normalized = [];
		foreach ( array_values( $filters ) as $index => $filter ) {
			$field = 'preset.filters.' . $index;
			if ( ! is_array( $filter ) ) {
				return $this->field_error( $field, __( 'Filter row must be an object.', 'elementor-implementation-toolkit' ) );
			}

			$unknown = $this->unknown_keys( $filter, $this->filter_keys );
			if ( ! empty( $unknown ) ) {
				return $this->field_error( $field, __( 'Unknown filter fields.', 'elementor-implementation-toolkit' ), $unknown );
			}

			$type = sanitize_key( $filter['type'] ?? 'search' );
			if ( ! in_array( $type, $types, true ) ) {
				return $this->field_error( $field . '.type', __( 'Unknown filter type.', 'elementor-implementation-toolkit' ) );
			}

			if ( 'range' === $type ) {
				$range_error = $this->validate_range( $filter, $field );
				if ( is_wp_error( $range_error ) ) {
					return $range_error;
				}
			}
			$normalized[] = $filter;
		}

		return $normalized;
	}

	private function validate_range( array $filter, $field ) {
		foreach ( [ 'range_min', 'range_max', 'range_step' ] as $range_field ) {
			$value = $filter[ $range_field ] ?? '';
			if ( '' !== (string) $value && ! is_numeric( $value ) ) {
				return $this->field_error( $field . '.' . $range_field, __( 'Range value must be numeric.', 'elementor-implementation-toolkit' ) );
			}
		}

		return true;
	}

	private function unknown_keys( array $value, array $allowed ) {
		return array_values( array_diff( array_keys( $value ), $allowed ) );
	}

	private function field_error( $field, $message, array $details = [] ) {
		return new WP_Error(
			'eit_filter_preset_invalid_payload',
			__( 'Preset payload is invalid.', 'elementor-implementation-toolkit' ),
			[
				'status' => 400,
				'fields' => [
					$field => [
						'message' => $message,
						'details' => $details,
					],
				],
			]
		);
	}
}
