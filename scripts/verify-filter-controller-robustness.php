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
use EIT\Elementor\FilterController\FilterSettings;
use EIT\Elementor\FilterController\FilterTypeRegistry;
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
			'eit_filter_has_field_controls'   => '',
			'eit_filter_has_option_controls'  => '',
			'eit_filter_has_range_controls'   => '',
			'eit_filter_has_rating_controls'  => '',
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

if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
	eit_fc_fail( 'TEST-FC-ROBUSTNESS-001', 'Elementor loaded', [ 'reason' => 'Elementor is not loaded.' ] );
} else {
	list( $widget, $controls ) = eit_fc_controls();
	$editor_js = file_get_contents( EIT_PATH . 'assets/js/eit-editor.js' );

	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Range section remains registered for editor cadence', isset( $controls['section_range_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Rating section remains registered for editor cadence', isset( $controls['section_rating_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Option section remains registered for editor cadence', isset( $controls['section_option_style'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Field controls remain registered for editor cadence', isset( $controls['field_text_color'] ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Style controls no longer depend on hidden filter flags', false === strpos( file_get_contents( EIT_PATH . 'includes/Elementor/FilterController/StyleControls.php' ), 'eit_filter_has_' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence falls back from empty repeater DOM to widget model', false !== strpos( $editor_js, 'return rows.length ? rows : null;' ) );
	eit_fc_assert( 'TEST-FC-ROBUSTNESS-001', 'Editor cadence controls field input styles separately', false !== strpos( $editor_js, 'styleCadenceControls.fields' ) && false !== strpos( $editor_js, 'eit_filter_has_field_controls' ) );

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

$css = file_get_contents( EIT_PATH . 'assets/css/eit-frontend.css' );
list( $balanced, $balance_detail ) = eit_fc_css_braces_balanced( $css );

eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Frontend CSS braces are balanced', $balanced, [ 'detail' => $balance_detail ] );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Controller form uses grid packing', eit_fc_css_contains( $css, '.eit-filter-controller__form', 'grid-template-columns: repeat(100, minmax(0, 1fr))' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Filter groups can shrink inside grid spans', eit_fc_css_contains( $css, '.eit-filter-group', 'min-width: 0' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-002', 'Inputs are full-width inside containers', eit_fc_css_contains( $css, '.eit-input,\s*.eit-select', 'width: 100%' ) || false !== strpos( $css, ".eit-input,\n.eit-select {\n    width: 100%;" ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-006', 'Range hidden inputs do not reserve visible layout space', false !== strpos( $css, '.eit-range:not(.eit-range--show-inputs) .eit-range__values' ) && false !== strpos( $css, "display: none;\n}" ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-006', 'Range icon visual bounds constrain oversized icons', false !== strpos( $css, '--eit-range-thumb-visual-size: max(' ) && false !== strpos( $css, 'max-width: calc(var(--eit-range-thumb-visual-size)' ) && false !== strpos( $css, 'overflow: hidden;' ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-006', 'Vertical range has alignment variables', false !== strpos( $css, '--eit-range-vertical-alignment' ) && false !== strpos( $css, '--eit-range-vertical-item-alignment' ) );

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

		$mapped = FilterSettings::map_preset_filters_to_widget_filters( $preset['filters'] ?? [] );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Preset import restores Elementor __dynamic__ field binding', ! empty( $mapped[0]['__dynamic__']['field_binding'] ) );

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
eit_fc_skip( 'TEST-FC-ROBUSTNESS-008', 'Rating icon visual QA', [ 'owner' => 'Guilherme', 'reason' => 'Rating still needs icon/star implementation before visual QA can pass.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-010', 'Elementor fallback warning screenshot path', [ 'owner' => 'Guilherme', 'reason' => 'Requires normal and simulated fallback editor screenshots plus console notes.' ] );

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
