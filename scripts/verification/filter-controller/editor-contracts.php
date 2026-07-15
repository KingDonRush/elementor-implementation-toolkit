<?php
if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
	eit_fc_fail( 'TEST-FC-ROBUSTNESS-001', 'Elementor loaded', [ 'reason' => 'Elementor is not loaded.' ] );
} else {
	list( $widget, $controls ) = eit_fc_controls();
	$editor_js = file_get_contents( EIT_PATH . 'assets/js/eit-editor.js' );
	$editor_css = file_get_contents( EIT_PATH . 'assets/css/eit-editor.css' );

	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Search debounce control remains registered', isset( $controls['search_debounce_ms'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Search section remains registered for editor cadence', isset( $controls['section_search_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Search exposes icon, clear, and focus style controls', isset( $controls['search_icon_size'] ) && isset( $controls['search_icon_color'] ) && isset( $controls['search_clear_color'] ) && isset( $controls['search_focus_ring_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Select section remains registered for editor cadence', isset( $controls['section_select_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Select exposes native note, field height, arrow, and focus controls', isset( $controls['select_native_note'] ) && isset( $controls['select_field_height'] ) && isset( $controls['select_arrow_size'] ) && isset( $controls['select_arrow_color'] ) && isset( $controls['select_focus_ring_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Checkbox section remains registered for editor cadence', isset( $controls['section_checkbox_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Checkbox exposes layout, indicator, and focus style controls', isset( $controls['checkbox_direction'] ) && isset( $controls['checkbox_wrap'] ) && isset( $controls['checkbox_indicator_size'] ) && isset( $controls['checkbox_indicator_position'] ) && isset( $controls['checkbox_focus_ring_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Chips section remains registered for editor cadence', isset( $controls['section_chips_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Chips exposes wrap, scroll, grid, icon, and focus style controls', isset( $controls['chips_wrap'] ) && isset( $controls['chips_scroll_row'] ) && isset( $controls['chips_columns'] ) && isset( $controls['chips_check_size'] ) && isset( $controls['chips_focus_ring_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Radio section remains registered for editor cadence', isset( $controls['section_radio_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Radio exposes layout, segmented, indicator, and focus style controls', isset( $controls['radio_direction'] ) && isset( $controls['radio_segmented'] ) && isset( $controls['radio_indicator_size'] ) && isset( $controls['radio_dot_size'] ) && isset( $controls['radio_focus_ring_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Toggle section remains registered for editor cadence', isset( $controls['section_toggle_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Toggle exposes contract, track, thumb, state, and focus style controls', isset( $controls['toggle_contract_note'] ) && isset( $controls['toggle_track_width'] ) && isset( $controls['toggle_track_on_color'] ) && isset( $controls['toggle_thumb_size'] ) && isset( $controls['toggle_state_text_display'] ) && isset( $controls['toggle_focus_ring_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Swatch section remains registered for editor cadence', isset( $controls['section_swatch_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Swatch exposes visual, ring, label, fallback, and focus style controls', isset( $controls['swatch_contract_note'] ) && isset( $controls['swatch_size'] ) && isset( $controls['swatch_shape'] ) && isset( $controls['swatch_border_color'] ) && isset( $controls['swatch_hide_labels'] ) && isset( $controls['swatch_ring_color'] ) && isset( $controls['swatch_ring_offset'] ) && isset( $controls['swatch_fallback_background'] ) && isset( $controls['swatch_focus_ring_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Range section remains registered for editor cadence', isset( $controls['section_range_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Range exposes vertical rail side controls', isset( $controls['range_value_label_position'] ) && isset( $controls['range_tick_position'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Date section remains registered for editor cadence', isset( $controls['section_date_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Date exposes native note, layout, label, separator, state, and clear controls', isset( $controls['date_native_note'] ) && isset( $controls['date_stack_fields'] ) && isset( $controls['date_gap'] ) && isset( $controls['date_hide_labels'] ) && isset( $controls['date_separator_display'] ) && isset( $controls['date_input_height'] ) && isset( $controls['date_focus_ring_color'] ) && isset( $controls['date_invalid_color'] ) && isset( $controls['date_clear_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Rating section remains registered for editor cadence', isset( $controls['section_rating_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Rating exposes display, icon, and state style controls', isset( $controls['rating_display_mode'] ) && isset( $controls['rating_threshold_note'] ) && isset( $controls['rating_icon'] ) && isset( $controls['rating_icon_size'] ) && isset( $controls['rating_icon_gap'] ) && isset( $controls['rating_active_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Option section remains registered for editor cadence', isset( $controls['section_option_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Field controls remain registered for editor cadence', isset( $controls['field_text_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Style controls no longer depend on hidden filter flags', false === strpos( file_get_contents( EIT_PATH . 'includes/Elementor/FilterController/StyleControls.php' ), 'eit_filter_has_' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence falls back from empty repeater DOM to widget model', false !== strpos( $editor_js, 'return rows.length ? rows : null;' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence uses body classes instead of inline control display', false !== strpos( $editor_js, 'eit-filter-style-cadence-active' ) && false === strpos( $editor_js, 'style.display' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence uses official hooks with a bounded observer fallback', false !== strpos( $editor_js, 'panel/open_editor/widget/eit-filter-controller' ) && false !== strpos( $editor_js, 'new MutationObserver' ) && false === strpos( $editor_js, 'setInterval' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Search as its own style family', false !== strpos( $editor_js, 'eit_filter_has_search_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-search' ) && false !== strpos( $editor_css, '.elementor-control-section_search_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Select as its own style family', false !== strpos( $editor_js, 'eit_filter_has_select_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-select' ) && false !== strpos( $editor_css, '.elementor-control-section_select_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Checkbox as its own style family', false !== strpos( $editor_js, 'eit_filter_has_checkbox_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-checkbox' ) && false !== strpos( $editor_css, '.elementor-control-section_checkbox_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Chips as its own style family', false !== strpos( $editor_js, 'eit_filter_has_chips_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-chips' ) && false !== strpos( $editor_css, '.elementor-control-section_chips_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Radio as its own style family', false !== strpos( $editor_js, 'eit_filter_has_radio_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-radio' ) && false !== strpos( $editor_css, '.elementor-control-section_radio_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Toggle as its own style family', false !== strpos( $editor_js, 'eit_filter_has_toggle_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-toggle' ) && false !== strpos( $editor_css, '.elementor-control-section_toggle_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Swatch as its own style family', false !== strpos( $editor_js, 'eit_filter_has_swatch_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-swatch' ) && false !== strpos( $editor_css, '.elementor-control-section_swatch_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Date as its own style family', false !== strpos( $editor_js, 'eit_filter_has_date_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-date' ) && false !== strpos( $editor_css, '.elementor-control-section_date_style' ) );

	eit_fc_assert(
		'TEST-FC-ROBUSTNESS-001',
		'Sort style section exists',
		isset( $controls['section_sort_style'] ),
		[ 'expected' => 'Sort needs an independent Style section gated by show_sort.' ]
	);
	eit_fc_assert(
		'TEST-FC-ROBUSTNESS-001',
		'Sort style section hides when Sort is disabled',
		isset( $controls['section_sort_style'] ) && false === eit_fc_control_visible( $widget, $controls, 'section_sort_style', eit_fc_visibility_values( [ 'search' ], [ 'show_sort' => '' ] ) )
	);
}
