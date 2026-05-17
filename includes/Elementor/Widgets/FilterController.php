<?php
/**
 * Parasitic filter controller widget.
 */

namespace EIT\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Repeater;
use Elementor\Widget_Base;
use EIT\Elementor\ElementorIntegration;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterController extends Widget_Base {

	public function get_name() {
		return 'eit-filter-controller';
	}

	public function get_title() {
		return esc_html__( 'Filter Controller', 'elementor-implementation-toolkit' );
	}

	public function get_icon() {
		return 'eicon-filter';
	}

	public function get_categories() {
		return [ ElementorIntegration::CATEGORY ];
	}

	public function get_keywords() {
		return [ 'filter', 'ajax', 'listing', 'search', 'sort', 'pagination', 'jetsmartfilters' ];
	}

	public function get_script_depends() {
		return [ 'eit-frontend' ];
	}

	public function get_style_depends() {
		return [ 'eit-frontend' ];
	}

	public function has_widget_inner_wrapper(): bool {
		return false;
	}

	protected function register_controls() {
		$this->register_target_controls();
		$this->register_filter_controls();
		$this->register_state_controls();
		$this->register_layout_style_controls();
		$this->register_field_style_controls();
		$this->register_option_style_controls();
		$this->register_range_style_controls();
		$this->register_button_style_controls();
		$this->register_chip_style_controls();
		$this->register_pagination_style_controls();
		$this->register_state_style_controls();
	}

	private function register_target_controls() {
		$this->start_controls_section(
			'section_target',
			[
				'label' => esc_html__( 'Target Listing', 'elementor-implementation-toolkit' ),
			]
		);

		$this->add_control(
			'configuration_source',
			[
				'label'   => esc_html__( 'Configuration Source', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'widget',
				'options' => [
					'widget' => esc_html__( 'Widget controls', 'elementor-implementation-toolkit' ),
					'preset' => esc_html__( 'Admin filter preset', 'elementor-implementation-toolkit' ),
				],
			]
		);

		$this->add_control(
			'filter_preset',
			[
				'label'       => esc_html__( 'Filter Preset', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => FilterPresets::options(),
				'description' => esc_html__( 'Create and tune presets in Implementation Toolkit > Filter Presets.', 'elementor-implementation-toolkit' ),
				'condition'   => [
					'configuration_source' => 'preset',
				],
			]
		);

		$this->add_control(
			'target_selector',
			[
				'label'       => esc_html__( 'Target Selector', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '.elementor-element-abc123, .my-listing',
				'description' => esc_html__( 'Use the detected listings helper in the editor, or enter a CSS selector manually.', 'elementor-implementation-toolkit' ),
				'frontend_available' => true,
			]
		);

		$this->add_control(
			'item_selector',
			[
				'label'       => esc_html__( 'Item Selector Override', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '.jet-listing-grid__item, article, .product',
				'description' => esc_html__( 'Optional. Leave empty to let the frontend detect repeated items inside the target.', 'elementor-implementation-toolkit' ),
				'frontend_available' => true,
			]
		);

		$this->add_control(
			'auto_apply',
			[
				'label'        => esc_html__( 'Auto Apply', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'elementor-implementation-toolkit' ),
				'label_off'    => esc_html__( 'No', 'elementor-implementation-toolkit' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'frontend_available' => true,
			]
		);

		$this->add_control(
			'sync_url',
			[
				'label'        => esc_html__( 'Sync URL Parameters', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'elementor-implementation-toolkit' ),
				'label_off'    => esc_html__( 'No', 'elementor-implementation-toolkit' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'frontend_available' => true,
			]
		);

		$this->add_control(
			'per_page',
			[
				'label'   => esc_html__( 'Items Per Page', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 96,
				'step'    => 1,
				'default' => 9,
				'frontend_available' => true,
			]
		);

		$this->end_controls_section();
	}

	private function register_filter_controls() {
		$this->start_controls_section(
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
				'options' => [
					'search'   => esc_html__( 'Search', 'elementor-implementation-toolkit' ),
					'checkbox' => esc_html__( 'Checkboxes', 'elementor-implementation-toolkit' ),
					'radio'    => esc_html__( 'Radio', 'elementor-implementation-toolkit' ),
					'select'   => esc_html__( 'Select', 'elementor-implementation-toolkit' ),
					'chips'    => esc_html__( 'Chips', 'elementor-implementation-toolkit' ),
					'toggle'   => esc_html__( 'Toggle', 'elementor-implementation-toolkit' ),
					'range'    => esc_html__( 'Range / Min Max', 'elementor-implementation-toolkit' ),
					'date'     => esc_html__( 'Date Range', 'elementor-implementation-toolkit' ),
					'swatch'   => esc_html__( 'Swatches', 'elementor-implementation-toolkit' ),
					'rating'   => esc_html__( 'Rating', 'elementor-implementation-toolkit' ),
				],
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

		$this->add_control(
			'filters',
			[
				'label'       => esc_html__( 'Filter Controls', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ label }}} - {{{ type }}}',
				'default'     => [
					[
						'label'       => esc_html__( 'Search', 'elementor-implementation-toolkit' ),
						'type'        => 'search',
						'placeholder' => esc_html__( 'Search products...', 'elementor-implementation-toolkit' ),
					],
					[
						'label'   => esc_html__( 'Category', 'elementor-implementation-toolkit' ),
						'type'    => 'chips',
						'key'     => 'category',
						'options' => "featured|Featured\nstandard|Standard",
					],
					[
						'label'     => esc_html__( 'Budget Range', 'elementor-implementation-toolkit' ),
						'type'      => 'range',
						'key'       => 'price',
						'range_min' => 0,
						'range_max' => 10000,
						'range_step' => 100,
					],
				],
			]
		);

		$this->end_controls_section();
	}

	private function register_state_controls() {
		$this->start_controls_section(
			'section_state',
			[
				'label' => esc_html__( 'State, Sort & Pagination', 'elementor-implementation-toolkit' ),
			]
		);

		$this->add_control(
			'show_result_count',
			[
				'label'        => esc_html__( 'Show Result Count', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'result_count_text',
			[
				'label'   => esc_html__( 'Result Count Text', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( '{count} results', 'elementor-implementation-toolkit' ),
				'condition' => [
					'show_result_count' => 'yes',
				],
			]
		);

		$this->add_control(
			'show_active_chips',
			[
				'label'        => esc_html__( 'Show Active Filter Chips', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'show_sort',
			[
				'label'        => esc_html__( 'Show Sort', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
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

		$this->add_control(
			'sort_options',
			[
				'label'       => esc_html__( 'Sort Options', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 5,
				'default'     => "default|Default\ntitle_asc|Title A-Z\ntitle_desc|Title Z-A\ndate_desc|Newest\nnumeric_asc|Lowest value\nnumeric_desc|Highest value",
				'description' => esc_html__( 'One option per line. Format: value|Label.', 'elementor-implementation-toolkit' ),
				'condition'   => [
					'show_sort' => 'yes',
				],
			]
		);

		$this->add_control(
			'show_apply',
			[
				'label'        => esc_html__( 'Show Apply Button', 'elementor-implementation-toolkit' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			]
		);

		$this->add_control(
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

		$this->add_control(
			'reset_text',
			[
				'label'   => esc_html__( 'Reset Text', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Reset', 'elementor-implementation-toolkit' ),
			]
		);

		$this->add_control(
			'empty_text',
			[
				'label'   => esc_html__( 'Empty State Text', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'No matching items found.', 'elementor-implementation-toolkit' ),
			]
		);

		$this->add_control(
			'pagination_heading',
			[
				'label'     => esc_html__( 'Pagination', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_control(
			'pagination_type',
			[
				'label'   => esc_html__( 'Pagination Type', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'numbers',
				'options' => [
					'numbers'       => esc_html__( 'Numbers', 'elementor-implementation-toolkit' ),
					'prev_next'     => esc_html__( 'Previous / Next', 'elementor-implementation-toolkit' ),
					'numbers_arrows'=> esc_html__( 'Numbers + Arrows', 'elementor-implementation-toolkit' ),
					'none'          => esc_html__( 'None', 'elementor-implementation-toolkit' ),
				],
			]
		);

		$this->add_control(
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

		$this->add_control(
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

		$this->end_controls_section();
	}

	private function register_layout_style_controls() {
		$this->start_controls_section(
			'section_layout_style',
			[
				'label' => esc_html__( 'Layout', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'layout_direction',
			[
				'label'   => esc_html__( 'Direction', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::CHOOSE,
				'default' => 'column',
				'options' => [
					'row' => [
						'title' => esc_html__( 'Horizontal', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-ellipsis-h',
					],
					'column' => [
						'title' => esc_html__( 'Vertical', 'elementor-implementation-toolkit' ),
						'icon'  => 'eicon-editor-list-ul',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller__form' => 'flex-direction: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'layout_gap',
			[
				'label' => esc_html__( 'Gap', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 80,
					],
				],
				'default' => [
					'size' => 16,
					'unit' => 'px',
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller__form' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'group_gap',
			[
				'label' => esc_html__( 'Group Gap', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 48,
					],
				],
				'default' => [
					'size' => 10,
					'unit' => 'px',
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			[
				'name'     => 'controller_background',
				'selector' => '{{WRAPPER}} .eit-filter-controller',
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'controller_border',
				'selector' => '{{WRAPPER}} .eit-filter-controller',
			]
		);

		$this->add_responsive_control(
			'controller_radius',
			[
				'label' => esc_html__( 'Border Radius', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'controller_padding',
			[
				'label' => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', '%' ],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name'     => 'controller_shadow',
				'selector' => '{{WRAPPER}} .eit-filter-controller',
			]
		);

		$this->end_controls_section();
	}

	private function register_field_style_controls() {
		$this->start_controls_section(
			'section_field_style',
			[
				'label' => esc_html__( 'Fields', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .eit-filter-group__label',
			]
		);

		$this->add_control(
			'label_color',
			[
				'label' => esc_html__( 'Label Color', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-filter-group__label' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'field_typography',
				'selector' => '{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select',
			]
		);

		$this->add_control(
			'field_text_color',
			[
				'label' => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'field_background',
			[
				'label' => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'field_border',
				'selector' => '{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select',
			]
		);

		$this->add_responsive_control(
			'field_radius',
			[
				'label' => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors' => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'field_padding',
			[
				'label' => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors' => [
					'{{WRAPPER}} .eit-input, {{WRAPPER}} .eit-select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	private function register_option_style_controls() {
		$this->start_controls_section(
			'section_option_style',
			[
				'label' => esc_html__( 'Options, Chips & Swatches', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'option_typography',
				'selector' => '{{WRAPPER}} .eit-option',
			]
		);

		$this->add_control(
			'option_color',
			[
				'label' => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'option_background',
			[
				'label' => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'option_active_color',
			[
				'label' => esc_html__( 'Active Text', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option:has(input:checked), {{WRAPPER}} .eit-option.is-active' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'option_active_background',
			[
				'label' => esc_html__( 'Active Background', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-option:has(input:checked), {{WRAPPER}} .eit-option.is-active' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'option_border',
				'selector' => '{{WRAPPER}} .eit-option',
			]
		);

		$this->add_responsive_control(
			'option_radius',
			[
				'label' => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors' => [
					'{{WRAPPER}} .eit-option' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'option_padding',
			[
				'label' => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors' => [
					'{{WRAPPER}} .eit-option' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	private function register_range_style_controls() {
		$this->start_controls_section(
			'section_range_style',
			[
				'label' => esc_html__( 'Range & Rating', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'range_track_color',
			[
				'label' => esc_html__( 'Range Track', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-range-input' => 'accent-color: {{VALUE}};',
					'{{WRAPPER}} .eit-rating-option input:checked + span' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'rating_color',
			[
				'label' => esc_html__( 'Rating Color', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-rating-option span' => 'color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	private function register_button_style_controls() {
		$this->start_controls_section(
			'section_button_style',
			[
				'label' => esc_html__( 'Buttons', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .eit-button',
			]
		);

		$this->add_control(
			'button_color',
			[
				'label' => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-button' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'button_background',
			[
				'label' => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-button' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'button_border',
				'selector' => '{{WRAPPER}} .eit-button',
			]
		);

		$this->add_responsive_control(
			'button_radius',
			[
				'label' => esc_html__( 'Radius', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors' => [
					'{{WRAPPER}} .eit-button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'button_padding',
			[
				'label' => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors' => [
					'{{WRAPPER}} .eit-button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	private function register_chip_style_controls() {
		$this->start_controls_section(
			'section_chip_style',
			[
				'label' => esc_html__( 'Active Chips & Count', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'meta_typography',
				'selector' => '{{WRAPPER}} .eit-result-count, {{WRAPPER}} .eit-active-chip',
			]
		);

		$this->add_control(
			'chip_color',
			[
				'label' => esc_html__( 'Chip Text', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-active-chip' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'chip_background',
			[
				'label' => esc_html__( 'Chip Background', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-active-chip' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'count_color',
			[
				'label' => esc_html__( 'Count Text', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-result-count' => 'color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	private function register_pagination_style_controls() {
		$this->start_controls_section(
			'section_pagination_style',
			[
				'label' => esc_html__( 'Pagination', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'pagination_gap',
			[
				'label' => esc_html__( 'Gap', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 40,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eit-pagination' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'pagination_color',
			[
				'label' => esc_html__( 'Text Color', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'pagination_background',
			[
				'label' => esc_html__( 'Background', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'pagination_active_color',
			[
				'label' => esc_html__( 'Active Text', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button.is-active' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'pagination_active_background',
			[
				'label' => esc_html__( 'Active Background', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-page-button.is-active' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	private function register_state_style_controls() {
		$this->start_controls_section(
			'section_state_style',
			[
				'label' => esc_html__( 'Loading, Empty & Motion', 'elementor-implementation-toolkit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'transition_duration',
			[
				'label' => esc_html__( 'Transition Duration', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'ms' => [
						'min' => 0,
						'max' => 900,
					],
				],
				'default' => [
					'size' => 180,
					'unit' => 'ms',
				],
				'selectors' => [
					'{{WRAPPER}} .eit-filter-controller, {{WRAPPER}} .eit-option, {{WRAPPER}} .eit-button, {{WRAPPER}} .eit-active-chip, {{WRAPPER}} .eit-page-button' => 'transition-duration: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'loading_opacity',
			[
				'label' => esc_html__( 'Listing Loading Opacity', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0.1,
						'max' => 1,
						'step' => 0.05,
					],
				],
				'default' => [
					'size' => 0.55,
				],
				'selectors' => [
					'body .eit-target-is-loading' => 'opacity: {{SIZE}};',
				],
			]
		);

		$this->add_control(
			'empty_color',
			[
				'label' => esc_html__( 'Empty Text Color', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eit-empty-state' => 'color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->resolve_preset_settings( $this->get_settings_for_display() );
		$filters  = $this->normalize_filters( $settings['filters'] ?? [] );
		$sort_options = $this->parse_options( $settings['sort_options'] ?? '' );
		$config = [
			'instance'       => $this->get_id(),
			'targetSelector' => sanitize_text_field( $settings['target_selector'] ?? '' ),
			'itemSelector'   => sanitize_text_field( $settings['item_selector'] ?? '' ),
			'autoApply'      => ( $settings['auto_apply'] ?? 'yes' ) === 'yes',
			'syncUrl'        => ( $settings['sync_url'] ?? 'yes' ) === 'yes',
			'perPage'        => max( 1, min( 96, absint( $settings['per_page'] ?? 9 ) ) ),
			'paginationType' => sanitize_key( $settings['pagination_type'] ?? 'numbers' ),
			'previousText'   => sanitize_text_field( $settings['previous_text'] ?? __( 'Previous', 'elementor-implementation-toolkit' ) ),
			'nextText'       => sanitize_text_field( $settings['next_text'] ?? __( 'Next', 'elementor-implementation-toolkit' ) ),
			'emptyText'      => sanitize_text_field( $settings['empty_text'] ?? __( 'No matching items found.', 'elementor-implementation-toolkit' ) ),
			'resultText'     => sanitize_text_field( $settings['result_count_text'] ?? __( '{count} results', 'elementor-implementation-toolkit' ) ),
			'showResultCount'=> ( $settings['show_result_count'] ?? 'yes' ) === 'yes',
			'showActiveChips'=> ( $settings['show_active_chips'] ?? 'yes' ) === 'yes',
		];

		$this->add_render_attribute(
			'wrapper',
			[
				'class' => 'eit-filter-controller',
				'data-eit-instance' => $this->get_id(),
				'data-eit-config' => wp_json_encode( $config ),
				'data-eit-filters' => wp_json_encode( $filters ),
			]
		);

		?>
		<div <?php $this->print_render_attribute_string( 'wrapper' ); ?>>
			<div class="eit-editor-target-helper" hidden></div>
			<form class="eit-filter-controller__form" action="#" method="get">
				<?php foreach ( $filters as $index => $filter ) : ?>
					<?php $this->render_filter( $filter, $index ); ?>
				<?php endforeach; ?>

				<?php if ( ( $settings['show_sort'] ?? 'yes' ) === 'yes' ) : ?>
					<div class="eit-filter-group eit-filter-group--sort">
						<label class="eit-filter-group__label" for="<?php echo esc_attr( $this->get_id() . '-sort' ); ?>">
							<?php echo esc_html( $settings['sort_label'] ?? __( 'Sort by', 'elementor-implementation-toolkit' ) ); ?>
						</label>
						<select id="<?php echo esc_attr( $this->get_id() . '-sort' ); ?>" class="eit-select" data-eit-sort>
							<?php foreach ( $sort_options as $option ) : ?>
								<option value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<div class="eit-filter-actions">
					<?php if ( ( $settings['show_apply'] ?? '' ) === 'yes' ) : ?>
						<button type="submit" class="eit-button eit-button--apply" data-eit-apply>
							<?php echo esc_html( $settings['apply_text'] ?? __( 'Apply filters', 'elementor-implementation-toolkit' ) ); ?>
						</button>
					<?php endif; ?>
					<button type="button" class="eit-button eit-button--reset" data-eit-reset>
						<?php echo esc_html( $settings['reset_text'] ?? __( 'Reset', 'elementor-implementation-toolkit' ) ); ?>
					</button>
				</div>
			</form>

			<div class="eit-filter-controller__meta">
				<?php if ( $config['showResultCount'] ) : ?>
					<div class="eit-result-count" data-eit-result-count aria-live="polite"></div>
				<?php endif; ?>
				<?php if ( $config['showActiveChips'] ) : ?>
					<div class="eit-active-filters" data-eit-active-filters></div>
				<?php endif; ?>
			</div>

			<div class="eit-empty-state" data-eit-empty hidden><?php echo esc_html( $config['emptyText'] ); ?></div>
			<nav class="eit-pagination" data-eit-pagination aria-label="<?php echo esc_attr__( 'Filtered listing pagination', 'elementor-implementation-toolkit' ); ?>"></nav>
		</div>
		<?php
	}

	private function render_filter( array $filter, $index ) {
		$id = $this->get_id() . '-' . $index;
		$type = $filter['type'];
		$key = $filter['key'];
		$name = 'eit-' . $this->get_id() . '-' . $index;
		$options = $filter['options'];
		?>
		<div class="eit-filter-group eit-filter-group--<?php echo esc_attr( $type ); ?>" data-eit-filter-group="<?php echo esc_attr( $filter['id'] ); ?>">
			<?php if ( $filter['showLabel'] ) : ?>
				<div class="eit-filter-group__label"><?php echo esc_html( $filter['label'] ); ?></div>
			<?php endif; ?>

			<?php if ( 'search' === $type ) : ?>
				<input
					id="<?php echo esc_attr( $id ); ?>"
					class="eit-input eit-input--search"
					type="search"
					placeholder="<?php echo esc_attr( $filter['placeholder'] ); ?>"
					data-eit-control
					data-eit-type="search"
					data-eit-key="<?php echo esc_attr( $key ); ?>"
				/>
			<?php elseif ( 'select' === $type ) : ?>
				<select class="eit-select" data-eit-control data-eit-type="select" data-eit-key="<?php echo esc_attr( $key ); ?>">
					<option value=""><?php echo esc_html( $filter['placeholder'] ?: __( 'All', 'elementor-implementation-toolkit' ) ); ?></option>
					<?php foreach ( $options as $option ) : ?>
						<option value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( 'range' === $type ) : ?>
				<div class="eit-range" data-eit-control data-eit-type="range" data-eit-key="<?php echo esc_attr( $key ); ?>">
					<div class="eit-range__values">
						<input class="eit-input eit-range-number" type="number" value="<?php echo esc_attr( $filter['rangeMin'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-min />
						<input class="eit-input eit-range-number" type="number" value="<?php echo esc_attr( $filter['rangeMax'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-max />
					</div>
					<div class="eit-range__sliders">
						<input class="eit-range-input" type="range" value="<?php echo esc_attr( $filter['rangeMin'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-min-slider />
						<input class="eit-range-input" type="range" value="<?php echo esc_attr( $filter['rangeMax'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-max-slider />
					</div>
				</div>
			<?php elseif ( 'date' === $type ) : ?>
				<div class="eit-date-range" data-eit-control data-eit-type="date" data-eit-key="<?php echo esc_attr( $key ); ?>">
					<input class="eit-input" type="date" data-eit-date-from />
					<input class="eit-input" type="date" data-eit-date-to />
				</div>
			<?php elseif ( 'rating' === $type ) : ?>
				<div class="eit-options eit-options--rating" data-eit-options>
					<?php $rating_options = ! empty( $options ) ? $options : $this->default_rating_options(); ?>
					<?php foreach ( $rating_options as $option ) : ?>
						<label class="eit-option eit-rating-option">
							<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $option['value'] ); ?>" data-eit-control data-eit-type="rating" data-eit-key="<?php echo esc_attr( $key ); ?>" />
							<span><?php echo esc_html( $option['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php elseif ( 'toggle' === $type ) : ?>
				<?php $option = $options[0] ?? [ 'value' => 'yes', 'label' => $filter['label'], 'visual' => '' ]; ?>
				<label class="eit-option eit-toggle">
					<input type="checkbox" value="<?php echo esc_attr( $option['value'] ); ?>" data-eit-control data-eit-type="toggle" data-eit-key="<?php echo esc_attr( $key ); ?>" />
					<span class="eit-toggle__switch" aria-hidden="true"></span>
					<span><?php echo esc_html( $option['label'] ); ?></span>
				</label>
			<?php else : ?>
				<div class="eit-options eit-options--<?php echo esc_attr( $type ); ?>" data-eit-options>
					<?php foreach ( $options as $option ) : ?>
						<label class="eit-option eit-option--<?php echo esc_attr( $type ); ?>">
							<input
								type="<?php echo in_array( $type, [ 'radio' ], true ) ? 'radio' : 'checkbox'; ?>"
								name="<?php echo esc_attr( $name ); ?><?php echo in_array( $type, [ 'checkbox', 'chips', 'swatch' ], true ) ? '[]' : ''; ?>"
								value="<?php echo esc_attr( $option['value'] ); ?>"
								data-eit-control
								data-eit-type="<?php echo esc_attr( $type ); ?>"
								data-eit-key="<?php echo esc_attr( $key ); ?>"
							/>
							<?php if ( 'swatch' === $type && $option['visual'] ) : ?>
								<span class="eit-swatch" style="<?php echo esc_attr( $this->get_swatch_style( $option['visual'] ) ); ?>" aria-hidden="true"></span>
							<?php endif; ?>
							<span><?php echo esc_html( $option['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function normalize_filters( array $filters ) {
		$normalized = [];

		foreach ( $filters as $index => $filter ) {
			$type = sanitize_key( $filter['type'] ?? 'search' );
			$key = sanitize_key( $filter['key'] ?? '' );
			$id = sanitize_key( $filter['_id'] ?? 'filter-' . $index );

			if ( ! in_array( $type, [ 'search', 'checkbox', 'radio', 'select', 'chips', 'toggle', 'range', 'date', 'swatch', 'rating' ], true ) ) {
				$type = 'search';
			}

			$normalized[] = [
				'id'          => $id,
				'label'       => sanitize_text_field( $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' ) ),
				'type'        => $type,
				'key'         => $key,
				'placeholder' => sanitize_text_field( $filter['placeholder'] ?? '' ),
				'options'     => $this->parse_options( $filter['options'] ?? '' ),
				'rangeMin'    => is_numeric( $filter['range_min'] ?? null ) ? (float) $filter['range_min'] : 0,
				'rangeMax'    => is_numeric( $filter['range_max'] ?? null ) ? (float) $filter['range_max'] : 100,
				'rangeStep'   => is_numeric( $filter['range_step'] ?? null ) ? (float) $filter['range_step'] : 1,
				'showLabel'   => ( $filter['show_label'] ?? 'yes' ) === 'yes',
			];
		}

		return $normalized;
	}

	private function resolve_preset_settings( array $settings ) {
		if ( 'preset' !== ( $settings['configuration_source'] ?? 'widget' ) || empty( $settings['filter_preset'] ) ) {
			return $settings;
		}

		$preset = FilterPresets::get( $settings['filter_preset'] );

		if ( ! $preset ) {
			return $settings;
		}

		$resolved = $settings;
		$resolved['filters'] = $this->map_preset_filters_to_widget_filters( $preset['filters'] ?? [] );
		$resolved['target_selector'] = ! empty( $settings['target_selector'] ) ? $settings['target_selector'] : ( $preset['target_selector'] ?? '' );
		$resolved['item_selector'] = ! empty( $settings['item_selector'] ) ? $settings['item_selector'] : ( $preset['item_selector'] ?? '' );
		$resolved['auto_apply'] = 'auto' === ( $preset['apply_mode'] ?? 'auto' ) ? 'yes' : '';
		$resolved['show_apply'] = 'button' === ( $preset['apply_mode'] ?? 'auto' ) ? 'yes' : '';
		$resolved['sync_url'] = ! empty( $preset['sync_url'] ) ? 'yes' : '';
		$resolved['per_page'] = $preset['per_page'] ?? ( $settings['per_page'] ?? 9 );
		$resolved['show_result_count'] = ! empty( $preset['show_result_count'] ) ? 'yes' : '';
		$resolved['result_count_text'] = $preset['result_count_text'] ?? ( $settings['result_count_text'] ?? '' );
		$resolved['show_active_chips'] = ! empty( $preset['show_active_chips'] ) ? 'yes' : '';
		$resolved['show_sort'] = ! empty( $preset['show_sort'] ) ? 'yes' : '';
		$resolved['sort_label'] = $preset['sort_label'] ?? ( $settings['sort_label'] ?? '' );
		$resolved['sort_options'] = $preset['sort_options'] ?? ( $settings['sort_options'] ?? '' );
		$resolved['apply_text'] = $preset['apply_text'] ?? ( $settings['apply_text'] ?? '' );
		$resolved['reset_text'] = $preset['reset_text'] ?? ( $settings['reset_text'] ?? '' );
		$resolved['empty_text'] = $preset['empty_text'] ?? ( $settings['empty_text'] ?? '' );
		$resolved['pagination_type'] = $preset['pagination_type'] ?? ( $settings['pagination_type'] ?? 'numbers' );
		$resolved['previous_text'] = $preset['previous_text'] ?? ( $settings['previous_text'] ?? '' );
		$resolved['next_text'] = $preset['next_text'] ?? ( $settings['next_text'] ?? '' );

		return $resolved;
	}

	private function map_preset_filters_to_widget_filters( array $filters ) {
		$mapped = [];

		foreach ( $filters as $index => $filter ) {
			if ( empty( $filter['enabled'] ) ) {
				continue;
			}

			$mapped[] = [
				'_id'         => sanitize_key( $filter['key'] ?? 'preset-filter-' . $index ) ?: 'preset-filter-' . $index,
				'label'       => $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' ),
				'type'        => $filter['type'] ?? 'search',
				'key'         => $filter['key'] ?? '',
				'placeholder' => $filter['placeholder'] ?? '',
				'options'     => $filter['options'] ?? '',
				'range_min'   => $filter['range_min'] ?? 0,
				'range_max'   => $filter['range_max'] ?? 100,
				'range_step'  => $filter['range_step'] ?? 1,
				'show_label'  => ! empty( $filter['show_label'] ) ? 'yes' : '',
			];
		}

		return $mapped;
	}

	private function parse_options( $raw ) {
		$options = [];
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			$value = sanitize_title( $parts[0] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			$options[] = [
				'value'  => $value,
				'label'  => sanitize_text_field( $parts[1] ?? $parts[0] ),
				'visual' => sanitize_text_field( $parts[2] ?? '' ),
			];
		}

		return $options;
	}

	private function default_rating_options() {
		return [
			[ 'value' => '5', 'label' => '5 stars', 'visual' => '' ],
			[ 'value' => '4', 'label' => '4+ stars', 'visual' => '' ],
			[ 'value' => '3', 'label' => '3+ stars', 'visual' => '' ],
			[ 'value' => '2', 'label' => '2+ stars', 'visual' => '' ],
			[ 'value' => '1', 'label' => '1+ star', 'visual' => '' ],
		];
	}

	private function get_swatch_style( $visual ) {
		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $visual ) ) {
			return 'background-color:' . $visual . ';';
		}

		if ( filter_var( $visual, FILTER_VALIDATE_URL ) ) {
			return 'background-image:url(' . esc_url_raw( $visual ) . ');';
		}

		return '';
	}
}
