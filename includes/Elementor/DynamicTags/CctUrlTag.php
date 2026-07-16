<?php
/**
 * URL values from the current CCT item.
 */

namespace EIT\Elementor\DynamicTags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module;
use EIT\Support\CctFieldCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctUrlTag extends Data_Tag {

	public function get_name() {
		return 'eit-cct-url';
	}

	public function get_title() {
		return __( 'Legacy CCT URL', 'elementor-implementation-toolkit' );
	}

	public function get_group() {
		return DynamicTagsIntegration::LEGACY_GROUP;
	}

	public function get_categories() {
		return [ Module::URL_CATEGORY ];
	}

	public function get_panel_template_setting_key() {
		return 'field';
	}

	protected function register_controls() {
		$this->add_control( 'cct_type', [ 'label' => __( 'Preview Content Type', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::SELECT, 'options' => CctFieldCatalog::type_options() ] );
		$this->add_control( 'field', [ 'label' => __( 'URL Field', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::SELECT, 'options' => CctFieldCatalog::field_options( [ 'url', 'email' ] ) ] );
	}

	protected function get_value( array $options = [] ) {
		$value = CctTagValue::resolve( $this->get_settings( 'cct_type' ), $this->get_settings( 'field' ) );
		if ( is_email( $value ) ) {
			return 'mailto:' . sanitize_email( $value );
		}
		return esc_url_raw( $value );
	}
}
