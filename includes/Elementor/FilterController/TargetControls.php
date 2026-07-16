<?php
/**
 * Provider, preset, and target controls for the Filter Controller widget.
 */

namespace EIT\Elementor\FilterController;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use EIT\Admin\AdminPages;
use EIT\Support\CctFieldCatalog;
use EIT\Support\CctLoopTemplateCatalog;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TargetControls {

	public static function register( Widget_Base $widget ) {
		$widget->start_controls_section(
			'section_target',
			[ 'label' => esc_html__( 'Target Listing', 'elementor-implementation-toolkit' ) ]
		);

		$widget->add_control(
			'data_provider',
			[
				'label'   => esc_html__( 'Data Provider', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'dom',
				'options' => [
					'collection' => esc_html__( 'Published Collection', 'elementor-implementation-toolkit' ),
					'dom' => esc_html__( 'Existing DOM listing', 'elementor-implementation-toolkit' ),
					'cct' => esc_html__( 'Toolkit CCT query', 'elementor-implementation-toolkit' ),
				],
			]
		);

		$widget->add_control(
			'collection_id',
			[
				'label' => esc_html__( 'Collection', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::SELECT,
				'options' => CollectionWidgetBridge::options(),
				'description' => esc_html__( 'Filters, operators, facets, ordering, page size and URL state come from the published System contract.', 'elementor-implementation-toolkit' ),
				'frontend_available' => true,
				'condition' => [ 'data_provider' => 'collection' ],
			]
		);

		$widget->add_control(
			'collection_contract_note',
			[
				'type' => Controls_Manager::RAW_HTML,
				'raw' => esc_html__( 'This compatibility widget controls presentation only. Edit filter behavior in Systems; no field keys or CSS selectors are required here.', 'elementor-implementation-toolkit' ),
				'content_classes' => 'elementor-control-field-description',
				'condition' => [ 'data_provider' => 'collection' ],
			]
		);

		$widget->add_control(
			'cct_type',
			[
				'label'     => esc_html__( 'Content Type', 'elementor-implementation-toolkit' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => CctFieldCatalog::type_options(),
				'condition' => [ 'data_provider' => 'cct' ],
			]
		);

		$widget->add_control(
			'cct_template_id',
			[
				'label'       => esc_html__( 'Loop Item Template', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => CctLoopTemplateCatalog::options(),
				'description' => esc_html__( 'Requires a Loop Item template and target selector. Rich Loop rendering depends on Elementor Pro / Loop Builder.', 'elementor-implementation-toolkit' ),
				'condition'   => [ 'data_provider' => 'cct' ],
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
				'condition' => [ 'data_provider!' => 'collection' ],
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
				'condition'   => [ 'data_provider!' => 'collection', 'configuration_source' => 'preset' ],
			]
		);

		$import_html = current_user_can( AdminPages::CAPABILITY )
			? '<div class="eit-editor-action" data-eit-editor-action><button type="button" class="elementor-button elementor-button-default" data-eit-import-preset>' . esc_html__( 'Import preset as local copy', 'elementor-implementation-toolkit' ) . '</button><div class="eit-editor-action__status" data-eit-action-status aria-live="polite"></div></div>'
			: '<div class="eit-editor-action"><p class="elementor-control-field-description">' . esc_html__( 'Only administrators can import shared presets into local widget controls.', 'elementor-implementation-toolkit' ) . '</p></div>';

		$widget->add_control(
			'preset_import_action',
			[
				'type'      => Controls_Manager::RAW_HTML,
				'raw'       => $import_html,
				'condition' => [ 'data_provider!' => 'collection', 'configuration_source' => 'preset' ],
			]
		);

		$widget->add_control(
			'preset_save_name',
			[
				'label'       => esc_html__( 'New Preset Name', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => esc_html__( 'Listing filters', 'elementor-implementation-toolkit' ),
				'description' => esc_html__( 'Build filters below, then save this widget setup as a reusable preset.', 'elementor-implementation-toolkit' ),
				'condition'   => [ 'data_provider!' => 'collection', 'configuration_source' => 'widget' ],
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
				'condition' => [ 'data_provider!' => 'collection', 'configuration_source' => 'widget' ],
			]
		);

		$save_html = current_user_can( AdminPages::CAPABILITY )
			? '<div class="eit-editor-action eit-editor-save-preset" data-eit-editor-action><button type="button" class="elementor-button elementor-button-default" data-eit-save-preset>' . esc_html__( 'Save current filters as preset', 'elementor-implementation-toolkit' ) . '</button><div class="eit-editor-action__status eit-editor-save-preset__status" data-eit-action-status data-eit-save-preset-status aria-live="polite"></div></div>'
			: '<div class="eit-editor-action eit-editor-save-preset"><p class="elementor-control-field-description">' . esc_html__( 'Only administrators can create global filter presets.', 'elementor-implementation-toolkit' ) . '</p></div>';

		$widget->add_control(
			'preset_save_action',
			[
				'type'      => Controls_Manager::RAW_HTML,
				'raw'       => $save_html,
				'condition' => [ 'data_provider!' => 'collection', 'configuration_source' => 'widget' ],
			]
		);

		$widget->add_control(
			'target_selector',
			[
				'label'              => esc_html__( 'Target Selector', 'elementor-implementation-toolkit' ),
				'type'               => Controls_Manager::TEXT,
				'placeholder'        => '.elementor-element-abc123, .my-listing',
				'description'        => esc_html__( 'Use detection or enter a CSS selector. CCT mode needs a target container for replacement HTML.', 'elementor-implementation-toolkit' ),
				'frontend_available' => true,
				'condition'          => [ 'data_provider!' => 'collection' ],
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
				'condition'          => [ 'data_provider!' => 'collection' ],
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
				'condition'          => [ 'data_provider!' => 'collection' ],
			]
		);

		$widget->add_control(
			'search_debounce_ms',
			[
				'label'              => esc_html__( 'Search Debounce (ms)', 'elementor-implementation-toolkit' ),
				'type'               => Controls_Manager::NUMBER,
				'min'                => 0,
				'max'                => 2000,
				'step'               => 50,
				'default'            => 250,
				'description'        => esc_html__( 'Waits before auto-applying while someone types in Search. Other filters still apply immediately.', 'elementor-implementation-toolkit' ),
				'frontend_available' => true,
				'condition'          => [ 'data_provider!' => 'collection', 'auto_apply' => 'yes' ],
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
				'condition'          => [ 'data_provider!' => 'collection' ],
			]
		);

		$widget->add_control(
			'per_page',
			[
				'label'              => esc_html__( 'Items Per Page', 'elementor-implementation-toolkit' ),
				'type'               => Controls_Manager::NUMBER,
				'min'                => 1,
				'max'                => 48,
				'step'               => 1,
				'default'            => 24,
				'frontend_available' => true,
				'condition'          => [ 'data_provider!' => 'collection' ],
			]
		);

		$widget->end_controls_section();
	}
}
