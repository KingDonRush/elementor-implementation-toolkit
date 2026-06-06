<?php
/**
 * WP-CLI smoke harness for Filter Controller robustness contracts.
 *
 * Usage:
 * docker compose run --rm wpcli eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-filter-controller-robustness.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EIT\CPT\CptManager;
use EIT\Elementor\FilterController\FieldBindingResolver;
use EIT\Elementor\FilterController\FilterOptions;
use EIT\Elementor\FilterController\FilterSettings;
use EIT\Elementor\FilterController\FilterTypeRegistry;
use EIT\Elementor\FilterController\Renderers\Types\ChoiceOptionsRenderer;
use EIT\Elementor\FilterController\Renderers\Types\SearchRenderer;
use EIT\Elementor\FilterController\Renderers\Types\SelectRenderer;
use EIT\Elementor\FilterController\RuntimeConfig;
use EIT\Elementor\Widgets\FilterController;
use EIT\Support\FilterPresets;
use EIT\Support\FilterResolver;
use EIT\Support\SortOptions;
use EIT\Support\ToolkitFieldCatalog;

$GLOBALS['eit_results'] = [];

function eit_fc_result( $test_id, $name, $status, array $details = [] ) {
	$GLOBALS['eit_results'][] = [
		'test'    => $test_id,
		'name'    => $name,
		'status'  => $status,
		'details' => $details,
	];
}

function eit_fc_pass( $test_id, $name, array $details = [] ) {
	eit_fc_result( $test_id, $name, 'PASS', $details );
}

function eit_fc_fail( $test_id, $name, array $details = [] ) {
	eit_fc_result( $test_id, $name, 'FAIL', $details );
}

function eit_fc_skip( $test_id, $name, array $details = [] ) {
	eit_fc_result( $test_id, $name, 'SKIP_HUMAN_QA', $details );
}

function eit_fc_assert( $test_id, $name, $condition, array $details = [] ) {
	$condition ? eit_fc_pass( $test_id, $name, $details ) : eit_fc_fail( $test_id, $name, $details );
}

function eit_fc_controls() {
	$widget = new FilterController();
	$stack  = $widget->get_stack( false );
	$controls = array_merge( $stack['controls'] ?? [], $stack['style_controls'] ?? [] );

	return [ $widget, $controls ];
}

function eit_fc_control_visible( FilterController $widget, array $controls, $control_id, array $values ) {
	if ( empty( $controls[ $control_id ] ) ) {
		return null;
	}

	return $widget->is_control_visible( $controls[ $control_id ], $values, $controls );
}

function eit_fc_visibility_values( array $types, array $overrides = [] ) {
	return array_merge(
		[
			'show_sort'                       => 'yes',
			'show_result_count'               => 'yes',
			'show_active_chips'               => 'yes',
			'pagination_type'                 => 'numbers',
			'range_orientation'               => 'horizontal',
			'range_show_inputs'               => 'yes',
			'range_show_ticks'                => '',
			'range_handle_icon_enabled'       => '',
			'eit_filter_has_field_controls'    => '',
			'eit_filter_has_option_controls'   => '',
			'eit_filter_has_checkbox_controls' => '',
			'eit_filter_has_chips_controls'    => '',
			'eit_filter_has_radio_controls'    => '',
			'eit_filter_has_search_controls'   => '',
			'eit_filter_has_select_controls'   => '',
			'eit_filter_has_range_controls'    => '',
			'eit_filter_has_rating_controls'   => '',
		],
		FilterTypeRegistry::state_flags_for_types( $types ),
		$overrides
	);
}

function eit_fc_ids_for_result( array $result ) {
	return implode( ',', $result['allIds'] ?? [] );
}

function eit_fc_css_contains( $css, $selector, $property ) {
	$pattern = '/' . preg_quote( $selector, '/' ) . '\s*\{[^}]*' . preg_quote( $property, '/' ) . '/s';

	return (bool) preg_match( $pattern, $css );
}

function eit_fc_css_braces_balanced( $css ) {
	$level = 0;
	$line = 1;

	for ( $index = 0, $length = strlen( $css ); $index < $length; $index++ ) {
		if ( "\n" === $css[ $index ] ) {
			$line++;
		}

		if ( '{' === $css[ $index ] ) {
			$level++;
		}

		if ( '}' === $css[ $index ] ) {
			$level--;

			if ( $level < 0 ) {
				return [ false, $line ];
			}
		}
	}

	return [ 0 === $level, $level ];
}

function eit_fc_stylesheet_contents( $relative_path, array $seen = [] ) {
	$relative_path = ltrim( $relative_path, '/' );

	if ( isset( $seen[ $relative_path ] ) ) {
		return '';
	}

	$seen[ $relative_path ] = true;
	$path = EIT_PATH . $relative_path;

	if ( ! file_exists( $path ) ) {
		return '';
	}

	$css = file_get_contents( $path );

	return preg_replace_callback(
		'/@import\s+url\("([^"]+)"\);/',
		function ( $matches ) use ( $relative_path, $seen ) {
			$base = dirname( $relative_path );
			return eit_fc_stylesheet_contents( $base . '/' . $matches[1], $seen );
		},
		$css
	);
}

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
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Range section remains registered for editor cadence', isset( $controls['section_range_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Range exposes vertical rail side controls', isset( $controls['range_value_label_position'] ) && isset( $controls['range_tick_position'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Rating section remains registered for editor cadence', isset( $controls['section_rating_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Rating exposes display, icon, and state style controls', isset( $controls['rating_display_mode'] ) && isset( $controls['rating_threshold_note'] ) && isset( $controls['rating_icon'] ) && isset( $controls['rating_icon_size'] ) && isset( $controls['rating_icon_gap'] ) && isset( $controls['rating_active_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Option section remains registered for editor cadence', isset( $controls['section_option_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Field controls remain registered for editor cadence', isset( $controls['field_text_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Style controls no longer depend on hidden filter flags', false === strpos( file_get_contents( EIT_PATH . 'includes/Elementor/FilterController/StyleControls.php' ), 'eit_filter_has_' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence falls back from empty repeater DOM to widget model', false !== strpos( $editor_js, 'return rows.length ? rows : null;' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence uses body classes instead of inline control display', false !== strpos( $editor_js, 'eit-filter-style-cadence-active' ) && false === strpos( $editor_js, 'style.display' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Search as its own style family', false !== strpos( $editor_js, 'eit_filter_has_search_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-search' ) && false !== strpos( $editor_css, '.elementor-control-section_search_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Select as its own style family', false !== strpos( $editor_js, 'eit_filter_has_select_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-select' ) && false !== strpos( $editor_css, '.elementor-control-section_select_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Checkbox as its own style family', false !== strpos( $editor_js, 'eit_filter_has_checkbox_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-checkbox' ) && false !== strpos( $editor_css, '.elementor-control-section_checkbox_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Chips as its own style family', false !== strpos( $editor_js, 'eit_filter_has_chips_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-chips' ) && false !== strpos( $editor_css, '.elementor-control-section_chips_style' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence tracks Radio as its own style family', false !== strpos( $editor_js, 'eit_filter_has_radio_controls' ) && false !== strpos( $editor_js, 'eit-filter-style-has-radio' ) && false !== strpos( $editor_css, '.elementor-control-section_radio_style' ) );

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

$css = eit_fc_stylesheet_contents( 'assets/css/eit-frontend.css' );
$frontend_js = file_get_contents( EIT_PATH . 'assets/js/eit-frontend.js' );
list( $balanced, $balance_detail ) = eit_fc_css_braces_balanced( $css );

eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Frontend CSS braces are balanced', $balanced, [ 'detail' => $balance_detail ] );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Controller form uses grid packing', eit_fc_css_contains( $css, '.eit-filter-controller__form', 'grid-template-columns: repeat(100, minmax(0, 1fr))' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Filter groups can shrink inside grid spans', eit_fc_css_contains( $css, '.eit-filter-group', 'min-width: 0' ) );
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

$runtime_config = RuntimeConfig::from_settings( 'qa', [ 'search_debounce_ms' => 375 ] );
$runtime_config_clamped = RuntimeConfig::from_settings( 'qa', [ 'search_debounce_ms' => 5000 ] );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-011', 'Search debounce enters runtime config', 375 === $runtime_config['searchDebounceMs'] );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-011', 'Search debounce runtime config is clamped', 2000 === $runtime_config_clamped['searchDebounceMs'] );

ob_start();
SearchRenderer::render(
	'qa-search',
	[
		'label'       => 'Search QA',
		'placeholder' => 'Find items',
	],
	''
);
$search_markup = ob_get_clean();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-011', 'Search renderer emits wrapped clearable field', false !== strpos( $search_markup, 'data-eit-search-field' ) && false !== strpos( $search_markup, 'data-eit-search-input' ) && false !== strpos( $search_markup, 'data-eit-search-clear' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-011', 'Search renderer keeps global visible-text source contract', false !== strpos( $search_markup, 'data-eit-key=""' ) && false !== strpos( $search_markup, 'type="search"' ) );

ob_start();
SelectRenderer::render(
	[
		'label'       => 'Select QA',
		'placeholder' => 'All items',
		'options'     => [
			[
				'value' => 'premium',
				'label' => 'Premium option with a long label',
			],
		],
	],
	'category'
);
$select_markup = ob_get_clean();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-012', 'Select renderer emits native select with wrapper arrow', false !== strpos( $select_markup, 'data-eit-select-field' ) && false !== strpos( $select_markup, 'eit-select-field__arrow' ) && false !== strpos( $select_markup, 'data-eit-key="category"' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-012', 'Select renderer keeps empty option as all-state label', false !== strpos( $select_markup, '<option value="">All items</option>' ) && false !== strpos( $select_markup, 'value="premium"' ) );

$checkbox_options = FilterOptions::parse( "featured|Featured||12\nstandard|Standard||3" );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-013', 'Checkbox option parser accepts optional count slot', 12 === ( $checkbox_options[0]['count'] ?? null ) && 3 === ( $checkbox_options[1]['count'] ?? null ) );

ob_start();
ChoiceOptionsRenderer::render(
	'checkbox',
	[
		'options' => $checkbox_options,
	],
	'eit-qa-checkbox',
	'category'
);
$checkbox_markup = ob_get_clean();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-013', 'Checkbox renderer emits native inputs with custom indicator and count', false !== strpos( $checkbox_markup, 'type="checkbox"' ) && false !== strpos( $checkbox_markup, 'eit-checkbox-indicator' ) && false !== strpos( $checkbox_markup, 'eit-option__label' ) && false !== strpos( $checkbox_markup, 'eit-option-count' ) );

ob_start();
ChoiceOptionsRenderer::render( 'checkbox', [ 'options' => [] ], 'eit-empty-checkbox', 'category' );
$empty_checkbox_markup = ob_get_clean();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-013', 'Checkbox renderer emits empty options state', false !== strpos( $empty_checkbox_markup, 'data-eit-options-empty' ) );

$radio_options = FilterOptions::parse( "premium|Premium||5\nstandard|Standard||2" );
ob_start();
ChoiceOptionsRenderer::render(
	'radio',
	[
		'options'       => $radio_options,
		'radioShowAll'  => true,
		'radioAllLabel' => 'All tiers',
	],
	'eit-qa-radio',
	'tier'
);
$radio_markup = ob_get_clean();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-014', 'Radio renderer emits single-choice inputs with custom indicator', false !== strpos( $radio_markup, 'type="radio"' ) && false !== strpos( $radio_markup, 'eit-radio-indicator' ) && false === strpos( $radio_markup, 'name="eit-qa-radio[]"' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-014', 'Radio renderer emits optional all-state as empty value', false !== strpos( $radio_markup, 'value=""' ) && false !== strpos( $radio_markup, 'All tiers' ) );

$chips_options = FilterOptions::parse( "featured|Featured|#14b8a6|7\nlong|A very long chip label that must stay contained||4" );
ob_start();
ChoiceOptionsRenderer::render(
	'chips',
	[
		'options' => $chips_options,
	],
	'eit-qa-chips',
	'category'
);
$chips_markup = ob_get_clean();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-015', 'Chips renderer keeps grouped checkbox semantics with custom chip affordance', false !== strpos( $chips_markup, 'type="checkbox"' ) && false !== strpos( $chips_markup, 'name="eit-qa-chips[]"' ) && false !== strpos( $chips_markup, 'eit-chip-check' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-015', 'Chips renderer emits visual marker, label, and count slots', false !== strpos( $chips_markup, 'eit-chip-visual' ) && false !== strpos( $chips_markup, 'eit-option__label' ) && false !== strpos( $chips_markup, 'eit-option-count' ) );

$original_definitions = get_option( CptManager::OPTION, [] );
$original_presets     = get_option( FilterPresets::OPTION, [] );
$post_id              = 0;
$term_id              = 0;

try {
	$definitions = is_array( $original_definitions ) ? $original_definitions : [];
	$post_type = isset( $definitions['_portfolio_item'] ) ? '_portfolio_item' : '';

	if ( '' === $post_type ) {
		foreach ( $definitions as $candidate_slug => $definition ) {
			if ( ! empty( $definition['public'] ) && post_type_exists( $candidate_slug ) ) {
				$post_type = $candidate_slug;
				break;
			}
		}
	}

	if ( '' === $post_type ) {
		eit_fc_fail( 'TEST-FC-ROBUSTNESS-004', 'Toolkit public CPT fixture available', [ 'reason' => 'No registered public Toolkit CPT definition found.' ] );
	} else {
		$taxonomy = '';

		foreach ( $definitions[ $post_type ]['taxonomies'] ?? [] as $taxonomy_definition ) {
			if ( ! empty( $taxonomy_definition['public'] ) && ! empty( $taxonomy_definition['show_in_rest'] ) && taxonomy_exists( $taxonomy_definition['slug'] ) ) {
				$taxonomy = $taxonomy_definition['slug'];
				break;
			}
		}

		$definitions[ $post_type ]['meta_fields'] = [
			[
				'key'          => 'qa_text',
				'label'        => 'QA Text',
				'type'         => 'text',
				'default'      => '',
				'options'      => '',
				'required'     => false,
				'show_in_rest' => true,
			],
			[
				'key'          => 'qa_budget',
				'label'        => 'QA Budget',
				'type'         => 'number',
				'default'      => '',
				'options'      => '',
				'required'     => false,
				'show_in_rest' => true,
			],
			[
				'key'          => 'qa_date',
				'label'        => 'QA Date',
				'type'         => 'date',
				'default'      => '',
				'options'      => '',
				'required'     => false,
				'show_in_rest' => true,
			],
			[
				'key'          => 'qa_select',
				'label'        => 'QA Select',
				'type'         => 'select',
				'default'      => '',
				'options'      => "standard|Standard\npremium|Premium",
				'required'     => false,
				'show_in_rest' => true,
			],
			[
				'key'          => 'qa_private',
				'label'        => 'QA Private',
				'type'         => 'number',
				'default'      => '',
				'options'      => '',
				'required'     => false,
				'show_in_rest' => false,
			],
		];

		update_option( CptManager::OPTION, $definitions, false );

		$options = ToolkitFieldCatalog::select_options();
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog includes text field', isset( $options['qa_text'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog includes number field', isset( $options['qa_budget'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog includes date field', isset( $options['qa_date'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog includes select field', isset( $options['qa_select'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog excludes private meta', ! isset( $options['qa_private'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog includes taxonomy field', '' === $taxonomy || isset( $options[ $taxonomy ] ), [ 'taxonomy' => $taxonomy ] );

		$dynamic_tags = \Elementor\Plugin::$instance->dynamic_tags;
		$tags = $dynamic_tags->get_tags();
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit field key dynamic tag registers', isset( $tags['eit-toolkit-field-key'] ) );

		$dynamic_text = $dynamic_tags->tag_data_to_tag_text( 'eit-qa', 'eit-toolkit-field-key', [ 'key' => 'qa_budget' ] );
		$resolved = FieldBindingResolver::resolve_filter( [ 'field_binding_dynamic' => $dynamic_text ] );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Serialized Toolkit dynamic tag resolves key', 'qa_budget' === $resolved['key'] && FieldBindingResolver::SOURCE_DYNAMIC_BINDING === $resolved['key_source'], $resolved );

		$preset_id = FilterPresets::save(
			[
				'name'    => 'QA Dynamic Binding',
				'slug'    => 'qa-dynamic-binding',
				'search_debounce_ms' => 375,
				'filters' => [
					FilterPresets::blank_filter(
						[
							'enabled'               => true,
							'label'                 => 'Budget',
							'type'                  => 'range',
							'field_binding_dynamic' => $dynamic_text,
							'range_min'             => 0,
							'range_max'             => 500,
						]
					),
				],
			]
		);
		$preset = FilterPresets::get( $preset_id );
		$filter = $preset['filters'][0] ?? [];
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Preset stores raw dynamic binding', ! empty( $filter['field_binding_dynamic'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Preset stores resolved dynamic key', 'qa_budget' === ( $filter['resolved_key'] ?? '' ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Preset stores dynamic key source', FieldBindingResolver::SOURCE_DYNAMIC_BINDING === ( $filter['key_source'] ?? '' ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-011', 'Preset stores Search debounce setting', 375 === ( $preset['search_debounce_ms'] ?? 0 ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-011', 'Preset import restores Search debounce setting', 375 === ( FilterSettings::preset_to_widget_settings( $preset )['search_debounce_ms'] ?? 0 ) );

		$mapped = FilterSettings::map_preset_filters_to_widget_filters( $preset['filters'] ?? [] );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Preset import restores Elementor __dynamic__ field binding', ! empty( $mapped[0]['__dynamic__']['field_binding'] ) );

		$radio_preset_id = FilterPresets::save(
			[
				'name'    => 'QA Radio All Option',
				'slug'    => 'qa-radio-all-option',
				'filters' => [
					FilterPresets::blank_filter(
						[
							'enabled'         => true,
							'label'           => 'Tier',
							'type'            => 'radio',
							'key'             => 'qa_select',
							'options'         => "premium|Premium\nstandard|Standard",
							'radio_show_all'  => true,
							'radio_all_label' => 'All tiers',
						]
					),
				],
			]
		);
		$radio_preset = FilterPresets::get( $radio_preset_id );
		$radio_filter = $radio_preset['filters'][0] ?? [];
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-014', 'Preset stores Radio all option settings', ! empty( $radio_filter['radio_show_all'] ) && 'All tiers' === ( $radio_filter['radio_all_label'] ?? '' ) );
		$radio_mapped = FilterSettings::map_preset_filters_to_widget_filters( $radio_preset['filters'] ?? [] );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-014', 'Preset import restores Radio all option settings', 'yes' === ( $radio_mapped[0]['radio_show_all'] ?? '' ) && 'All tiers' === ( $radio_mapped[0]['radio_all_label'] ?? '' ) );

		$post_id = wp_insert_post(
			[
				'post_type'   => $post_type,
				'post_title'  => 'QA Alpha',
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			eit_fc_fail( 'TEST-FC-ROBUSTNESS-005', 'Fixture post insert', [ 'error' => $post_id->get_error_message() ] );
		} else {
			update_post_meta( $post_id, 'qa_text', 'blue cotton' );
			update_post_meta( $post_id, 'qa_budget', '125' );
			update_post_meta( $post_id, 'qa_date', '2026-03-15' );
			update_post_meta( $post_id, 'qa_select', 'premium' );
			update_post_meta( $post_id, 'qa_private', '125' );

			$term_slug = '';

			if ( '' !== $taxonomy ) {
				$term_slug = 'qa-term-' . wp_generate_password( 6, false, false );
				$term = wp_insert_term( 'QA Term', $taxonomy, [ 'slug' => $term_slug ] );

				if ( ! is_wp_error( $term ) ) {
					$term_id = absint( $term['term_id'] );
					wp_set_object_terms( $post_id, [ $term_slug ], $taxonomy );
				}
			}

			$resolver = new FilterResolver();
			$base_payload = [
				'items'   => [
					[
						'clientId'      => 'qa-item',
						'originalIndex' => 0,
						'postId'        => $post_id,
						'title'         => 'QA Alpha',
						'text'          => 'visible red sample',
						'classes'       => [ 'qa-card' ],
						'data'          => [
							'color' => 'blue',
							'sort'  => '2',
							'price' => '10',
						],
					],
				],
				'perPage' => 12,
			];

			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Visible text filter matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'search', 'key' => '', 'value' => 'visible red' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Data attribute filter matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'radio', 'key' => 'color', 'value' => 'blue' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Registered meta range matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'range', 'key' => 'qa_budget', 'value' => [ 'min' => '100', 'max' => '150' ] ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Private meta is not auto-enriched', 0 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'range', 'key' => 'qa_private', 'value' => [ 'min' => '100', 'max' => '150' ] ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Registered meta date matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'date', 'key' => 'qa_date', 'value' => [ 'from' => '2026-03-01', 'to' => '2026-03-31' ] ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Post field post_type matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'radio', 'key' => 'post_type', 'value' => $post_type ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit meta equals compare matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'select', 'key' => 'qa_select', 'source' => 'meta', 'compare' => 'equals', 'dataType' => 'string', 'value' => 'premium' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit meta equals compare rejects mismatch', 0 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'select', 'key' => 'qa_select', 'source' => 'meta', 'compare' => 'equals', 'dataType' => 'string', 'value' => 'standard' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit numeric between compare matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'range', 'key' => 'qa_budget', 'source' => 'meta', 'compare' => 'between', 'dataType' => 'number', 'value' => [ 'min' => '100', 'max' => '150' ] ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit numeric gte compare matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'range', 'key' => 'qa_budget', 'source' => 'meta', 'compare' => 'gte', 'dataType' => 'number', 'value' => '100' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit numeric lte compare rejects high value', 0 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'range', 'key' => 'qa_budget', 'source' => 'meta', 'compare' => 'lte', 'dataType' => 'number', 'value' => '100' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit exists compare matches populated meta', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'select', 'key' => 'qa_text', 'source' => 'meta', 'compare' => 'exists', 'dataType' => 'string', 'value' => '' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit date between compare matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'date', 'key' => 'qa_date', 'source' => 'meta', 'compare' => 'between', 'dataType' => 'date', 'value' => [ 'from' => '2026-03-01', 'to' => '2026-03-31' ] ] ] ] )['total'] );

			if ( '' !== $term_slug ) {
				eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Taxonomy filter matches registered term', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'radio', 'key' => $taxonomy, 'value' => $term_slug ] ] ] )['total'], [ 'taxonomy' => $taxonomy, 'term' => $term_slug ] );
			} else {
				eit_fc_skip( 'TEST-FC-ROBUSTNESS-005', 'Taxonomy filter fixture', [ 'reason' => 'No registered Toolkit taxonomy fixture available.' ] );
			}
		}
	}
} finally {
	if ( $term_id && ! empty( $taxonomy ) ) {
		wp_delete_term( $term_id, $taxonomy );
	}

	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}

	update_option( CptManager::OPTION, $original_definitions, false );
	update_option( FilterPresets::OPTION, $original_presets, false );
}

$sort_items = [
	[
		'clientId'      => 'bravo',
		'originalIndex' => 0,
		'title'         => 'Bravo',
		'text'          => 'Bravo',
		'classes'       => [],
		'data'          => [ 'date' => '2026-01-02', 'sort' => '2', 'rating' => '4', 'price' => '20' ],
	],
	[
		'clientId'      => 'alpha',
		'originalIndex' => 1,
		'title'         => 'Alpha',
		'text'          => 'Alpha',
		'classes'       => [],
		'data'          => [ 'date' => '2026-01-01', 'sort' => '1', 'rating' => '5', 'price' => '10' ],
	],
	[
		'clientId'      => 'charlie',
		'originalIndex' => 2,
		'title'         => 'Charlie',
		'text'          => 'Charlie',
		'classes'       => [],
		'data'          => [ 'date' => '2026-01-03', 'sort' => '3', 'rating' => '3', 'price' => '30' ],
	],
];

$resolver = new FilterResolver();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-009', 'Structured sort compiles custom data option', 'data_price_number_desc' === SortOptions::value_for_item( [ 'source' => 'data', 'key' => 'price', 'data_type' => 'number', 'direction' => 'desc' ] ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-009', 'Title sort asc orders items', 'alpha,bravo,charlie' === eit_fc_ids_for_result( $resolver->resolve( [ 'items' => $sort_items, 'sort' => 'title_asc', 'perPage' => 12 ] ) ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-009', 'Numeric sort asc orders items', 'alpha,bravo,charlie' === eit_fc_ids_for_result( $resolver->resolve( [ 'items' => $sort_items, 'sort' => 'numeric_asc', 'perPage' => 12 ] ) ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-009', 'Rating sort desc orders items', 'alpha,bravo,charlie' === eit_fc_ids_for_result( $resolver->resolve( [ 'items' => $sort_items, 'sort' => 'rating_desc', 'perPage' => 12 ] ) ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-009', 'Custom data sort desc orders items', 'charlie,bravo,alpha' === eit_fc_ids_for_result( $resolver->resolve( [ 'items' => $sort_items, 'sort' => 'data_price_number_desc', 'perPage' => 12 ] ) ) );

eit_fc_skip( 'TEST-FC-ROBUSTNESS-001', 'Elementor multi-click Style cadence QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor screenshots and nuanced multi-click panel flow.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-002', 'Desktop/mobile visual containment screenshots', [ 'owner' => 'Guilherme', 'reason' => 'Requires browser/editor visual hierarchy judgment.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-006', 'Range visual variants screenshots', [ 'owner' => 'Guilherme', 'reason' => 'Requires visual QA for vertical labels, ticks, icon handle feel, and responsive layout.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-007', 'Option filter visual matrix', [ 'owner' => 'Guilherme', 'reason' => 'Type-specific option visuals are not complete enough for a full visual pass.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-008', 'Rating icon visual QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires visual QA for rating icon choice, active state, spacing, and label balance.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-010', 'Elementor fallback warning screenshot path', [ 'owner' => 'Guilherme', 'reason' => 'Requires normal and simulated fallback editor screenshots plus console notes.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-011', 'Search visual and interaction QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend visual QA for icon, clear button, focus state, and typing feel.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-012', 'Select native picker visual QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for closed-field styling, long labels, browser picker behavior, and mobile picker feel.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-013', 'Checkbox visual and keyboard QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for indicator feel, multiple checked states, keyboard focus, counts, wrapping, and mobile layout.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-014', 'Radio visual and keyboard QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for single-choice clarity, segmented mode, All option clearing, keyboard arrows, focus, and mobile wrapping.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-015', 'Chips visual and keyboard QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for compact token feel, multi-select clarity, hidden input keyboard behavior, long labels, scroll row, grid, and mobile wrapping.' ] );

$failures = array_values(
	array_filter(
		$GLOBALS['eit_results'],
		function ( $result ) {
			return 'FAIL' === $result['status'];
		}
	)
);

echo wp_json_encode(
	[
		'summary' => [
			'passed' => count(
				array_filter(
					$GLOBALS['eit_results'],
					function ( $result ) {
						return 'PASS' === $result['status'];
					}
				)
			),
			'failed' => count( $failures ),
			'skipped_human_qa' => count(
				array_filter(
					$GLOBALS['eit_results'],
					function ( $result ) {
						return 'SKIP_HUMAN_QA' === $result['status'];
					}
				)
			),
		],
		'results' => $GLOBALS['eit_results'],
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";

if ( $failures ) {
	exit( 1 );
}
