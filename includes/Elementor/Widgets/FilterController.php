<?php
/**
 * Parasitic filter controller widget.
 */

namespace EIT\Elementor\Widgets;

use Elementor\Widget_Base;
use EIT\Elementor\ElementorIntegration;
use EIT\Elementor\FilterController\ContentControls;
use EIT\Elementor\FilterController\FilterOptions;
use EIT\Elementor\FilterController\Renderers\ActionButtonsRenderer;
use EIT\Elementor\FilterController\Renderers\FilterRenderer;
use EIT\Elementor\FilterController\Renderers\MetaRenderer;
use EIT\Elementor\FilterController\Renderers\PresetStateNoticeRenderer;
use EIT\Elementor\FilterController\Renderers\SortRenderer;
use EIT\Elementor\FilterController\FilterSettings;
use EIT\Elementor\FilterController\RuntimeConfig;
use EIT\Elementor\FilterController\StyleControls;
use EIT\Support\SortOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterController extends Widget_Base {

	public function get_name() {
		return 'eit-filter-controller';
	}

	public function get_title() {
		return esc_html__( 'Filter Controller', 'elementor-implementation-toolkit' );
	}

	public function get_icon() {
		return 'eicon-filter';
	}

	public function get_categories() {
		return [ ElementorIntegration::CATEGORY ];
	}

	public function get_keywords() {
		return [ 'filter', 'ajax', 'listing', 'search', 'sort', 'pagination', 'jetsmartfilters' ];
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
		ContentControls::register( $this );
		StyleControls::register( $this );
	}

	protected function render() {
		$settings = FilterSettings::resolve_preset_settings( $this->get_settings_for_display() );
		$filters  = FilterSettings::normalize_filters( $settings['filters'] ?? [] );
		$sort_options_raw = SortOptions::resolve_lines( $settings['sort_options_items'] ?? null, $settings['sort_options'] ?? '' );
		$sort_options = FilterOptions::parse( $sort_options_raw );
		$config = RuntimeConfig::from_settings( $this->get_id(), $settings );

		$this->add_render_attribute(
			'wrapper',
			[
				'class' => 'eit-filter-controller',
				'data-eit-instance' => $this->get_id(),
				'data-eit-preset-state' => sanitize_key( $settings['preset_resolution_state'] ?? 'widget' ),
				'data-eit-config' => wp_json_encode( $config ),
				'data-eit-filters' => wp_json_encode( $filters ),
			]
		);

		?>
		<div <?php $this->print_render_attribute_string( 'wrapper' ); ?>>
			<div class="eit-editor-target-helper" hidden></div>
			<?php PresetStateNoticeRenderer::render( $settings ); ?>
			<form class="eit-filter-controller__form" action="#" method="get">
				<?php foreach ( $filters as $index => $filter ) : ?>
					<?php FilterRenderer::render( $this->get_id(), $filter, $index, $settings ); ?>
				<?php endforeach; ?>

				<?php SortRenderer::render( $this->get_id(), $settings, $sort_options ); ?>
				<?php ActionButtonsRenderer::render( $settings ); ?>
			</form>

			<?php MetaRenderer::render( $config ); ?>
		</div>
		<?php
	}
}
