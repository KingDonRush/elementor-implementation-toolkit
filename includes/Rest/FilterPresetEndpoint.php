<?php
/**
 * Protected REST endpoint for saving filter presets from Elementor.
 */

namespace EIT\Rest;

use EIT\Admin\AdminPages;
use EIT\Elementor\FilterController\FilterSettings;
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
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get' ],
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

	public function get( WP_REST_Request $request ) {
		$id = sanitize_key( $request['id'] ?? '' );
		$preset = '' !== $id ? FilterPresets::get( $id ) : null;

		if ( ! $preset ) {
			return $this->error(
				'eit_filter_preset_not_found',
				__( 'Filter preset not found.', 'elementor-implementation-toolkit' ),
				404
			);
		}

		return new WP_REST_Response( $this->response_payload( $id, $preset, 'none' ), 200 );
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

		$validator = new FilterPresetPayloadValidator();
		$preset = $validator->validate_preset( $payload['preset'] ?? null );

		if ( is_wp_error( $preset ) ) {
			return $preset;
		}

		$source_widget = $validator->validate_source_widget( $payload['source_widget'] ?? [] );

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
		if ( is_wp_error( $id ) ) {
			return $this->error( $id->get_error_code(), $id->get_error_message(), 400 );
		}
		$saved = FilterPresets::get( $id );

		if ( ! $saved ) {
			return $this->error(
				'eit_filter_preset_save_failed',
				__( 'Could not save filter preset.', 'elementor-implementation-toolkit' ),
				500
			);
		}

		return new WP_REST_Response( $this->response_payload( $id, $saved, $after_save ), 'create' === $operation ? 201 : 200 );
	}

	private function response_payload( $id, array $saved, $after_save ) {
		return [
			'ok'              => true,
			'preset'          => [
				'id'           => $id,
				'name'         => $saved['name'] ?? $id,
				'slug'         => $saved['slug'] ?? $id,
				'updated_at'   => $saved['updated_at'] ?? '',
				'filter_count' => count( $saved['filters'] ?? [] ),
				'edit_url'     => admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&preset=' . rawurlencode( $id ) ),
			],
			'editor_update'   => 'link' === $after_save ? [
				'configuration_source' => 'preset',
				'filter_preset'        => $id,
			] : [],
			'widget_settings' => FilterSettings::preset_to_widget_settings( $saved ),
			'warnings'        => $this->warnings_for_preset( $saved ),
		];
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
