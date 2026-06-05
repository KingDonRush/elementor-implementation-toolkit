<?php
/**
 * Elementor dynamic tag that returns a Toolkit-managed field key.
 */

namespace EIT\Elementor\DynamicTags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module as DynamicTagsModule;
use EIT\Support\ToolkitFieldCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolkitFieldKeyTag extends Data_Tag {

	public function get_name() {
		return 'eit-toolkit-field-key';
	}

	public function get_title() {
		return __( 'Toolkit Field Key', 'elementor-implementation-toolkit' );
	}

	public function get_group() {
		return DynamicTagsIntegration::GROUP;
	}

	public function get_categories() {
		return [
			DynamicTagsModule::TEXT_CATEGORY,
			DynamicTagsModule::POST_META_CATEGORY,
		];
	}

	public function get_panel_template_setting_key() {
		return 'key';
	}

	public function is_settings_required() {
		return true;
	}

	protected function register_controls() {
		$this->add_control(
			'key',
			[
				'label'       => __( 'Field', 'elementor-implementation-toolkit' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => ToolkitFieldCatalog::select_options(),
				'default'     => '',
				'description' => __( 'Returns the selected Toolkit field key for Filter Controller bindings.', 'elementor-implementation-toolkit' ),
			]
		);
	}

	protected function get_value( array $options = [] ) {
		return sanitize_key( $this->get_settings( 'key' ) );
	}
}
