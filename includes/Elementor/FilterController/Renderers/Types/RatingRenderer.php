<?php
/**
 * Rating filter markup and icon rendering.
 */

namespace EIT\Elementor\FilterController\Renderers\Types;

use Elementor\Icons_Manager;
use EIT\Elementor\FilterController\FilterOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RatingRenderer {

	public static function render( array $filter, $name, $key, array $settings ) {
		$options       = $filter['options'];
		$rating_options = ! empty( $options ) ? $options : FilterOptions::default_rating_options();
		$icon_html    = self::icon_html( $settings );
		$icon_position = self::choice_setting( $settings, 'rating_icon_position', [ 'before', 'after' ], 'before' );
		?>
		<div class="eit-options eit-options--rating" data-eit-options>
			<?php foreach ( $rating_options as $option ) : ?>
				<label class="eit-option eit-rating-option eit-rating-option--icon-<?php echo esc_attr( $icon_position ); ?>">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $option['value'] ); ?>" data-eit-control data-eit-type="rating" data-eit-key="<?php echo esc_attr( $key ); ?>" />
					<?php self::render_icon( $icon_html ); ?>
					<span class="eit-rating-option__label"><?php echo esc_html( $option['label'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function choice_setting( array $settings, $key, array $allowed, $fallback ) {
		$value = sanitize_key( $settings[ $key ] ?? $fallback );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private static function icon_html( array $settings ) {
		if ( 'yes' !== ( $settings['rating_icon_enabled'] ?? 'yes' ) ) {
			return '';
		}

		$icon = $settings['rating_icon'] ?? [
			'value'   => 'fas fa-star',
			'library' => 'fa-solid',
		];

		if ( ! is_array( $icon ) || empty( $icon['library'] ) || empty( $icon['value'] ) ) {
			$icon = [
				'value'   => 'fas fa-star',
				'library' => 'fa-solid',
			];
		}

		return (string) Icons_Manager::try_get_icon_html(
			$icon,
			[
				'class'       => 'eit-rating-option__icon-glyph',
				'aria-hidden' => 'true',
			]
		);
	}

	private static function render_icon( $icon_html ) {
		if ( '' === $icon_html ) {
			return;
		}

		?>
		<span class="eit-rating-option__icon" aria-hidden="true">
			<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</span>
		<?php
	}
}
