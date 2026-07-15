<?php
$css = eit_fc_stylesheet_contents( 'assets/css/eit-frontend.css' );
$frontend_js = implode(
	"\n",
	array_map(
		'file_get_contents',
		glob( EIT_PATH . 'assets/src/frontend/*.js' )
	)
);
list( $balanced, $balance_detail ) = eit_fc_css_braces_balanced( $css );

eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Frontend CSS braces are balanced', $balanced, [ 'detail' => $balance_detail ] );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Controller form uses wrapping flex packing', eit_fc_css_contains( $css, '.eit-filter-controller__form', 'flex-wrap: wrap' ) && false !== strpos( $css, '--eit-filter-form-gap' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Filter groups can shrink inside layout spans', eit_fc_css_contains( $css, '.eit-filter-group', 'min-width: 0' ) && false !== strpos( $css, '--eit-filter-basis' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Inputs are full-width inside containers', eit_fc_css_contains( $css, '.eit-input,\s*.eit-select', 'width: 100%' ) || false !== strpos( $css, ".eit-input,\n.eit-select {\n    width: 100%;" ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-006', 'Range hidden inputs do not reserve visible layout space', false !== strpos( $css, '.eit-range:not(.eit-range--show-inputs) .eit-range__values' ) && false !== strpos( $css, "display: none;\n}" ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-006', 'Range icon visual bounds constrain oversized icons', false !== strpos( $css, '--eit-range-thumb-visual-size: max(' ) && false !== strpos( $css, 'max-width: calc(var(--eit-range-thumb-visual-size)' ) && false !== strpos( $css, 'overflow: hidden;' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-006', 'Vertical range has alignment variables', false !== strpos( $css, '--eit-range-vertical-alignment' ) && false !== strpos( $css, '--eit-range-vertical-item-alignment' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-006', 'Vertical range supports rail side classes', false !== strpos( $css, '.eit-range--vertical.eit-range--value-labels-right' ) && false !== strpos( $css, '.eit-range--vertical.eit-range--ticks-left' ) && false !== strpos( $css, '--eit-range-label-order' ) && false !== strpos( $css, '--eit-range-tick-order' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-008', 'Rating display and icon CSS contract exists', false !== strpos( $css, '.eit-rating-option__icon' ) && false !== strpos( $css, '.eit-rating-option--display-icon .eit-rating-option__label' ) && false !== strpos( $css, '--eit-rating-icon-size' ) && false !== strpos( $css, '--eit-rating-active-icon-color' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-011', 'Search field CSS anatomy exists', false !== strpos( $css, '.eit-search-field' ) && false !== strpos( $css, '.eit-search-field__clear' ) && false !== strpos( $css, '--eit-search-focus-ring-color' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-011', 'Search frontend JS clear and debounce contract exists', false !== strpos( $frontend_js, 'data-eit-search-clear' ) && false !== strpos( $frontend_js, 'searchDebounceMs' ) && false !== strpos( $frontend_js, 'syncSearchClearButtons' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-012', 'Select field CSS anatomy exists', false !== strpos( $css, '.eit-select-field' ) && false !== strpos( $css, '.eit-select-field__arrow' ) && false !== strpos( $css, '--eit-select-focus-ring-color' ) && false !== strpos( $css, 'text-overflow: ellipsis' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-013', 'Checkbox CSS indicator and count contract exists', false !== strpos( $css, '.eit-checkbox-indicator' ) && false !== strpos( $css, '.eit-option-count' ) && false !== strpos( $css, '--eit-checkbox-indicator-size' ) && false !== strpos( $css, '.eit-options__empty' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-014', 'Radio CSS indicator and segmented contract exists', false !== strpos( $css, '.eit-radio-indicator' ) && false !== strpos( $css, '--eit-radio-indicator-size' ) && false !== strpos( $css, '--eit-radio-dot-size' ) && false !== strpos( $css, '--eit-radio-active-background' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-015', 'Chips CSS anatomy and active affordance contract exists', false !== strpos( $css, '.eit-chip-check' ) && false !== strpos( $css, '.eit-chip-visual' ) && false !== strpos( $css, '--eit-chip-check-size' ) && false !== strpos( $css, '--eit-chip-active-outline-color' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-016', 'Toggle CSS track, thumb, state text, and focus contract exists', false !== strpos( $css, '.eit-option--toggle' ) && false !== strpos( $css, '.eit-toggle__state--on' ) && false !== strpos( $css, '--eit-toggle-track-width' ) && false !== strpos( $css, '--eit-toggle-thumb-size' ) && false !== strpos( $css, '--eit-toggle-focus-ring-color' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-016', 'Toggle frontend JS keeps checked-only and clear-to-off semantics', false !== strpos( $frontend_js, "['toggle', 'radio', 'rating'].includes(type)" ) && false !== strpos( $frontend_js, 'if (!control.checked)' ) && false !== strpos( $frontend_js, 'control.checked = false' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-017', 'Swatch CSS visual, fallback, ring, and focus contract exists', false !== strpos( $css, '.eit-option--swatch' ) && false !== strpos( $css, '.eit-swatch--fallback' ) && false !== strpos( $css, '--eit-swatch-ring-width' ) && false !== strpos( $css, '--eit-swatch-focus-ring-color' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-018', 'Date CSS field, clear, invalid, and focus contract exists', false !== strpos( $css, '.eit-date-range__field' ) && false !== strpos( $css, '.eit-date-range__clear' ) && false !== strpos( $css, '.eit-date-range.is-invalid' ) && false !== strpos( $css, '--eit-date-focus-ring-color' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-018', 'Date frontend JS clear, sync, and active chip contract exists', false !== strpos( $frontend_js, 'data-eit-date-clear' ) && false !== strpos( $frontend_js, 'syncDateRange' ) && false !== strpos( $frontend_js, 'formatActiveValue' ) );

