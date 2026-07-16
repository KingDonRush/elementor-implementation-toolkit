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
		$provider = sanitize_key( $settings['data_provider'] ?? 'dom' );
		$provider = in_array( $provider, [ 'collection', 'cct', 'dom' ], true ) ? $provider : 'dom';
		$result_text = sanitize_text_field( $settings['result_count_text'] ?? __( '{count} results', 'elementor-implementation-toolkit' ) );
		$singular_text = sanitize_text_field( $settings['result_count_singular_text'] ?? '' );
		if ( '' === $singular_text ) {
			$singular_text = __( '{count} results', 'elementor-implementation-toolkit' ) === $result_text
				? __( '{count} result', 'elementor-implementation-toolkit' )
				: $result_text;
		}
		return [
			'instance'        => (string) $instance,
			'provider'        => $provider,
			'collectionId'    => sanitize_text_field( $settings['collection_id'] ?? '' ),
				'collectionFacetIds' => array_values( array_map( 'strval', $settings['collection_facet_field_ids'] ?? [] ) ),
				'collectionExplain' => ( $settings['collection_explain'] ?? '' ) === 'yes',
			'collectionTarget' => sanitize_text_field( $settings['collection_target_id'] ?? '' ),
			'cctType'         => sanitize_key( $settings['cct_type'] ?? '' ),
			'cctTemplateId'   => absint( $settings['cct_template_id'] ?? 0 ),
			'targetSelector'  => 'collection' === $provider ? '' : sanitize_text_field( $settings['target_selector'] ?? '' ),
			'itemSelector'    => 'collection' === $provider ? '' : sanitize_text_field( $settings['item_selector'] ?? '' ),
			'autoApply'       => ( $settings['auto_apply'] ?? 'yes' ) === 'yes',
			'searchDebounceMs' => max( 0, min( 2000, absint( $settings['search_debounce_ms'] ?? 250 ) ) ),
			'syncUrl'         => ( $settings['sync_url'] ?? 'yes' ) === 'yes',
			'perPage'         => max( 1, min( 48, absint( $settings['per_page'] ?? 24 ) ) ),
			'paginationType'  => sanitize_key( $settings['pagination_type'] ?? 'numbers' ),
			'previousText'    => sanitize_text_field( $settings['previous_text'] ?? __( 'Previous', 'elementor-implementation-toolkit' ) ),
			'nextText'        => sanitize_text_field( $settings['next_text'] ?? __( 'Next', 'elementor-implementation-toolkit' ) ),
			'emptyText'       => sanitize_text_field( $settings['empty_text'] ?? __( 'No matching items found.', 'elementor-implementation-toolkit' ) ),
			'resultText'      => $result_text,
			'resultTextSingular' => $singular_text,
			'showResultCount' => ( $settings['show_result_count'] ?? 'yes' ) === 'yes',
			'showActiveChips' => ( $settings['show_active_chips'] ?? 'yes' ) === 'yes',
			'presetState'     => sanitize_key( $settings['preset_resolution_state'] ?? 'widget' ),
			'presetId'        => sanitize_key( $settings['resolved_filter_preset'] ?? ( $settings['filter_preset'] ?? '' ) ),
			'presetName'      => sanitize_text_field( $settings['resolved_filter_preset_name'] ?? '' ),
		];
	}
}
