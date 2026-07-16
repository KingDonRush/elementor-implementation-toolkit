<?php
/**
 * Parasitic filter controller widget.
 */

namespace EIT\Elementor\Widgets;

use Elementor\Widget_Base;
use EIT\Elementor\ElementorIntegration;
use EIT\Elementor\FilterController\ContentControls;
use EIT\Elementor\FilterController\CollectionWidgetBridge;
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
		if ( 'collection' === ( $settings['data_provider'] ?? '' ) ) {
			$collection = CollectionWidgetBridge::resolve( $settings['collection_id'] ?? '' );
			if ( is_wp_error( $collection ) ) {
				$this->render_collection_error( $collection );
				return;
			}
			$settings = array_replace( $settings, $collection['settings'] );
			$settings['collection_facet_field_ids'] = $collection['facet_field_ids'];
			$filters = $collection['filters'];
			$sort_options = $collection['sort_options'];
		} else {
			$filters = FilterSettings::normalize_filters( $settings['filters'] ?? [] );
			$sort_options_raw = SortOptions::resolve_lines( $settings['sort_options_items'] ?? null, $settings['sort_options'] ?? '' );
			$sort_options = FilterOptions::parse( $sort_options_raw );
		}
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
		<div <?php $this->print_render_attribute_string( 'wrapper' ); ?> aria-busy="false">
			<div class="eit-editor-target-helper" hidden></div>
			<?php PresetStateNoticeRenderer::render( $settings ); ?>
			<form class="eit-filter-controller__form" action="#" method="get">
				<?php foreach ( $filters as $index => $filter ) : ?>
					<?php FilterRenderer::render( $this->get_id(), $filter, $index, $settings ); ?>
				<?php endforeach; ?>

				<?php SortRenderer::render( $this->get_id(), $settings, $sort_options ); ?>
				<?php ActionButtonsRenderer::render( $settings ); ?>
			</form>

			<?php if ( 'collection' === $config['provider'] ) : ?>
				<section
					class="eit-collection-surface"
					data-eit-collection-results
					aria-label="<?php echo esc_attr__( 'Collection results', 'elementor-implementation-toolkit' ); ?>"
					tabindex="-1"
				></section>
			<?php endif; ?>

			<?php MetaRenderer::render( $config ); ?>
		</div>
		<?php
	}

	private function render_collection_error( \WP_Error $error ) {
		?>
		<div class="eit-filter-controller">
			<div class="eit-filter-controller__notice is-warning" role="alert">
				<strong><?php esc_html_e( 'Collection connection needs attention.', 'elementor-implementation-toolkit' ); ?></strong>
				<span><?php echo esc_html( $error->get_error_message() ); ?></span>
			</div>
		</div>
		<?php
	}
}
