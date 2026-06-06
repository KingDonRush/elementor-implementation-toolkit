<?php
/**
 * Range filter markup and local range display helpers.
 */

namespace EIT\Elementor\FilterController\Renderers\Types;

use Elementor\Icons_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RangeRenderer {

	public static function render( array $filter, $key, array $settings ) {
		$show_range_inputs      = ( $settings['range_show_inputs'] ?? 'yes' ) === 'yes';
		$show_range_values      = ( $settings['range_show_values'] ?? '' ) === 'yes';
		$show_range_ticks       = ( $settings['range_show_ticks'] ?? '' ) === 'yes';
		$range_orientation      = self::range_setting( $settings, 'range_orientation', [ 'horizontal', 'vertical' ], 'horizontal' );
		$range_input_flow       = self::range_setting( $settings, 'range_input_flow', [ 'before', 'after' ], 'before' );
		$range_input_position   = self::range_setting( $settings, 'range_input_position', [ 'left', 'right' ], 'left' );
		$range_handle_icon_html = self::handle_icon_html( $settings );
		$range_classes          = [
			'eit-range',
			'eit-range--inputs-' . $range_input_position,
			'eit-range--inputs-' . $range_input_flow,
			'eit-range--' . $range_orientation,
			'eit-range--track-' . self::range_setting( $settings, 'range_track_style', [ 'solid', 'dashed', 'segmented' ], 'solid' ),
		];

		if ( $show_range_inputs ) {
			$range_classes[] = 'eit-range--show-inputs';
			$range_classes[] = 'eit-range--has-inputs';
		}

		if ( $show_range_values ) {
			$range_classes[] = 'eit-range--show-values';
			$range_classes[] = 'eit-range--has-value-labels';
		}

		if ( $show_range_ticks ) {
			$range_classes[] = 'eit-range--show-ticks';
			$range_classes[] = 'eit-range--has-ticks';
		}

		if ( '' !== $range_handle_icon_html ) {
			$range_classes[] = 'eit-range--handle-icon';
		}

		$range_midpoint = ( $filter['rangeMin'] + $filter['rangeMax'] ) / 2;
		?>
		<div class="<?php echo esc_attr( implode( ' ', $range_classes ) ); ?>" data-eit-control data-eit-type="range" data-eit-key="<?php echo esc_attr( $key ); ?>">
			<div class="eit-range__labels" aria-hidden="true">
				<span data-eit-range-min-label><?php echo esc_html( self::format_value( $filter['rangeMin'] ) ); ?></span>
				<span data-eit-range-max-label><?php echo esc_html( self::format_value( $filter['rangeMax'] ) ); ?></span>
			</div>
			<div class="eit-range__values">
				<input class="eit-input eit-range-number" type="number" value="<?php echo esc_attr( $filter['rangeMin'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-min />
				<input class="eit-input eit-range-number" type="number" value="<?php echo esc_attr( $filter['rangeMax'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-max />
			</div>
			<div class="eit-range__sliders">
				<div class="eit-range__slider">
					<input class="eit-range-input" type="range" value="<?php echo esc_attr( $filter['rangeMin'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-min-slider />
					<?php self::render_handle_icon( $range_handle_icon_html, 'min' ); ?>
				</div>
				<div class="eit-range__slider">
					<input class="eit-range-input" type="range" value="<?php echo esc_attr( $filter['rangeMax'] ); ?>" min="<?php echo esc_attr( $filter['rangeMin'] ); ?>" max="<?php echo esc_attr( $filter['rangeMax'] ); ?>" step="<?php echo esc_attr( $filter['rangeStep'] ); ?>" data-eit-range-max-slider />
					<?php self::render_handle_icon( $range_handle_icon_html, 'max' ); ?>
				</div>
			</div>
				<div class="eit-range__ticks" aria-hidden="true">
					<span><?php echo esc_html( self::format_value( $filter['rangeMin'] ) ); ?></span>
					<span><?php echo esc_html( self::format_value( $range_midpoint ) ); ?></span>
					<span><?php echo esc_html( self::format_value( $filter['rangeMax'] ) ); ?></span>
				</div>
			</div>
		<?php
	}

	private static function range_setting( array $settings, $key, array $allowed, $fallback ) {
		return self::choice_setting( $settings, $key, $allowed, $fallback );
	}

	private static function choice_setting( array $settings, $key, array $allowed, $fallback ) {
		$value = sanitize_key( $settings[ $key ] ?? $fallback );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private static function handle_icon_html( array $settings ) {
		if ( 'yes' !== ( $settings['range_handle_icon_enabled'] ?? '' ) ) {
			return '';
		}

		$icon = $settings['range_handle_icon'] ?? [];

		if ( ! is_array( $icon ) || empty( $icon['library'] ) ) {
			return '';
		}

		return (string) Icons_Manager::try_get_icon_html(
			$icon,
			[
				'class'       => 'eit-range__handle-icon-glyph',
				'aria-hidden' => 'true',
			]
		);
	}

	private static function render_handle_icon( $icon_html, $position ) {
		if ( '' === $icon_html ) {
			return;
		}

		?>
		<span class="eit-range__handle-icon" data-eit-range-<?php echo esc_attr( $position ); ?>-handle aria-hidden="true">
			<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</span>
		<?php
	}

	private static function format_value( $value ) {
		$value = (float) $value;

		if ( floor( $value ) === $value ) {
			return number_format_i18n( $value, 0 );
		}

		return number_format_i18n( $value, 2 );
	}
}
