<?php
/**
 * Elementor Free connector for one stable Toolkit Field.
 */

namespace EIT\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use EIT\Elementor\Contracts\PublishedContractCatalog;
use EIT\Elementor\DynamicTags\TypedValueResolver;
use EIT\Elementor\ElementorIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolkitField extends Widget_Base {

	public function get_name() {
		return 'eit-toolkit-field';
	}

	public function get_title() {
		return esc_html__( 'Toolkit Field', 'elementor-implementation-toolkit' );
	}

	public function get_icon() {
		return 'eicon-database';
	}

	public function get_categories() {
		return [ ElementorIntegration::CATEGORY ];
	}

	public function get_keywords() {
		return [ 'field', 'dynamic', 'data', 'content' ];
	}

	public function get_style_depends() {
		return [ 'eit-frontend' ];
	}

	public function has_widget_inner_wrapper(): bool {
		return false;
	}

	protected function register_controls() {
		$this->start_controls_section( 'content', [ 'label' => esc_html__( 'Connection', 'elementor-implementation-toolkit' ) ] );
		$this->add_control( 'field_id', [
			'label' => esc_html__( 'Published Field', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SELECT,
			'options' => ( new PublishedContractCatalog() )->field_options(),
			'description' => esc_html__( 'The stable Field ID remains connected when its public name changes.', 'elementor-implementation-toolkit' ),
		] );
		$this->add_control( 'html_tag', [
			'label' => esc_html__( 'HTML element', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SELECT,
			'default' => 'div',
			'options' => array_combine( [ 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], [ 'div', 'span', 'p', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ] ),
		] );
		$this->add_control( 'prefix', [ 'label' => esc_html__( 'Prefix', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::TEXT ] );
		$this->add_control( 'suffix', [ 'label' => esc_html__( 'Suffix', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::TEXT ] );
		$this->add_control( 'link_value', [
			'label' => esc_html__( 'Link URL-compatible values', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default' => 'yes',
		] );
		$this->end_controls_section();

		$this->start_controls_section( 'style', [ 'label' => esc_html__( 'Value', 'elementor-implementation-toolkit' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_control( 'text_color', [
			'label' => esc_html__( 'Color', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .eit-toolkit-field__value' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'typography', 'selector' => '{{WRAPPER}} .eit-toolkit-field__value' ] );
		$this->add_responsive_control( 'alignment', [
			'label' => esc_html__( 'Alignment', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::CHOOSE,
			'options' => [
				'left' => [ 'title' => esc_html__( 'Left', 'elementor-implementation-toolkit' ), 'icon' => 'eicon-text-align-left' ],
				'center' => [ 'title' => esc_html__( 'Center', 'elementor-implementation-toolkit' ), 'icon' => 'eicon-text-align-center' ],
				'right' => [ 'title' => esc_html__( 'Right', 'elementor-implementation-toolkit' ), 'icon' => 'eicon-text-align-right' ],
			],
			'selectors' => [ '{{WRAPPER}} .eit-toolkit-field' => 'text-align: {{VALUE}};' ],
		] );
		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$field_id = (string) ( $settings['field_id'] ?? '' );
		$resolver = new TypedValueResolver();
		$context = $resolver->resolve( $field_id, 'text' );
		if ( ! $context ) {
			$this->render_notice( __( 'Select an available published Field.', 'elementor-implementation-toolkit' ) );
			return;
		}
		$field = $context['field'];
		if ( in_array( 'gallery', $field['elementor'] ?? [], true ) ) {
			$this->render_gallery( $resolver->resolve( $field_id, 'gallery' )['value'] ?? [], $field );
			return;
		}
		if ( in_array( 'image', $field['elementor'] ?? [], true ) ) {
			$this->render_image( $resolver->resolve( $field_id, 'image' )['value'] ?? null, $field );
			return;
		}
		$value = $context['value'];
		if ( null === $value || '' === (string) $value ) {
			return;
		}
		$tag = in_array( $settings['html_tag'] ?? '', [ 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ? $settings['html_tag'] : 'div';
		$url = 'yes' === ( $settings['link_value'] ?? '' ) && in_array( 'url', $field['elementor'] ?? [], true )
			? ( $resolver->resolve( $field_id, 'url' )['value'] ?? '' )
			: '';
		printf( '<%1$s class="eit-toolkit-field"><span class="eit-toolkit-field__value">', esc_attr( $tag ) );
		echo esc_html( $settings['prefix'] ?? '' );
		if ( $url ) {
			printf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $value ) );
		} else {
			echo esc_html( $value );
		}
		echo esc_html( $settings['suffix'] ?? '' );
		printf( '</span></%s>', esc_attr( $tag ) );
	}

	private function render_image( $image, array $field ) {
		if ( ! is_array( $image ) || empty( $image['url'] ) ) {
			return;
		}
		printf( '<figure class="eit-toolkit-field eit-toolkit-field--image"><img src="%1$s" alt="%2$s" loading="lazy"></figure>', esc_url( $image['url'] ), esc_attr( $field['name'] ) );
	}

	private function render_gallery( array $images, array $field ) {
		if ( ! $images ) {
			return;
		}
		echo '<div class="eit-toolkit-field eit-toolkit-field--gallery">';
		foreach ( $images as $image ) {
			if ( ! empty( $image['url'] ) ) {
				printf( '<img src="%1$s" alt="%2$s" loading="lazy">', esc_url( $image['url'] ), esc_attr( $field['name'] ) );
			}
		}
		echo '</div>';
	}

	private function render_notice( $message ) {
		if ( class_exists( '\\Elementor\\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			printf( '<div class="eit-connector-notice" role="status">%s</div>', esc_html( $message ) );
		}
	}
}
