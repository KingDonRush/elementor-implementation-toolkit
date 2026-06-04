<?php
/**
 * Frontend runtime config for the Elementor Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RuntimeConfig {

	public static function from_settings( $instance, array $settings ) {
		return [
			'instance'        => (string) $instance,
			'targetSelector'  => sanitize_text_field( $settings['target_selector'] ?? '' ),
			'itemSelector'    => sanitize_text_field( $settings['item_selector'] ?? '' ),
			'autoApply'       => ( $settings['auto_apply'] ?? 'yes' ) === 'yes',
			'syncUrl'         => ( $settings['sync_url'] ?? 'yes' ) === 'yes',
			'perPage'         => max( 1, min( 96, absint( $settings['per_page'] ?? 9 ) ) ),
			'paginationType'  => sanitize_key( $settings['pagination_type'] ?? 'numbers' ),
			'previousText'    => sanitize_text_field( $settings['previous_text'] ?? __( 'Previous', 'elementor-implementation-toolkit' ) ),
			'nextText'        => sanitize_text_field( $settings['next_text'] ?? __( 'Next', 'elementor-implementation-toolkit' ) ),
			'emptyText'       => sanitize_text_field( $settings['empty_text'] ?? __( 'No matching items found.', 'elementor-implementation-toolkit' ) ),
			'resultText'      => sanitize_text_field( $settings['result_count_text'] ?? __( '{count} results', 'elementor-implementation-toolkit' ) ),
			'showResultCount' => ( $settings['show_result_count'] ?? 'yes' ) === 'yes',
			'showActiveChips' => ( $settings['show_active_chips'] ?? 'yes' ) === 'yes',
			'presetState'     => sanitize_key( $settings['preset_resolution_state'] ?? 'widget' ),
			'presetId'        => sanitize_key( $settings['resolved_filter_preset'] ?? ( $settings['filter_preset'] ?? '' ) ),
			'presetName'      => sanitize_text_field( $settings['resolved_filter_preset_name'] ?? '' ),
		];
	}
}
