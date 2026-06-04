<?php
/**
 * Parasitic filter controller widget.
 */

namespace EIT\Elementor\Widgets;

use Elementor\Widget_Base;
use EIT\Elementor\ElementorIntegration;
use EIT\Elementor\FilterController\ContentControls;
use EIT\Elementor\FilterController\FilterOptions;
use EIT\Elementor\FilterController\FilterSettings;
use EIT\Elementor\FilterController\RuntimeConfig;
use EIT\Elementor\FilterController\StyleControls;

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
		$sort_options = FilterOptions::parse( $settings['sort_options'] ?? '' );
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
			<?php $this->render_preset_state_notice( $settings ); ?>
			<form class="eit-filter-controller__form" action="#" method="get">
				<?php foreach ( $filters as $index => $filter ) : ?>
					<?php $this->render_filter( $filter, $index, $settings ); ?>
				<?php endforeach; ?>

				<?php if ( ( $settings['show_sort'] ?? 'yes' ) === 'yes' ) : ?>
					<div class="eit-filter-group eit-filter-group--sort">
						<label class="eit-filter-group__label" for="<?php echo esc_attr( $this->get_id() . '-sort' ); ?>">
							<?php echo esc_html( $settings['sort_label'] ?? __( 'Sort by', 'elementor-implementation-toolkit' ) ); ?>
						</label>
						<select id="<?php echo esc_attr( $this->get_id() . '-sort' ); ?>" class="eit-select" data-eit-sort>
							<?php foreach ( $sort_options as $option ) : ?>
								<option value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<div class="eit-filter-actions">
					<?php if ( ( $settings['show_apply'] ?? '' ) === 'yes' ) : ?>
						<button type="submit" class="eit-button eit-button--apply" data-eit-apply>
							<?php echo esc_html( $settings['apply_text'] ?? __( 'Apply filters', 'elementor-implementation-toolkit' ) ); ?>
						</button>
					<?php endif; ?>
					<button type="button" class="eit-button eit-button--reset" data-eit-reset>
						<?php echo esc_html( $settings['reset_text'] ?? __( 'Reset', 'elementor-implementation-toolkit' ) ); ?>
					</button>
				</div>
			</form>

			<div class="eit-filter-controller__meta">
				<?php if ( $config['showResultCount'] ) : ?>
					<div class="eit-result-count" data-eit-result-count aria-live="polite"></div>
				<?php endif; ?>
				<?php if ( $config['showActiveChips'] ) : ?>
					<div class="eit-active-filters" data-eit-active-filters></div>
				<?php endif; ?>
			</div>

			<div class="eit-empty-state" data-eit-empty hidden><?php echo esc_html( $config['emptyText'] ); ?></div>
			<nav class="eit-pagination" data-eit-pagination aria-label="<?php echo esc_attr__( 'Filtered listing pagination', 'elementor-implementation-toolkit' ); ?>"></nav>
		</div>
		<?php
	}

	private function render_preset_state_notice( array $settings ) {
		if ( 'missing' !== ( $settings['preset_resolution_state'] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="eit-filter-controller__notice is-warning">
			<strong><?php esc_html_e( 'Linked filter preset is missing.', 'elementor-implementation-toolkit' ); ?></strong>
			<span><?php esc_html_e( 'Select another preset or import a local copy in the Elementor widget controls.', 'elementor-implementation-toolkit' ); ?></span>
		</div>
		<?php
	}

	private function render_filter( array $filter, $index, array $settings = [] ) {
		$id = $this->get_id() . '-' . $index;
		$type = $filter['type'];
		$key = $filter['key'];
		$name = 'eit-' . $this->get_id() . '-' . $index;
		$options = $filter['options'];
		?>
		<div class="eit-filter-group eit-filter-group--<?php echo esc_attr( $type ); ?>" data-eit-filter-group="<?php echo esc_attr( $filter['id'] ); ?>">
			<?php if ( $filter['showLabel'] ) : ?>
				<div class="eit-filter-group__label"><?php echo esc_html( $filter['label'] ); ?></div>
			<?php endif; ?>

			<?php if ( 'search' === $type ) : ?>
				<input
					id="<?php echo esc_attr( $id ); ?>"
					class="eit-input eit-input--search"
					type="search"
					placeholder="<?php echo esc_attr( $filter['placeholder'] ); ?>"
					data-eit-control
					data-eit-type="search"
					data-eit-key="<?php echo esc_attr( $key ); ?>"
				/>
			<?php elseif ( 'select' === $type ) : ?>
				<select class="eit-select" data-eit-control data-eit-type="select" data-eit-key="<?php echo esc_attr( $key ); ?>">
					<option value=""><?php echo esc_html( $filter['placeholder'] ?: __( 'All', 'elementor-implementation-toolkit' ) ); ?></option>
					<?php foreach ( $options as $option ) : ?>
						<option value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( 'range' === $type ) : ?>
				<?php
				$range_classes = [
					'eit-range',
					'eit-range--' . $this->get_range_setting( $settings, 'range_orientation', [ 'horizontal', 'vertical' ], 'horizontal' ),
					'eit-range--track-' . $this->get_range_setting( $settings, 'range_track_style', [ 'solid', 'dashed', 'segmented' ], 'solid' ),
				];

				if ( ( $settings['range_show_values'] ?? '' ) === 'yes' ) {
					$range_classes[] = 'eit-range--show-values';
				}

				if ( ( $settings['range_show_ticks'] ?? '' ) === 'yes' ) {
					$range_classes[] = 'eit-range--show-ticks';
				}

				$range_midpoint = ( $filter['rangeMin'] + $filter['rangeMax'] ) / 2;
				?>
				<div class="<?php echo esc_attr( implode( ' ', $range_classes ) ); ?>" data-eit-control data-eit-type="range" data-eit-key="<?php echo esc_attr( $key ); ?>">
					<div class="eit-range__labels" aria-hidden="true">
						<span data-eit-range-min-label><?php echo esc_html( $this->format_range_value( $filter['rangeMin'] ) ); ?></span>
						<span data-eit-range-max-label><?php echo esc_html( $this->format_range_value( $filter['rangeMax'] ) ); ?></span>
					</div>
					<div class="eit-range__values">
						<input class="eit-input eit-range-number" type="number" value="<?php echo esc_attr( $filter['rangeMin'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-min />
						<input class="eit-input eit-range-number" type="number" value="<?php echo esc_attr( $filter['rangeMax'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-max />
					</div>
					<div class="eit-range__sliders">
						<input class="eit-range-input" type="range" value="<?php echo esc_attr( $filter['rangeMin'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-min-slider />
						<input class="eit-range-input" type="range" value="<?php echo esc_attr( $filter['rangeMax'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-max-slider />
					</div>
					<div class="eit-range__ticks" aria-hidden="true">
						<span><?php echo esc_html( $this->format_range_value( $filter['rangeMin'] ) ); ?></span>
						<span><?php echo esc_html( $this->format_range_value( $range_midpoint ) ); ?></span>
						<span><?php echo esc_html( $this->format_range_value( $filter['rangeMax'] ) ); ?></span>
					</div>
				</div>
			<?php elseif ( 'date' === $type ) : ?>
				<div class="eit-date-range" data-eit-control data-eit-type="date" data-eit-key="<?php echo esc_attr( $key ); ?>">
					<input class="eit-input" type="date" data-eit-date-from />
					<input class="eit-input" type="date" data-eit-date-to />
				</div>
			<?php elseif ( 'rating' === $type ) : ?>
				<div class="eit-options eit-options--rating" data-eit-options>
					<?php $rating_options = ! empty( $options ) ? $options : FilterOptions::default_rating_options(); ?>
					<?php foreach ( $rating_options as $option ) : ?>
						<label class="eit-option eit-rating-option">
							<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $option['value'] ); ?>" data-eit-control data-eit-type="rating" data-eit-key="<?php echo esc_attr( $key ); ?>" />
							<span><?php echo esc_html( $option['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php elseif ( 'toggle' === $type ) : ?>
				<?php $option = $options[0] ?? [ 'value' => 'yes', 'label' => $filter['label'], 'visual' => '' ]; ?>
				<label class="eit-option eit-toggle">
					<input type="checkbox" value="<?php echo esc_attr( $option['value'] ); ?>" data-eit-control data-eit-type="toggle" data-eit-key="<?php echo esc_attr( $key ); ?>" />
					<span class="eit-toggle__switch" aria-hidden="true"></span>
					<span><?php echo esc_html( $option['label'] ); ?></span>
				</label>
			<?php else : ?>
				<div class="eit-options eit-options--<?php echo esc_attr( $type ); ?>" data-eit-options>
					<?php foreach ( $options as $option ) : ?>
						<label class="eit-option eit-option--<?php echo esc_attr( $type ); ?>">
							<input
								type="<?php echo in_array( $type, [ 'radio' ], true ) ? 'radio' : 'checkbox'; ?>"
								name="<?php echo esc_attr( $name ); ?><?php echo in_array( $type, [ 'checkbox', 'chips', 'swatch' ], true ) ? '[]' : ''; ?>"
								value="<?php echo esc_attr( $option['value'] ); ?>"
								data-eit-control
								data-eit-type="<?php echo esc_attr( $type ); ?>"
								data-eit-key="<?php echo esc_attr( $key ); ?>"
							/>
							<?php if ( 'swatch' === $type && $option['visual'] ) : ?>
								<span class="eit-swatch" style="<?php echo esc_attr( FilterOptions::swatch_style( $option['visual'] ) ); ?>" aria-hidden="true"></span>
							<?php endif; ?>
							<span><?php echo esc_html( $option['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_range_setting( array $settings, $key, array $allowed, $fallback ) {
		$value = sanitize_key( $settings[ $key ] ?? $fallback );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private function format_range_value( $value ) {
		$value = (float) $value;

		if ( floor( $value ) === $value ) {
			return number_format_i18n( $value, 0 );
		}

		return number_format_i18n( $value, 2 );
	}
}
