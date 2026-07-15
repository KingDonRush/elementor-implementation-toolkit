<?php

use EIT\Elementor\FilterController\FilterOptions;
use EIT\Elementor\FilterController\Renderers\Types\ChoiceOptionsRenderer;
use EIT\Elementor\FilterController\Renderers\Types\DateRenderer;
use EIT\Elementor\FilterController\Renderers\Types\SearchRenderer;
use EIT\Elementor\FilterController\Renderers\Types\SelectRenderer;
use EIT\Elementor\FilterController\RuntimeConfig;

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

ob_start();
\EIT\Elementor\FilterController\Renderers\Types\ToggleRenderer::render(
	[
		'label'   => 'Featured only',
		'options' => FilterOptions::parse( "featured|Featured only\nignored|Ignored second value" ),
	],
	'featured'
);
$toggle_markup = ob_get_clean();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-016', 'Toggle renderer emits one native checkbox with explicit on-value contract', false !== strpos( $toggle_markup, 'type="checkbox"' ) && false !== strpos( $toggle_markup, 'data-eit-type="toggle"' ) && false !== strpos( $toggle_markup, 'data-eit-toggle-on-value="featured"' ) && false === strpos( $toggle_markup, 'ignored' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-016', 'Toggle renderer emits switch, on/off state text, visible label, and screen-reader contract', false !== strpos( $toggle_markup, 'eit-toggle__switch' ) && false !== strpos( $toggle_markup, 'eit-toggle__state--off' ) && false !== strpos( $toggle_markup, 'eit-toggle__state--on' ) && false !== strpos( $toggle_markup, 'eit-toggle__label' ) && false !== strpos( $toggle_markup, 'eit-toggle__contract' ) );

$swatch_options = FilterOptions::parse( "blue|Blue|#14b8a6|8\nimage|Texture|https://example.com/texture.png|3\nmystery|Mystery|not-a-visual|1" );
ob_start();
ChoiceOptionsRenderer::render(
	'swatch',
	[
		'options' => $swatch_options,
	],
	'eit-qa-swatch',
	'color'
);
$swatch_markup = ob_get_clean();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-017', 'Swatch renderer keeps grouped checkbox semantics and accessible labels', false !== strpos( $swatch_markup, 'type="checkbox"' ) && false !== strpos( $swatch_markup, 'name="eit-qa-swatch[]"' ) && false !== strpos( $swatch_markup, 'eit-option__label' ) && false !== strpos( $swatch_markup, 'eit-option-count' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-017', 'Swatch renderer emits color/image visuals and invalid visual fallback', false !== strpos( $swatch_markup, 'background-color:#14b8a6' ) && false !== strpos( $swatch_markup, 'background-image:url(https://example.com/texture.png)' ) && false !== strpos( $swatch_markup, 'eit-swatch--fallback' ) && false !== strpos( $swatch_markup, 'eit-swatch__fallback' ) );

ob_start();
DateRenderer::render(
	[
		'label' => 'Publish Date',
	],
	'qa_date'
);
$date_markup = ob_get_clean();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-018', 'Date renderer emits labeled native inputs and clear action', false !== strpos( $date_markup, 'data-eit-date-from' ) && false !== strpos( $date_markup, 'data-eit-date-to' ) && false !== strpos( $date_markup, 'eit-date-range__label' ) && false !== strpos( $date_markup, 'data-eit-date-clear' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-018', 'Date renderer emits invalid status and date-format contract', false !== strpos( $date_markup, 'data-eit-date-status' ) && false !== strpos( $date_markup, 'eit-date-range__contract' ) );

