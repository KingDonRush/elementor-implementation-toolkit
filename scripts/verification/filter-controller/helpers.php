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
use EIT\Elementor\FilterController\Renderers\Types\DateRenderer;
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
			'eit_filter_has_toggle_controls'   => '',
			'eit_filter_has_swatch_controls'   => '',
			'eit_filter_has_search_controls'   => '',
			'eit_filter_has_select_controls'   => '',
			'eit_filter_has_range_controls'    => '',
			'eit_filter_has_date_controls'     => '',
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

function eit_fc_filter_controller_stylesheets() {
	return [
		'assets/css/filter-controller/base.css',
		'assets/css/filter-controller/layout.css',
		'assets/css/filter-controller/fields.css',
		'assets/css/filter-controller/shared/options.css',
		'assets/css/filter-controller/types/rating.css',
		'assets/css/filter-controller/types/toggle-swatch.css',
		'assets/css/filter-controller/types/date.css',
		'assets/css/filter-controller/types/range.css',
		'assets/css/filter-controller/buttons.css',
		'assets/css/filter-controller/meta.css',
		'assets/css/filter-controller/pagination.css',
		'assets/css/filter-controller/state.css',
		'assets/css/filter-controller/responsive.css',
	];
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

	if ( 'assets/css/eit-frontend.css' === $relative_path ) {
		foreach ( eit_fc_filter_controller_stylesheets() as $module_path ) {
			$css .= "\n" . eit_fc_stylesheet_contents( $module_path, $seen );
		}
	}

	return preg_replace_callback(
		'/@import\s+url\("([^"]+)"\);/',
		function ( $matches ) use ( $relative_path, $seen ) {
			$base = dirname( $relative_path );
			return eit_fc_stylesheet_contents( $base . '/' . $matches[1], $seen );
		},
		$css
	);
}

