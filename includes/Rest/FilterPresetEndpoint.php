<?php
/**
 * Protected REST endpoint for saving filter presets from Elementor.
 */

namespace EIT\Rest;

use EIT\Admin\AdminPages;
use EIT\Support\FilterPresets;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetEndpoint {

	const CREATE_ROUTE = '/filter-presets';
	const UPDATE_ROUTE = '/filter-presets/(?P<id>[a-z0-9_-]+)';

	private $request_keys = [
		'operation',
		'after_save',
		'preset',
		'preset_id',
		'confirm_overwrite',
		'source_widget',
	];

	private $preset_keys = [
		'name',
		'slug',
		'description',
		'target_selector',
		'item_selector',
		'apply_mode',
		'sync_url',
		'per_page',
		'show_result_count',
		'result_count_text',
		'show_active_chips',
		'show_sort',
		'sort_label',
		'sort_options',
		'apply_text',
		'reset_text',
		'empty_text',
		'pagination_type',
		'previous_text',
		'next_text',
		'filters',
	];

	private $filter_keys = [
		'enabled',
		'label',
		'type',
		'key',
		'source',
		'query_var',
		'compare',
		'data_type',
		'placeholder',
		'options',
		'range_min',
		'range_max',
		'range_step',
		'default_value',
		'empty_behavior',
		'show_count',
		'show_label',
	];

	private $source_widget_keys = [
		'element_id',
		'document_id',
	];

	public function init_hooks() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route(
			'eit/v1',
			self::CREATE_ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			'eit/v1',
			self::UPDATE_ROUTE,
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
	}

	public function can_manage() {
		if ( current_user_can( AdminPages::CAPABILITY ) ) {
			return true;
		}

		return $this->error(
			'eit_filter_preset_forbidden',
			__( 'You do not have permission to manage filter presets.', 'elementor-implementation-toolkit' ),
			403
		);
	}

	public function create( WP_REST_Request $request ) {
		return $this->save( $request, 'create' );
	}

	public function update( WP_REST_Request $request ) {
		return $this->save( $request, 'update', sanitize_key( $request['id'] ?? '' ) );
	}

	private function save( WP_REST_Request $request, $default_operation, $route_id = '' ) {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return $this->error(
				'eit_filter_preset_invalid_payload',
				__( 'Preset payload is invalid.', 'elementor-implementation-toolkit' ),
				400
			);
		}

		$unknown = $this->unknown_keys( $payload, $this->request_keys );

		if ( ! empty( $unknown ) ) {
			return $this->field_error( 'request', __( 'Unknown request fields.', 'elementor-implementation-toolkit' ), $unknown );
		}

		$operation = sanitize_key( $payload['operation'] ?? $default_operation );

		if ( ! in_array( $operation, [ 'create', 'update' ], true ) ) {
			return $this->field_error( 'operation', __( 'Unsupported save operation.', 'elementor-implementation-toolkit' ) );
		}

		$after_save = sanitize_key( $payload['after_save'] ?? 'link' );

		if ( ! in_array( $after_save, [ 'link', 'detach', 'none' ], true ) ) {
			return $this->field_error( 'after_save', __( 'Unsupported after-save behavior.', 'elementor-implementation-toolkit' ) );
		}

		$preset = $this->validate_preset( $payload['preset'] ?? null );

		if ( is_wp_error( $preset ) ) {
			return $preset;
		}

		$source_widget = $this->validate_source_widget( $payload['source_widget'] ?? [] );

		if ( is_wp_error( $source_widget ) ) {
			return $source_widget;
		}

		if ( 'update' === $operation ) {
			$preset_id = sanitize_key( $route_id ?: ( $payload['preset_id'] ?? '' ) );

			if ( '' === $preset_id || ! FilterPresets::get( $preset_id ) ) {
				return $this->error(
					'eit_filter_preset_not_found',
					__( 'Filter preset not found.', 'elementor-implementation-toolkit' ),
					404
				);
			}

			if ( empty( $payload['confirm_overwrite'] ) ) {
				return $this->error(
					'eit_filter_preset_overwrite_required',
					__( 'Confirm overwrite before updating a shared preset.', 'elementor-implementation-toolkit' ),
					409
				);
			}

			$preset['id'] = $preset_id;
		} else {
			unset( $preset['id'] );
			$preset['created_from'] = $source_widget;
		}

		$id = FilterPresets::save( $preset );
		$saved = FilterPresets::get( $id );

		if ( ! $saved ) {
			return $this->error(
				'eit_filter_preset_save_failed',
				__( 'Could not save filter preset.', 'elementor-implementation-toolkit' ),
				500
			);
		}

		$response = [
			'ok'            => true,
			'preset'        => [
				'id'           => $id,
				'name'         => $saved['name'] ?? $id,
				'slug'         => $saved['slug'] ?? $id,
				'updated_at'   => $saved['updated_at'] ?? '',
				'filter_count' => count( $saved['filters'] ?? [] ),
				'edit_url'     => admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&preset=' . rawurlencode( $id ) ),
			],
			'editor_update' => 'link' === $after_save ? [
				'configuration_source' => 'preset',
				'filter_preset'        => $id,
			] : [],
			'warnings'      => $this->warnings_for_preset( $saved ),
		];

		return new WP_REST_Response( $response, 'create' === $operation ? 201 : 200 );
	}

	private function validate_preset( $preset ) {
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

	private function validate_source_widget( $source_widget ) {
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

		$filters = array_slice( array_values( $filters ), 0, FilterPresets::MAX_FILTERS );
		$types = array_keys( FilterPresets::filter_types() );
		$normalized = [];

		foreach ( $filters as $index => $filter ) {
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
				foreach ( [ 'range_min', 'range_max', 'range_step' ] as $range_field ) {
					if ( isset( $filter[ $range_field ] ) && '' !== (string) $filter[ $range_field ] && ! is_numeric( $filter[ $range_field ] ) ) {
						return $this->field_error( $field . '.' . $range_field, __( 'Range value must be numeric.', 'elementor-implementation-toolkit' ) );
					}
				}
			}

			$normalized[] = $filter;
		}

		return $normalized;
	}

	private function warnings_for_preset( array $preset ) {
		$warnings = [];

		foreach ( $preset['filters'] ?? [] as $index => $filter ) {
			if ( in_array( $filter['type'] ?? '', [ 'checkbox', 'radio', 'select', 'chips', 'toggle', 'swatch', 'rating' ], true ) && empty( trim( (string) ( $filter['options'] ?? '' ) ) ) ) {
				$warnings[] = [
					'field'   => 'preset.filters.' . $index . '.options',
					'message' => __( 'This option-based filter has no options yet.', 'elementor-implementation-toolkit' ),
				];
			}
		}

		return $warnings;
	}

	private function unknown_keys( array $value, array $allowed ) {
		return array_values( array_diff( array_keys( $value ), $allowed ) );
	}

	private function field_error( $field, $message, array $details = [] ) {
		return $this->error(
			'eit_filter_preset_invalid_payload',
			__( 'Preset payload is invalid.', 'elementor-implementation-toolkit' ),
			400,
			[
				'fields' => [
					$field => [
						'message' => $message,
						'details' => $details,
					],
				],
			]
		);
	}

	private function error( $code, $message, $status, array $extra = [] ) {
		return new WP_Error(
			$code,
			$message,
			array_merge(
				[
					'status' => $status,
				],
				$extra
			)
		);
	}
}
