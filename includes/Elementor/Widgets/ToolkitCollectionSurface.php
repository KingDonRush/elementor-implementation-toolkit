<?php
/**
 * Elementor Free presentation connector for a published Collection.
 */

namespace EIT\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Widget_Base;
use EIT\Collection\CollectionRenderer;
use EIT\Elementor\ElementorIntegration;
use EIT\Elementor\FilterController\CollectionWidgetBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolkitCollectionSurface extends Widget_Base {

	public function get_name() {
		return 'eit-toolkit-collection-surface';
	}

	public function get_title() {
		return esc_html__( 'Toolkit Collection Surface', 'elementor-implementation-toolkit' );
	}

	public function get_icon() {
		return 'eicon-posts-grid';
	}

	public function get_categories() {
		return [ ElementorIntegration::CATEGORY ];
	}

	public function get_keywords() {
		return [ 'collection', 'listing', 'results', 'query' ];
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
		$this->start_controls_section( 'connection', [ 'label' => esc_html__( 'Connection', 'elementor-implementation-toolkit' ) ] );
		$this->add_control( 'connection_status', [
			'type' => Controls_Manager::RAW_HTML,
			'raw' => '<div class="eit-connector-status" data-eit-collection-pair-status role="status" aria-live="polite">'
				. esc_html__( 'This Surface owns the Collection contract for connected filters.', 'elementor-implementation-toolkit' )
				. '</div>',
		] );
		$this->add_control( 'collection_id', [
			'label' => esc_html__( 'Published Collection', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SELECT,
			'options' => CollectionWidgetBridge::options(),
			'description' => esc_html__( 'Query, projection, access, pagination and caching remain owned by Systems.', 'elementor-implementation-toolkit' ),
		] );
		$this->end_controls_section();

		$this->start_controls_section( 'layout', [ 'label' => esc_html__( 'Cards', 'elementor-implementation-toolkit' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_responsive_control( 'columns', [
			'label' => esc_html__( 'Columns', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 1,
			'max' => 6,
			'default' => 1,
			'tablet_default' => 1,
			'mobile_default' => 1,
			'selectors' => [ '{{WRAPPER}} .eit-collection-items' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ],
		] );
		$this->add_responsive_control( 'gap', [
			'label' => esc_html__( 'Gap', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SLIDER,
			'range' => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
			'default' => [ 'size' => 16, 'unit' => 'px' ],
			'selectors' => [ '{{WRAPPER}} .eit-collection-items' => 'gap: {{SIZE}}{{UNIT}};' ],
		] );
		$this->add_group_control( Group_Control_Background::get_type(), [ 'name' => 'card_background', 'selector' => '{{WRAPPER}} .eit-collection-item' ] );
		$this->add_group_control( Group_Control_Border::get_type(), [ 'name' => 'card_border', 'selector' => '{{WRAPPER}} .eit-collection-item' ] );
		$this->add_responsive_control( 'card_padding', [
			'label' => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', 'em', 'rem' ],
			'selectors' => [ '{{WRAPPER}} .eit-collection-item' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
		] );
		$this->add_control( 'card_radius', [
			'label' => esc_html__( 'Corner radius', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', '%' ],
			'selectors' => [ '{{WRAPPER}} .eit-collection-item' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
		] );
		$this->end_controls_section();
	}

	protected function render() {
		$collection_id = (string) $this->get_settings_for_display( 'collection_id' );
		$output = ( new CollectionRenderer() )->render( $collection_id );
		if ( '' !== $output ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns escaped Toolkit markup.
			return;
		}
		if ( class_exists( '\\Elementor\\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			echo '<div class="eit-connector-notice" role="status">' . esc_html__( 'Select an available Collection or review its access Policy.', 'elementor-implementation-toolkit' ) . '</div>';
		}
	}
}
