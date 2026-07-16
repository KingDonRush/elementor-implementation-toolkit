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
		$this->add_control(
			'field_id',
			[
				'label' => __( 'Compatible Field', 'elementor-implementation-toolkit' ),
				'type' => Controls_Manager::SELECT,
				'options' => ( new PublishedContractCatalog() )->field_options( $this->field_categories() ),
				'description' => __( 'Uses the stable Field ID and the current Entity context. Storage keys are never entered here.', 'elementor-implementation-toolkit' ),
			]
		);
	}

	protected function get_value( array $options = [] ) {
		$result = ( new TypedValueResolver() )->resolve( $this->get_settings( 'field_id' ), $this->value_category() );
		return $result['value'] ?? null;
	}

	abstract protected function field_categories();

	abstract protected function value_category();
}
