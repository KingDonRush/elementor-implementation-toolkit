<?php
/**
 * Content-tab controls for the Elementor Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Widget_Base;
use EIT\Admin\AdminPages;
use EIT\Support\FilterPresets;
use EIT\Support\SortOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentControls {

	public static function register( Widget_Base $widget ) {
		self::register_target_controls( $widget );
		self::register_filter_controls( $widget );
		self::register_sort_controls( $widget );
		self::register_state_controls( $widget );
	}

	private static function register_target_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_target',
			[
				'label' => esc_html__( 'Target Listing', 'elementor-implementation-toolkit' ),
			]
		);

		$widget->add_control(
			'configuration_source',
			[
					'label'   => esc_html__( 'Configuration Source', 'elementor-implementation-toolkit' ),
					'type'    => Controls_Manager::SELECT,
					'default' => 'widget',
					'options' => [
						'widget' => esc_html__( 'Local widget controls', 'elementor-implementation-toolkit' ),
						'preset' => esc_html__( 'Link shared preset', 'elementor-implementation-toolkit' ),
					],
				]
			);

		$widget->add_control(
			'filter_preset',
			[
					'label'       => esc_html__( 'Filter Preset', 'elementor-implementation-toolkit' ),
					'type'        => Controls_Manager::SELECT,
					'default'     => '',
					'options'     => FilterPresets::options(),
					'description' => esc_html__( 'Linked presets load shared filter behavior. Use import when this widget needs an editable local copy.', 'elementor-implementation-toolkit' ),
					'condition'   => [
						'configuration_source' => 'preset',
					],
				]
			);

		$import_preset_raw = current_user_can( AdminPages::CAPABILITY )
			? '<div class="eit-editor-action" data-eit-editor-action><button type="button" class="elementor-button elementor-button-default" data-eit-import-preset>' . esc_html__( 'Import preset as local copy', 'elementor-implementation-toolkit' ) . '</button><div class="eit-editor-action__status" data-eit-action-status aria-live="polite"></div></div>'
			: '<div class="eit-editor-action"><p class="elementor-control-field-description">' . esc_html__( 'Only administrators can import shared presets into local widget controls.', 'elementor-implementation-toolkit' ) . '</p></div>';

		$widget->add_control(
			'preset_import_action',
			[
				'type'      => Controls_Manager::RAW_HTML,
				'raw'       => $import_preset_raw,
				'condition' => [
					'configuration_source' => 'preset',
				],
			]
		);

		$widget->add_control(
			'preset_save_name',
			[
				'label'       => esc_html__( 'New Preset Name', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => esc_html__( 'Shop filters', 'elementor-implementation-toolkit' ),
				'description' => esc_html__( 'Build filters below, then save this widget setup as a reusable preset.', 'elementor-implementation-toolkit' ),
				'condition'   => [
					'configuration_source' => 'widget',
				],
			]
		);

		$widget->add_control(
			'preset_save_behavior',
			[
				'label'     => esc_html__( 'After Save', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'link',
				'options'   => [
					'link'   => esc_html__( 'Save and link this widget', 'elementor-implementation-toolkit' ),
					'detach' => esc_html__( 'Save only', 'elementor-implementation-toolkit' ),
				],
				'condition' => [
					'configuration_source' => 'widget',
				],
			]
		);

		$save_preset_raw = current_user_can( AdminPages::CAPABILITY )
			? '<div class="eit-editor-action eit-editor-save-preset" data-eit-editor-action><button type="button" class="elementor-button elementor-button-default" data-eit-save-preset>' . esc_html__( 'Save current filters as preset', 'elementor-implementation-toolkit' ) . '</button><div class="eit-editor-action__status eit-editor-save-preset__status" data-eit-action-status data-eit-save-preset-status aria-live="polite"></div></div>'
			: '<div class="eit-editor-action eit-editor-save-preset"><p class="elementor-control-field-description">' . esc_html__( 'Only administrators can create global filter presets.', 'elementor-implementation-toolkit' ) . '</p></div>';

		$widget->add_control(
			'preset_save_action',
			[
				'type'      => Controls_Manager::RAW_HTML,
				'raw'       => $save_preset_raw,
				'condition' => [
					'configuration_source' => 'widget',
				],
			]
		);

		$widget->add_control(
			'target_selector',
			[
				'label'              => esc_html__( 'Target Selector', 'elementor-implementation-toolkit' ),
				'type'               => Controls_Manager::TEXT,
				'placeholder'        => '.elementor-element-abc123, .my-listing',
				'description'        => esc_html__( 'Use the detected listings helper in the editor, or enter a CSS selector manually.', 'elementor-implementation-toolkit' ),
				'frontend_available' => true,
			]
		);

		$widget->add_control(
			'item_selector',
			[
				'label'              => esc_html__( 'Item Selector Override', 'elementor-implementation-toolkit' ),
				'type'               => Controls_Manager::TEXT,
				'placeholder'        => '.jet-listing-grid__item, article, .product',
				'description'        => esc_html__( 'Optional. Leave empty to let the frontend detect repeated items inside the target.', 'elementor-implementation-toolkit' ),
				'frontend_available' => true,
			]
		);

		$widget->add_control(
			'auto_apply',
			[
				'label'              => esc_html__( 'Auto Apply', 'elementor-implementation-toolkit' ),
				'type'               => Controls_Manager::SWITCHER,
				'label_on'           => esc_html__( 'Yes', 'elementor-implementation-toolkit' ),
				'label_off'          => esc_html__( 'No', 'elementor-implementation-toolkit' ),
				'return_value'       => 'yes',
				'default'            => 'yes',
				'frontend_available' => true,
			]
		);

		$widget->add_control(
			'sync_url',
			[
				'label'              => esc_html__( 'Sync URL Parameters', 'elementor-implementation-toolkit' ),
				'type'               => Controls_Manager::SWITCHER,
				'label_on'           => esc_html__( 'Yes', 'elementor-implementation-toolkit' ),
				'label_off'          => esc_html__( 'No', 'elementor-implementation-toolkit' ),
				'return_value'       => 'yes',
				'default'            => 'yes',
				'frontend_available' => true,
			]
		);

		$widget->add_control(
			'per_page',
			[
				'label'              => esc_html__( 'Items Per Page', 'elementor-implementation-toolkit' ),
				'type'               => Controls_Manager::NUMBER,
				'min'                => 1,
				'max'                => 96,
				'step'               => 1,
				'default'            => 9,
				'frontend_available' => true,
			]
		);

		$widget->end_controls_section();
	}

	private static function register_filter_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_filters',
			[
				'label' => esc_html__( 'Filters', 'elementor-implementation-toolkit' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'label',
			[
				'label'   => esc_html__( 'Label', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Filter', 'elementor-implementation-toolkit' ),
			]
		);

		$repeater->add_control(
			'type',
			[
				'label'   => esc_html__( 'Type', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'search',
				'options' => FilterTypes::labels(),
			]
		);

		$repeater->add_control(
			'key',
			[
				'label'       => esc_html__( 'Data Key', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'category, price, material, rating',
				'description' => esc_html__( 'Matches data-eit-{key}, data-{key}, taxonomy slugs, classes, or visible text fallback.', 'elementor-implementation-toolkit' ),
				'condition'   => [
					'type!' => 'search',
				],
			]
		);

		$repeater->add_control(
			'placeholder',
			[
				'label'     => esc_html__( 'Placeholder', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Search...', 'elementor-implementation-toolkit' ),
				'condition' => [
					'type' => [ 'search', 'select' ],
				],
			]
		);

		$repeater->add_control(
			'options',
			[
				'label'       => esc_html__( 'Options', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 6,
				'placeholder' => "value|Label|#14b8a6\npremium|Premium\nfast|Fast delivery",
				'description' => esc_html__( 'One option per line. Format: value|Label|optional color or image URL.', 'elementor-implementation-toolkit' ),
				'condition'   => [
					'type' => [ 'checkbox', 'radio', 'select', 'chips', 'toggle', 'swatch', 'rating' ],
				],
			]
		);

		$repeater->add_control(
			'range_min',
			[
				'label'     => esc_html__( 'Range Min', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 0,
				'condition' => [
					'type' => 'range',
				],
			]
		);

		$repeater->add_control(
			'range_max',
			[
				'label'     => esc_html__( 'Range Max', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 100,
				'condition' => [
					'type' => 'range',
				],
			]
		);

		$repeater->add_control(
			'range_step',
			[
				'label'     => esc_html__( 'Range Step', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 1,
				'condition' => [
					'type' => 'range',
				],
			]
		);

		$repeater->add_control(
			'show_label',
			[
				'label'        => esc_html__( 'Show Label', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$widget->add_control(
			'filters',
			[
				'label'         => esc_html__( 'Filter Controls', 'elementor-implementation-toolkit' ),
				'type'          => Controls_Manager::REPEATER,
				'fields'        => $repeater->get_controls(),
				'title_field'   => '{{{ label }}} - {{{ type }}}',
				'default'       => FilterTypes::default_widget_filters(),
				'prevent_empty' => false,
			]
		);

		self::register_filter_type_state_controls( $widget );

		$widget->end_controls_section();
	}

	private static function register_sort_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_sort',
			[
				'label' => esc_html__( 'Sort', 'elementor-implementation-toolkit' ),
			]
		);

		$widget->add_control(
			'show_sort',
			[
				'label'        => esc_html__( 'Show Sort', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$widget->add_control(
			'sort_label',
			[
				'label'     => esc_html__( 'Sort Label', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Sort by', 'elementor-implementation-toolkit' ),
				'condition' => [
					'show_sort' => 'yes',
				],
			]
		);

		$sort_repeater = new Repeater();

		$sort_repeater->add_control(
			'label',
			[
				'label'   => esc_html__( 'Option Label', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Sort option', 'elementor-implementation-toolkit' ),
			]
		);

		$sort_repeater->add_control(
			'source',
			[
				'label'   => esc_html__( 'Sort Source', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'title',
				'options' => SortOptions::source_options(),
			]
		);

		$sort_repeater->add_control(
			'key',
			[
				'label'       => esc_html__( 'Data Key', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'price, rating, stock, sort',
				'description' => esc_html__( 'Matches data-eit-{key} or data-{key} collected from each listing item.', 'elementor-implementation-toolkit' ),
				'condition'   => [
					'source' => [ 'numeric', 'rating', 'data' ],
				],
			]
		);

		$sort_repeater->add_control(
			'data_type',
			[
				'label'     => esc_html__( 'Data Type', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'text',
				'options'   => SortOptions::data_type_options(),
				'condition' => [
					'source' => 'data',
				],
			]
		);

		$sort_repeater->add_control(
			'direction',
			[
				'label'     => esc_html__( 'Direction', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'asc',
				'options'   => SortOptions::direction_options(),
				'condition' => [
					'source!' => 'default',
				],
			]
		);

		$widget->add_control(
			'sort_options_items',
			[
				'label'         => esc_html__( 'Sort Options', 'elementor-implementation-toolkit' ),
				'type'          => Controls_Manager::REPEATER,
				'fields'        => $sort_repeater->get_controls(),
				'title_field'   => '{{{ label }}}',
				'default'       => SortOptions::default_widget_items(),
				'prevent_empty' => false,
				'condition'     => [
					'show_sort' => 'yes',
				],
			]
		);

		$widget->add_control(
			'sort_show_legacy_options',
			[
				'label'        => esc_html__( 'Show Legacy Sort Lines', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'separator'    => 'before',
				'condition'    => [
					'show_sort' => 'yes',
				],
			]
		);

		$widget->add_control(
			'sort_options',
			[
				'label'       => esc_html__( 'Legacy Sort Lines', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 5,
				'default'     => SortOptions::default_lines(),
				'description' => esc_html__( 'Advanced fallback. One option per line: value|Label.', 'elementor-implementation-toolkit' ),
				'condition'   => [
					'show_sort'                => 'yes',
					'sort_show_legacy_options' => 'yes',
				],
			]
		);

		$widget->end_controls_section();
	}

	private static function register_filter_type_state_controls( Widget_Base $widget ) {
		$defaults = [
			'eit_filter_has_field_controls'  => 'yes',
			'eit_filter_has_option_controls' => 'yes',
			'eit_filter_has_range_controls'  => 'yes',
			'eit_filter_has_rating_controls' => '',
		];

		foreach ( $defaults as $control_id => $default ) {
			$widget->add_control(
				$control_id,
				[
					'type'    => Controls_Manager::HIDDEN,
					'default' => $default,
				]
			);
		}
	}

	private static function register_state_controls( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_state',
			[
				'label' => esc_html__( 'State & Pagination', 'elementor-implementation-toolkit' ),
			]
		);

		$widget->add_control(
			'show_result_count',
			[
				'label'        => esc_html__( 'Show Result Count', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$widget->add_control(
			'result_count_text',
			[
				'label'     => esc_html__( 'Result Count Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( '{count} results', 'elementor-implementation-toolkit' ),
				'condition' => [
					'show_result_count' => 'yes',
				],
			]
		);

		$widget->add_control(
			'show_active_chips',
			[
				'label'        => esc_html__( 'Show Active Filter Chips', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$widget->add_control(
			'show_apply',
			[
				'label'        => esc_html__( 'Show Apply Button', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			]
		);

		$widget->add_control(
			'apply_text',
			[
				'label'     => esc_html__( 'Apply Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Apply filters', 'elementor-implementation-toolkit' ),
				'condition' => [
					'show_apply' => 'yes',
				],
			]
		);

		$widget->add_control(
			'reset_text',
			[
				'label'   => esc_html__( 'Reset Text', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Reset', 'elementor-implementation-toolkit' ),
			]
		);

		$widget->add_control(
			'empty_text',
			[
				'label'   => esc_html__( 'Empty State Text', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'No matching items found.', 'elementor-implementation-toolkit' ),
			]
		);

		$widget->add_control(
			'pagination_heading',
			[
				'label'     => esc_html__( 'Pagination', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$widget->add_control(
			'pagination_type',
			[
				'label'   => esc_html__( 'Pagination Type', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'numbers',
				'options' => [
					'numbers'        => esc_html__( 'Numbers', 'elementor-implementation-toolkit' ),
					'prev_next'      => esc_html__( 'Previous / Next', 'elementor-implementation-toolkit' ),
					'numbers_arrows' => esc_html__( 'Numbers + Arrows', 'elementor-implementation-toolkit' ),
					'none'           => esc_html__( 'None', 'elementor-implementation-toolkit' ),
				],
			]
		);

		$widget->add_control(
			'previous_text',
			[
				'label'     => esc_html__( 'Previous Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Previous', 'elementor-implementation-toolkit' ),
				'condition' => [
					'pagination_type' => [ 'prev_next', 'numbers_arrows' ],
				],
			]
		);

		$widget->add_control(
			'next_text',
			[
				'label'     => esc_html__( 'Next Text', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Next', 'elementor-implementation-toolkit' ),
				'condition' => [
					'pagination_type' => [ 'prev_next', 'numbers_arrows' ],
				],
			]
		);

		$widget->end_controls_section();
	}
}
