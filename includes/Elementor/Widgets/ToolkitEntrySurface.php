<?php
/**
 * Elementor Free presentation connector for a governed Entry Surface.
 */

namespace EIT\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Widget_Base;
use EIT\CCT\CurrentItemContext;
use EIT\Elementor\Contracts\PublishedContractCatalog;
use EIT\Elementor\ElementorIntegration;
use EIT\Entry\EntryRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolkitEntrySurface extends Widget_Base {

	public function get_name() {
		return 'eit-toolkit-entry-surface';
	}

	public function get_title() {
		return esc_html__( 'Toolkit Entry Surface', 'elementor-implementation-toolkit' );
	}

	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	public function get_categories() {
		return [ ElementorIntegration::CATEGORY ];
	}

	public function get_keywords() {
		return [ 'form', 'entry', 'create', 'edit', 'workflow' ];
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
		$this->add_control( 'surface_id', [
			'label' => esc_html__( 'Published Entry Surface', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SELECT,
			'options' => ( new PublishedContractCatalog() )->entry_options(),
			'description' => esc_html__( 'Fields, steps, conditions, permissions and actions remain owned by Systems.', 'elementor-implementation-toolkit' ),
		] );
		$this->add_control( 'item_source', [
			'label' => esc_html__( 'Workspace item', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SELECT,
			'default' => 'new',
			'options' => [
				'new' => esc_html__( 'Create a new item', 'elementor-implementation-toolkit' ),
				'current' => esc_html__( 'Current content context', 'elementor-implementation-toolkit' ),
				'url' => esc_html__( 'Validated eit_item URL parameter', 'elementor-implementation-toolkit' ),
			],
		] );
		$this->end_controls_section();

		$this->start_controls_section( 'surface_style', [ 'label' => esc_html__( 'Workspace', 'elementor-implementation-toolkit' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_group_control( Group_Control_Background::get_type(), [ 'name' => 'background', 'selector' => '{{WRAPPER}} .eit-entry-workspace' ] );
		$this->add_group_control( Group_Control_Border::get_type(), [ 'name' => 'border', 'selector' => '{{WRAPPER}} .eit-entry-workspace' ] );
		$this->add_responsive_control( 'padding', [
			'label' => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', 'em', 'rem' ],
			'selectors' => [ '{{WRAPPER}} .eit-entry-workspace' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
		] );
		$this->add_responsive_control( 'field_gap', [
			'label' => esc_html__( 'Field gap', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SLIDER,
			'range' => [ 'px' => [ 'min' => 4, 'max' => 64 ] ],
			'selectors' => [ '{{WRAPPER}} .eit-entry-field' => 'margin-bottom: {{SIZE}}{{UNIT}};' ],
		] );
		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$surface_id = (string) ( $settings['surface_id'] ?? '' );
		$item_id = $this->item_id( $settings['item_source'] ?? 'new' );
		echo ( new EntryRenderer() )->render( $surface_id, $item_id, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EntryRenderer escapes its complete markup.
	}

	private function item_id( $source ) {
		if ( 'current' === $source ) {
			$item = CurrentItemContext::item();
			return absint( $item['id'] ?? get_the_ID() );
		}
		if ( 'url' === $source ) {
			return isset( $_GET['eit_item'] ) ? absint( wp_unslash( $_GET['eit_item'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only identifier; object Policy authorizes the loaded item.
		}
		return 0;
	}
}
