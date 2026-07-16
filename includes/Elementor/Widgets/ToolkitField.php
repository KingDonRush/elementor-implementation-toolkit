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
		$catalog = new PublishedContractCatalog();
		$context_entity_id = $catalog->context_entity_id();
		$this->start_controls_section( 'content', [ 'label' => esc_html__( 'Connection', 'elementor-implementation-toolkit' ) ] );
		if ( '' === $context_entity_id ) {
			$this->add_control( 'entity_id', [
				'label' => esc_html__( 'Entity context', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::SELECT,
				'options' => $catalog->entity_options(),
				'description' => esc_html__( 'Required because the current preview does not identify one Entity unambiguously.', 'elementor-implementation-toolkit' ),
			] );
			$this->add_control( 'eit_field_category', [
				'type' => Controls_Manager::HIDDEN,
				'default' => 'all',
			] );
		}
		$field_control = [
			'label' => esc_html__( 'Published Field', 'elementor-implementation-toolkit' ),
			'type' => Controls_Manager::SELECT,
			'options' => $catalog->editor_field_options( [], $context_entity_id ),
			'description' => esc_html__( 'The stable Field ID remains connected when its public name changes.', 'elementor-implementation-toolkit' ),
		];
		if ( '' === $context_entity_id ) {
			$field_control['condition'] = [ 'entity_id!' => '' ];
		}
		$this->add_control( 'field_id', $field_control );
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
		$entity_id = (string) ( $settings['entity_id'] ?? '' );
		$resolver = new TypedValueResolver();
		$context = $resolver->resolve( $field_id, 'text', $entity_id );
		if ( ! $context ) {
			$this->render_notice( __( 'Select an available published Field.', 'elementor-implementation-toolkit' ) );
			return;
		}
		$field = $context['field'];
		if ( in_array( 'gallery', $field['elementor'] ?? [], true ) ) {
			$this->render_gallery( $resolver->resolve( $field_id, 'gallery', $entity_id )['value'] ?? [], $field );
			return;
		}
		if ( in_array( 'image', $field['elementor'] ?? [], true ) ) {
			$this->render_image( $resolver->resolve( $field_id, 'image', $entity_id )['value'] ?? null, $field );
			return;
		}
		$value = $context['value'];
		if ( null === $value || '' === (string) $value ) {
			return;
		}
		$tag = in_array( $settings['html_tag'] ?? '', [ 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ? $settings['html_tag'] : 'div';
		$url = 'yes' === ( $settings['link_value'] ?? '' ) && in_array( 'url', $field['elementor'] ?? [], true )
			? ( $resolver->resolve( $field_id, 'url', $entity_id )['value'] ?? '' )
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
		if ( ! is_array( $image ) ) {
			return;
		}
		$image_html = $this->image_html( $image, $field, 'eit-toolkit-field__image' );
		if ( '' !== $image_html ) {
			echo '<figure class="eit-toolkit-field eit-toolkit-field--image">' . $image_html . '</figure>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image or escaped fallback markup.
		}
	}

	private function render_gallery( array $images, array $field ) {
		if ( ! $images ) {
			return;
		}
		echo '<div class="eit-toolkit-field eit-toolkit-field--gallery">';
		foreach ( $images as $image ) {
			if ( is_array( $image ) ) {
				echo $this->image_html( $image, $field, 'eit-toolkit-field__gallery-image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image or escaped fallback markup.
			}
		}
		echo '</div>';
	}

	private function image_html( array $image, array $field, $class ) {
		$attachment_id = absint( $image['id'] ?? 0 );
		if ( $attachment_id ) {
			$html = wp_get_attachment_image( $attachment_id, 'full', false, [
				'class' => sanitize_html_class( $class ),
				'loading' => 'lazy',
				'decoding' => 'async',
			] );
			if ( $html ) {
				return $html;
			}
		}
		if ( empty( $image['url'] ) ) {
			return '';
		}
		return sprintf(
			'<img class="%1$s" src="%2$s" alt="%3$s" loading="lazy" decoding="async">',
			esc_attr( sanitize_html_class( $class ) ),
			esc_url( $image['url'] ),
			esc_attr( $field['name'] )
		);
	}

	private function render_notice( $message ) {
		if ( class_exists( '\\Elementor\\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			printf( '<div class="eit-connector-notice" role="status">%s</div>', esc_html( $message ) );
		}
	}
}
