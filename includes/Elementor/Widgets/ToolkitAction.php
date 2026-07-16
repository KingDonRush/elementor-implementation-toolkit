<?php
/**
 * Elementor Free button that delegates to a governed Entry operation.
 */

namespace EIT\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use EIT\Elementor\Contracts\PublishedContractCatalog;
use EIT\Elementor\ElementorIntegration;
use EIT\Entry\EntrySurfaceResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolkitAction extends Widget_Base {

	public function get_name() {
		return 'eit-toolkit-action';
	}

	public function get_title() {
		return esc_html__( 'Toolkit Action', 'elementor-implementation-toolkit' );
	}

	public function get_icon() {
		return 'eicon-button';
	}

	public function get_categories() {
		return [ ElementorIntegration::CATEGORY ];
	}

	public function get_keywords() {
		return [ 'button', 'action', 'publish', 'archive', 'workflow' ];
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
			'label' => esc_html__( 'Entry Surface', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SELECT,
			'options' => ( new PublishedContractCatalog() )->entry_options(),
		] );
		$this->add_control( 'operation', [
			'label' => esc_html__( 'Governed operation', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SELECT,
			'default' => 'update',
			'options' => [
				'create' => esc_html__( 'Save new item', 'elementor-implementation-toolkit' ),
				'update' => esc_html__( 'Save changes', 'elementor-implementation-toolkit' ),
				'submit_review' => esc_html__( 'Submit for review', 'elementor-implementation-toolkit' ),
				'publish' => esc_html__( 'Publish', 'elementor-implementation-toolkit' ),
				'archive' => esc_html__( 'Archive', 'elementor-implementation-toolkit' ),
				'restore' => esc_html__( 'Restore', 'elementor-implementation-toolkit' ),
			],
		] );
		$this->add_control( 'label', [ 'label' => esc_html__( 'Button label', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::TEXT, 'default' => esc_html__( 'Save changes', 'elementor-implementation-toolkit' ) ] );
		$this->end_controls_section();

		$this->start_controls_section( 'button_style', [ 'label' => esc_html__( 'Button', 'elementor-implementation-toolkit' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'typography', 'selector' => '{{WRAPPER}} .eit-toolkit-action' ] );
		$this->add_control( 'text_color', [ 'label' => esc_html__( 'Text color', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .eit-toolkit-action' => 'color: {{VALUE}};' ] ] );
		$this->add_control( 'background_color', [ 'label' => esc_html__( 'Background', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .eit-toolkit-action' => 'background-color: {{VALUE}};' ] ] );
		$this->add_responsive_control( 'padding', [
			'label' => esc_html__( 'Padding', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', 'em', 'rem' ],
			'selectors' => [ '{{WRAPPER}} .eit-toolkit-action' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
		] );
		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$surface_id = (string) ( $settings['surface_id'] ?? '' );
		$operation = sanitize_key( $settings['operation'] ?? '' );
		$contract = ( new EntrySurfaceResolver() )->get( $surface_id );
		if ( ! $contract || ! in_array( $operation, $contract['workflow']['operations'] ?? [], true ) ) {
			$this->render_notice();
			return;
		}
		$intent = in_array( $operation, [ 'create', 'update' ], true ) ? 'default' : $operation;
		printf(
			'<button type="button" class="eit-toolkit-action" aria-controls="eit-entry-%1$s" data-eit-action-surface="%1$s" data-eit-action-intent="%2$s">%3$s</button>',
			esc_attr( $surface_id ),
			esc_attr( $intent ),
			esc_html( $settings['label'] ?? '' )
		);
	}

	private function render_notice() {
		if ( class_exists( '\\Elementor\\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			echo '<div class="eit-connector-notice" role="status">' . esc_html__( 'Choose an operation allowed by the published Entry Surface.', 'elementor-implementation-toolkit' ) . '</div>';
		}
	}
}
