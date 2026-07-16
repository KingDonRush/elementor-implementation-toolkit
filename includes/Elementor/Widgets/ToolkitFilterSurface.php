<?php
/**
 * Elementor Free presentation connector for a compiled Filter Surface.
 */

namespace EIT\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use EIT\Elementor\ElementorIntegration;
use EIT\Elementor\FilterController\CollectionWidgetBridge;
use EIT\Elementor\FilterController\Renderers\ActionButtonsRenderer;
use EIT\Elementor\FilterController\Renderers\FilterRenderer;
use EIT\Elementor\FilterController\Renderers\MetaRenderer;
use EIT\Elementor\FilterController\Renderers\SortRenderer;
use EIT\Elementor\FilterController\RuntimeConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolkitFilterSurface extends Widget_Base {

	public function get_name() {
		return 'eit-toolkit-filter-surface';
	}

	public function get_title() {
		return esc_html__( 'Toolkit Filter Surface', 'elementor-implementation-toolkit' );
	}

	public function get_icon() {
		return 'eicon-filter';
	}

	public function get_categories() {
		return [ ElementorIntegration::CATEGORY ];
	}

	public function get_keywords() {
		return [ 'filter', 'facet', 'sort', 'collection' ];
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
		$this->add_control( 'collection_id', [
			'label' => esc_html__( 'Published Collection', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SELECT,
			'options' => CollectionWidgetBridge::options(),
			'description' => esc_html__( 'Place the matching Toolkit Collection Surface anywhere on this page. No selector is required.', 'elementor-implementation-toolkit' ),
		] );
		$this->add_control( 'show_result_count', [
			'label' => esc_html__( 'Show result count', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default' => 'yes',
		] );
		$this->add_control( 'show_reset', [
			'label' => esc_html__( 'Show reset action', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default' => 'yes',
		] );
		$this->add_control( 'pagination_type', [
			'label' => esc_html__( 'Pagination presentation', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SELECT,
			'default' => 'numbers_arrows',
			'options' => [
				'numbers' => esc_html__( 'Numbers', 'elementor-implementation-toolkit' ),
				'prev_next' => esc_html__( 'Previous and next', 'elementor-implementation-toolkit' ),
				'numbers_arrows' => esc_html__( 'Numbers and arrows', 'elementor-implementation-toolkit' ),
				'none' => esc_html__( 'Hidden', 'elementor-implementation-toolkit' ),
			],
		] );
		$this->end_controls_section();

		$this->start_controls_section( 'layout', [ 'label' => esc_html__( 'Layout', 'elementor-implementation-toolkit' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_responsive_control( 'columns', [
			'label' => esc_html__( 'Columns', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 1,
			'max' => 4,
			'default' => 1,
			'selectors' => [ '{{WRAPPER}} .eit-filter-controller__form' => '--eit-filter-columns: {{VALUE}};' ],
		] );
		$this->add_responsive_control( 'gap', [
			'label' => esc_html__( 'Gap', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SLIDER,
			'range' => [ 'px' => [ 'min' => 0, 'max' => 64 ] ],
			'default' => [ 'size' => 12, 'unit' => 'px' ],
			'selectors' => [ '{{WRAPPER}} .eit-filter-controller__form' => 'gap: {{SIZE}}{{UNIT}};' ],
		] );
		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'label_typography', 'selector' => '{{WRAPPER}} .eit-filter-group__label' ] );
		$this->add_control( 'label_color', [
			'label' => esc_html__( 'Label color', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .eit-filter-group__label' => 'color: {{VALUE}};' ],
		] );
		$this->end_controls_section();
	}

	protected function render() {
		$display = $this->get_settings_for_display();
		$collection_id = (string) ( $display['collection_id'] ?? '' );
		$contract = CollectionWidgetBridge::resolve( $collection_id );
		if ( is_wp_error( $contract ) ) {
			$this->render_error( $contract->get_error_message() );
			return;
		}
		$settings = array_replace( $display, $contract['settings'] );
		$settings['collection_id'] = $collection_id;
		$settings['collection_target_id'] = $collection_id;
		$settings['collection_facet_field_ids'] = $contract['facet_field_ids'];
		$settings['show_result_count'] = $display['show_result_count'] ?? 'yes';
		$settings['show_reset'] = $display['show_reset'] ?? 'yes';
		$settings['pagination_type'] = $display['pagination_type'] ?? 'numbers_arrows';
		$settings['reset_text'] = __( 'Reset', 'elementor-implementation-toolkit' );
		$settings['apply_text'] = __( 'Apply filters', 'elementor-implementation-toolkit' );
		$settings['empty_text'] = __( 'No matching items found.', 'elementor-implementation-toolkit' );
		$config = RuntimeConfig::from_settings( $this->get_id(), $settings );

		$this->add_render_attribute( 'wrapper', [
			'class' => 'eit-filter-controller eit-toolkit-filter-surface',
			'data-eit-instance' => $this->get_id(),
			'data-eit-config' => wp_json_encode( $config ),
			'data-eit-filters' => wp_json_encode( $contract['filters'] ),
		] );
		?>
		<div <?php $this->print_render_attribute_string( 'wrapper' ); ?> aria-busy="false">
			<form class="eit-filter-controller__form" action="#" method="get">
				<?php foreach ( $contract['filters'] as $index => $filter ) : ?>
					<?php FilterRenderer::render( $this->get_id(), $filter, $index, $settings ); ?>
				<?php endforeach; ?>
				<?php SortRenderer::render( $this->get_id(), $settings, $contract['sort_options'] ); ?>
				<?php ActionButtonsRenderer::render( $settings ); ?>
			</form>
			<?php MetaRenderer::render( $config ); ?>
		</div>
		<?php
	}

	private function render_error( $message ) {
		printf( '<div class="eit-connector-notice" role="status">%s</div>', esc_html( $message ) );
	}
}
