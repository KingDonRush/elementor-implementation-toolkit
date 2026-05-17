<?php
/**
 * Stored admin contracts for Toolkit integration modules.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IntegrationPatterns {

	const OPTION = 'eit_integration_patterns';
	const MAX_FIELDS = 32;

	public static function all() {
		$items = [];

		foreach ( self::definitions() as $id => $definition ) {
			$items[ $id ] = self::get( $id );
		}

		return $items;
	}

	public static function get( $id ) {
		$id          = sanitize_key( $id );
		$definition  = self::definitions()[ $id ] ?? null;
		$saved_items = self::saved();
		$saved       = $saved_items[ $id ] ?? [];

		if ( ! $definition ) {
			return null;
		}

		$values = self::defaults_for( $definition );
		if ( ! empty( $saved['values'] ) && is_array( $saved['values'] ) ) {
			$values = array_merge( $values, self::sanitize_values( $saved['values'], $definition ) );
		}

		$status = self::allowed_value( $saved['status'] ?? $definition['status'], array_keys( self::statuses() ), $definition['status'] );

		return array_merge(
			$definition,
			[
				'status'     => $status,
				'values'     => $values,
				'updated_at' => sanitize_text_field( $saved['updated_at'] ?? '' ),
			]
		);
	}

	public static function save( array $raw ) {
		$id = sanitize_key( $raw['id'] ?? '' );

		if ( ! isset( self::definitions()[ $id ] ) ) {
			$id = 'simple_budget_bridge';
		}

		$definition = self::definitions()[ $id ];
		$saved      = self::saved();

		$saved[ $id ] = [
			'status'     => self::allowed_value( $raw['status'] ?? $definition['status'], array_keys( self::statuses() ), $definition['status'] ),
			'values'     => self::sanitize_values( $raw['values'] ?? [], $definition ),
			'updated_at' => current_time( 'mysql' ),
		];

		update_option( self::OPTION, $saved, false );

		return $id;
	}

	public static function statuses() {
		return [
			'active'   => __( 'Active', 'elementor-implementation-toolkit' ),
			'draft'    => __( 'Draft', 'elementor-implementation-toolkit' ),
			'degraded' => __( 'Degraded', 'elementor-implementation-toolkit' ),
		];
	}

	public static function definitions() {
		return [
			'simple_budget_bridge'     => self::definition(
				'simple_budget_bridge',
				__( 'Simple Budget Bridge', 'elementor-implementation-toolkit' ),
				__( 'Admin contract for keeping Simple Budget buttons stable inside filtered listings.', 'elementor-implementation-toolkit' ),
				'simple-budget-bridge',
				'active',
				[
					self::field( 'budget_action_selector', __( 'Action selector', 'elementor-implementation-toolkit' ), 'text', '.sbp-budget-action', 'scope', '.sbp-budget-action' ),
					self::field( 'cart_trigger_selector', __( 'Cart trigger selector', 'elementor-implementation-toolkit' ), 'text', '[data-sbp-open-cart]', 'contract', '[data-sbp-open-cart]' ),
					self::field( 'after_filter_refresh', __( 'After filter refresh', 'elementor-implementation-toolkit' ), 'select', 'preserve_handlers', 'binding', '', [ 'preserve_handlers' => __( 'Preserve existing handlers', 'elementor-implementation-toolkit' ), 'dispatch_event' => __( 'Dispatch bridge event', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'budget_event_name', __( 'Bridge event', 'elementor-implementation-toolkit' ), 'text', 'eit:listing-refreshed', 'output', 'eit:listing-refreshed' ),
					self::field( 'enabled_in_preview', __( 'Preview contract', 'elementor-implementation-toolkit' ), 'toggle', '1', 'runtime' ),
				]
			),
			'woocommerce_card_adapter' => self::definition(
				'woocommerce_card_adapter',
				__( 'WooCommerce Card Adapter', 'elementor-implementation-toolkit' ),
				__( 'Maps existing product-card markup to product IDs, prices, stock, and button states.', 'elementor-implementation-toolkit' ),
				'woo-adapter',
				'draft',
				[
					self::field( 'card_selector', __( 'Card selector', 'elementor-implementation-toolkit' ), 'text', '.product', 'scope', '.product' ),
					self::field( 'product_id_source', __( 'Product ID source', 'elementor-implementation-toolkit' ), 'select', 'data_attr', 'contract', '', [ 'data_attr' => __( 'data-product-id', 'elementor-implementation-toolkit' ), 'class_name' => __( 'post-* class', 'elementor-implementation-toolkit' ), 'permalink' => __( 'Permalink lookup', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'price_selector', __( 'Price selector', 'elementor-implementation-toolkit' ), 'text', '.price', 'binding', '.price' ),
					self::field( 'stock_selector', __( 'Stock selector', 'elementor-implementation-toolkit' ), 'text', '.stock', 'binding', '.stock' ),
					self::field( 'adapter_mode', __( 'Adapter mode', 'elementor-implementation-toolkit' ), 'select', 'read_only', 'runtime', '', [ 'read_only' => __( 'Read-only contract', 'elementor-implementation-toolkit' ), 'event_ready' => __( 'Event-ready placeholder', 'elementor-implementation-toolkit' ) ] ),
				]
			),
			'mobile_filter_panel'      => self::definition(
				'mobile_filter_panel',
				__( 'Mobile Filter Panel', 'elementor-implementation-toolkit' ),
				__( 'Defines off-canvas behavior for filter controllers without moving frontend runtime into V0.2.', 'elementor-implementation-toolkit' ),
				'mobile-panel',
				'draft',
				[
					self::field( 'breakpoint', __( 'Breakpoint', 'elementor-implementation-toolkit' ), 'number', '782', 'scope', '782' ),
					self::field( 'panel_side', __( 'Panel side', 'elementor-implementation-toolkit' ), 'select', 'left', 'contract', '', [ 'left' => __( 'Left', 'elementor-implementation-toolkit' ), 'right' => __( 'Right', 'elementor-implementation-toolkit' ), 'bottom' => __( 'Bottom sheet', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'overlay', __( 'Overlay', 'elementor-implementation-toolkit' ), 'toggle', '1', 'binding' ),
					self::field( 'apply_closes_panel', __( 'Apply closes panel', 'elementor-implementation-toolkit' ), 'toggle', '1', 'output' ),
					self::field( 'body_lock', __( 'Body scroll lock', 'elementor-implementation-toolkit' ), 'toggle', '1', 'runtime' ),
				]
			),
			'listing_target_detector'  => self::definition(
				'listing_target_detector',
				__( 'Listing Target Detector', 'elementor-implementation-toolkit' ),
				__( 'Controls how editor/frontend detection ranks existing listing widgets and fallback selectors.', 'elementor-implementation-toolkit' ),
				'listing-detector',
				'active',
				[
					self::field( 'scan_scope', __( 'Scan scope', 'elementor-implementation-toolkit' ), 'select', 'page_canvas', 'scope', '', [ 'page_canvas' => __( 'Elementor canvas', 'elementor-implementation-toolkit' ), 'document' => __( 'Full document', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'min_items', __( 'Minimum repeated items', 'elementor-implementation-toolkit' ), 'number', '3', 'contract', '3' ),
					self::field( 'preferred_patterns', __( 'Preferred patterns', 'elementor-implementation-toolkit' ), 'text', 'article,.product,.elementor-post,.jet-listing-grid__item', 'binding', 'article,.product' ),
					self::field( 'highlight_color', __( 'Highlight color', 'elementor-implementation-toolkit' ), 'color', '#ff4fa3', 'output', '#ff4fa3' ),
					self::field( 'manual_selector_fallback', __( 'Manual fallback', 'elementor-implementation-toolkit' ), 'toggle', '1', 'runtime' ),
				]
			),
			'url_state_router'         => self::definition(
				'url_state_router',
				__( 'URL State Router', 'elementor-implementation-toolkit' ),
				__( 'Defines query-string behavior for shareable filter states and resets.', 'elementor-implementation-toolkit' ),
				'url-router',
				'active',
				[
					self::field( 'param_prefix', __( 'Param prefix', 'elementor-implementation-toolkit' ), 'text', 'eit_', 'scope', 'eit_' ),
					self::field( 'history_mode', __( 'History mode', 'elementor-implementation-toolkit' ), 'select', 'replace', 'contract', '', [ 'replace' => __( 'Replace state', 'elementor-implementation-toolkit' ), 'push' => __( 'Push state', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'preserve_unknown_params', __( 'Preserve unknown params', 'elementor-implementation-toolkit' ), 'toggle', '1', 'binding' ),
					self::field( 'reset_removes_empty', __( 'Reset removes empty', 'elementor-implementation-toolkit' ), 'toggle', '1', 'output' ),
					self::field( 'debug_state', __( 'Debug state chip', 'elementor-implementation-toolkit' ), 'toggle', '', 'runtime' ),
				]
			),
			'conditional_display_rules'=> self::definition(
				'conditional_display_rules',
				__( 'Conditional Display Rules', 'elementor-implementation-toolkit' ),
				__( 'Admin contract for showing or hiding Toolkit controls based on page context and query state.', 'elementor-implementation-toolkit' ),
				'conditional-rules',
				'draft',
				[
					self::field( 'rule_scope', __( 'Rule scope', 'elementor-implementation-toolkit' ), 'select', 'controller', 'scope', '', [ 'controller' => __( 'Controller', 'elementor-implementation-toolkit' ), 'listing' => __( 'Listing target', 'elementor-implementation-toolkit' ), 'module' => __( 'Module', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'condition_key', __( 'Condition key', 'elementor-implementation-toolkit' ), 'text', 'post_type', 'contract', 'post_type' ),
					self::field( 'operator', __( 'Operator', 'elementor-implementation-toolkit' ), 'select', 'equals', 'binding', '', [ 'equals' => __( 'Equals', 'elementor-implementation-toolkit' ), 'contains' => __( 'Contains', 'elementor-implementation-toolkit' ), 'exists' => __( 'Exists', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'condition_value', __( 'Value', 'elementor-implementation-toolkit' ), 'text', 'product', 'output', 'product' ),
					self::field( 'fallback_behavior', __( 'Fallback', 'elementor-implementation-toolkit' ), 'select', 'show', 'runtime', '', [ 'show' => __( 'Show', 'elementor-implementation-toolkit' ), 'hide' => __( 'Hide', 'elementor-implementation-toolkit' ), 'degrade' => __( 'Mark degraded', 'elementor-implementation-toolkit' ) ] ),
				]
			),
			'design_token_mapper'      => self::definition(
				'design_token_mapper',
				__( 'Design Token Mapper', 'elementor-implementation-toolkit' ),
				__( 'Maps Toolkit admin semantics to Elementor controls and frontend CSS variables.', 'elementor-implementation-toolkit' ),
				'token-mapper',
				'draft',
				[
					self::field( 'token_source', __( 'Token source', 'elementor-implementation-toolkit' ), 'select', 'toolkit_palette', 'scope', '', [ 'toolkit_palette' => __( 'Toolkit palette', 'elementor-implementation-toolkit' ), 'elementor_globals' => __( 'Elementor globals placeholder', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'primary_token', __( 'Primary token', 'elementor-implementation-toolkit' ), 'text', '--eit-teal', 'contract', '--eit-teal' ),
					self::field( 'accent_token', __( 'Accent token', 'elementor-implementation-toolkit' ), 'text', '--eit-coral', 'binding', '--eit-coral' ),
					self::field( 'module_token', __( 'Module token', 'elementor-implementation-toolkit' ), 'text', '--eit-purple', 'output', '--eit-purple' ),
					self::field( 'export_css_vars', __( 'Export CSS variables', 'elementor-implementation-toolkit' ), 'toggle', '1', 'runtime' ),
				]
			),
			'editor_handoff_notes'     => self::definition(
				'editor_handoff_notes',
				__( 'Editor Handoff Notes', 'elementor-implementation-toolkit' ),
				__( 'Stores implementer-facing notes attached to a build contract without putting them in Elementor widgets.', 'elementor-implementation-toolkit' ),
				'handoff-notes',
				'draft',
				[
					self::field( 'handoff_owner', __( 'Owner', 'elementor-implementation-toolkit' ), 'text', 'Implementation', 'scope', 'Implementation' ),
					self::field( 'handoff_status', __( 'Handoff status', 'elementor-implementation-toolkit' ), 'select', 'ready_for_build', 'contract', '', [ 'drafting' => __( 'Drafting', 'elementor-implementation-toolkit' ), 'ready_for_build' => __( 'Ready for build', 'elementor-implementation-toolkit' ), 'needs_qa' => __( 'Needs QA', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'primary_note', __( 'Primary note', 'elementor-implementation-toolkit' ), 'text', 'Keep Simple Budget buttons inside each card.', 'binding', 'Keep buttons inside each card.' ),
					self::field( 'risk_note', __( 'Risk note', 'elementor-implementation-toolkit' ), 'text', 'Listing markup must expose selectors.', 'output', 'Listing markup must expose selectors.' ),
					self::field( 'show_in_editor', __( 'Show in editor', 'elementor-implementation-toolkit' ), 'toggle', '1', 'runtime' ),
				]
			),
			'qa_scenario_runner'       => self::definition(
				'qa_scenario_runner',
				__( 'QA Scenario Runner', 'elementor-implementation-toolkit' ),
				__( 'Defines manual and future automated scenarios for admin contracts and demo pages.', 'elementor-implementation-toolkit' ),
				'qa-runner',
				'draft',
				[
					self::field( 'scenario_scope', __( 'Scenario scope', 'elementor-implementation-toolkit' ), 'select', 'demo_pages', 'scope', '', [ 'admin' => __( 'Admin only', 'elementor-implementation-toolkit' ), 'demo_pages' => __( 'Demo pages', 'elementor-implementation-toolkit' ), 'runtime' => __( 'Runtime placeholder', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'desktop_width', __( 'Desktop width', 'elementor-implementation-toolkit' ), 'number', '1440', 'contract', '1440' ),
					self::field( 'mobile_width', __( 'Mobile width', 'elementor-implementation-toolkit' ), 'number', '390', 'binding', '390' ),
					self::field( 'console_required', __( 'Console must be clean', 'elementor-implementation-toolkit' ), 'toggle', '1', 'output' ),
					self::field( 'cleanup_after_run', __( 'Cleanup after run', 'elementor-implementation-toolkit' ), 'toggle', '1', 'runtime' ),
				]
			),
			'connector_registry'       => self::definition(
				'connector_registry',
				__( 'Connector Registry', 'elementor-implementation-toolkit' ),
				__( 'Registry contract for optional adapters without making third-party plugins required.', 'elementor-implementation-toolkit' ),
				'connector-registry',
				'draft',
				[
					self::field( 'registry_mode', __( 'Registry mode', 'elementor-implementation-toolkit' ), 'select', 'optional', 'scope', '', [ 'optional' => __( 'Optional adapters', 'elementor-implementation-toolkit' ), 'strict' => __( 'Strict local project', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'adapter_namespace', __( 'Adapter namespace', 'elementor-implementation-toolkit' ), 'text', 'EIT\\\\Adapters', 'contract', 'EIT\\\\Adapters' ),
					self::field( 'missing_adapter_behavior', __( 'Missing adapter', 'elementor-implementation-toolkit' ), 'select', 'degrade', 'binding', '', [ 'degrade' => __( 'Mark degraded', 'elementor-implementation-toolkit' ), 'hide' => __( 'Hide module', 'elementor-implementation-toolkit' ), 'fallback' => __( 'Use fallback', 'elementor-implementation-toolkit' ) ] ),
					self::field( 'show_admin_diagnostics', __( 'Admin diagnostics', 'elementor-implementation-toolkit' ), 'toggle', '1', 'output' ),
					self::field( 'public_dependency', __( 'Public dependency', 'elementor-implementation-toolkit' ), 'select', 'none', 'runtime', '', [ 'none' => __( 'None', 'elementor-implementation-toolkit' ), 'local_only' => __( 'Local only', 'elementor-implementation-toolkit' ) ] ),
				]
			),
		];
	}

	private static function definition( $id, $title, $description, $icon, $status, array $fields ) {
		return [
			'id'          => sanitize_key( $id ),
			'title'       => $title,
			'description' => $description,
			'icon'        => sanitize_key( $icon ),
			'status'      => self::allowed_value( $status, array_keys( self::statuses() ), 'draft' ),
			'layers'      => self::layers(),
			'fields'      => array_slice( $fields, 0, self::MAX_FIELDS ),
		];
	}

	private static function layers() {
		return [
			'scope'    => [
				'label'   => __( 'Scope', 'elementor-implementation-toolkit' ),
				'summary' => __( 'Where this module is allowed to observe or act.', 'elementor-implementation-toolkit' ),
				'icon'    => 'scope',
			],
			'contract' => [
				'label'   => __( 'Contract', 'elementor-implementation-toolkit' ),
				'summary' => __( 'The stable backend agreement this module exposes.', 'elementor-implementation-toolkit' ),
				'icon'    => 'contract',
			],
			'binding'  => [
				'label'   => __( 'Binding', 'elementor-implementation-toolkit' ),
				'summary' => __( 'How selectors, values, and state connect.', 'elementor-implementation-toolkit' ),
				'icon'    => 'binding',
			],
			'output'   => [
				'label'   => __( 'Output', 'elementor-implementation-toolkit' ),
				'summary' => __( 'What the implementer should expect to see or inspect.', 'elementor-implementation-toolkit' ),
				'icon'    => 'output',
			],
			'runtime'  => [
				'label'   => __( 'Runtime Boundary', 'elementor-implementation-toolkit' ),
				'summary' => __( 'What remains admin-only now versus executable later.', 'elementor-implementation-toolkit' ),
				'icon'    => 'runtime',
			],
		];
	}

	private static function field( $key, $label, $type, $default, $layer, $placeholder = '', array $options = [] ) {
		return [
			'key'         => sanitize_key( $key ),
			'label'       => $label,
			'type'        => sanitize_key( $type ),
			'default'     => (string) $default,
			'layer'       => sanitize_key( $layer ),
			'placeholder' => (string) $placeholder,
			'options'     => $options,
		];
	}

	private static function defaults_for( array $definition ) {
		$defaults = [];

		foreach ( $definition['fields'] as $field ) {
			$defaults[ $field['key'] ] = $field['default'];
		}

		return $defaults;
	}

	private static function sanitize_values( $values, array $definition ) {
		$values = is_array( $values ) ? $values : [];
		$clean  = [];

		foreach ( $definition['fields'] as $field ) {
			$key = $field['key'];
			$raw = $values[ $key ] ?? '';

			switch ( $field['type'] ) {
				case 'toggle':
					$clean[ $key ] = self::truthy( $raw ) ? '1' : '';
					break;
				case 'number':
					$clean[ $key ] = (string) max( 0, absint( $raw ) );
					break;
				case 'select':
					$clean[ $key ] = self::allowed_value( $raw, array_keys( $field['options'] ?? [] ), $field['default'] );
					break;
				case 'color':
					$color = sanitize_hex_color( $raw );
					$clean[ $key ] = $color ?: $field['default'];
					break;
				default:
					$clean[ $key ] = sanitize_text_field( $raw );
					break;
			}
		}

		return $clean;
	}

	private static function saved() {
		$saved = get_option( self::OPTION, [] );

		return is_array( $saved ) ? $saved : [];
	}

	private static function allowed_value( $value, array $allowed, $fallback ) {
		$value = (string) $value;

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private static function truthy( $value ) {
		return in_array( $value, [ true, 1, '1', 'true', 'yes', 'on' ], true );
	}
}
