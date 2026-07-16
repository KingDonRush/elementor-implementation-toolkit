<?php
/**
 * Shared stable-ID controls and resolution for typed Elementor tags.
 */

namespace EIT\Elementor\DynamicTags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use EIT\Elementor\Contracts\PublishedContractCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractTypedTag extends Data_Tag {

	public function get_group() {
		return DynamicTagsIntegration::GROUP;
	}

	public function get_panel_template_setting_key() {
		return 'field_id';
	}

	public function is_settings_required() {
		return true;
	}

	protected function register_controls() {
		$catalog = new PublishedContractCatalog();
		$context_entity_id = $catalog->context_entity_id();
		if ( '' === $context_entity_id ) {
			$this->add_control(
				'entity_id',
				[
					'label' => __( 'Entity context', 'elementor-implementation-toolkit' ),
					'type' => Controls_Manager::SELECT,
					'options' => $catalog->entity_options( $this->field_categories() ),
					'description' => __( 'Required because the current Theme Builder preview does not identify one Entity unambiguously.', 'elementor-implementation-toolkit' ),
				]
			);
			$this->add_control(
				'eit_field_category',
				[
					'type' => Controls_Manager::HIDDEN,
					'default' => $this->value_category(),
				]
			);
		}
		$this->add_control(
			'field_id',
			array_filter(
				[
				'label' => __( 'Compatible Field', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::SELECT,
				'options' => $catalog->editor_field_options( $this->field_categories(), $context_entity_id ),
				'description' => __( 'Only compatible Fields from the resolved Entity are listed. Storage keys are never entered here.', 'elementor-implementation-toolkit' ),
				'condition' => '' === $context_entity_id ? [ 'entity_id!' => '' ] : null,
				],
				fn( $value ) => null !== $value
			)
		);
	}

	protected function get_value( array $options = [] ) {
		$result = ( new TypedValueResolver() )->resolve(
			$this->get_settings( 'field_id' ),
			$this->value_category(),
			$this->get_settings( 'entity_id' )
		);
		return $result['value'] ?? null;
	}

	abstract protected function field_categories();

	abstract protected function value_category();
}
