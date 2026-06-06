<?php
/**
 * Checkbox, radio, chips, and swatch option markup.
 */

namespace EIT\Elementor\FilterController\Renderers\Types;

use EIT\Elementor\FilterController\FilterOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChoiceOptionsRenderer {

	public static function render( $type, array $filter, $name, $key ) {
		$options = self::options_for_render( $type, $filter );
		?>
		<div class="eit-options eit-options--<?php echo esc_attr( $type ); ?>" data-eit-options>
			<?php if ( empty( $options ) ) : ?>
				<div class="eit-options__empty" data-eit-options-empty>
					<?php esc_html_e( 'No options configured.', 'elementor-implementation-toolkit' ); ?>
				</div>
			<?php endif; ?>
			<?php foreach ( $options as $option ) : ?>
				<label class="eit-option eit-option--<?php echo esc_attr( $type ); ?><?php echo isset( $option['count'] ) && null !== $option['count'] ? ' eit-option--has-count' : ''; ?>">
					<input
						type="<?php echo in_array( $type, [ 'radio' ], true ) ? 'radio' : 'checkbox'; ?>"
						name="<?php echo esc_attr( $name ); ?><?php echo in_array( $type, [ 'checkbox', 'chips', 'swatch' ], true ) ? '[]' : ''; ?>"
						value="<?php echo esc_attr( $option['value'] ); ?>"
						data-eit-control
						data-eit-type="<?php echo esc_attr( $type ); ?>"
						data-eit-key="<?php echo esc_attr( $key ); ?>"
					/>
					<?php if ( 'checkbox' === $type ) : ?>
						<span class="eit-checkbox-indicator" aria-hidden="true"></span>
					<?php endif; ?>
					<?php if ( 'radio' === $type ) : ?>
						<span class="eit-radio-indicator" aria-hidden="true"></span>
					<?php endif; ?>
					<?php if ( 'swatch' === $type && $option['visual'] ) : ?>
						<span class="eit-swatch" style="<?php echo esc_attr( FilterOptions::swatch_style( $option['visual'] ) ); ?>" aria-hidden="true"></span>
					<?php endif; ?>
					<span class="eit-option__label"><?php echo esc_html( $option['label'] ); ?></span>
					<?php if ( isset( $option['count'] ) && null !== $option['count'] ) : ?>
						<span
							class="eit-option-count"
							aria-label="<?php echo esc_attr( sprintf( _n( '%d item', '%d items', $option['count'], 'elementor-implementation-toolkit' ), $option['count'] ) ); ?>"
						>
							<?php echo esc_html( number_format_i18n( $option['count'] ) ); ?>
						</span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function options_for_render( $type, array $filter ) {
		$options = $filter['options'] ?? [];

		if ( 'radio' !== $type || empty( $filter['radioShowAll'] ) ) {
			return $options;
		}

		$label = trim( (string) ( $filter['radioAllLabel'] ?? '' ) );

		if ( '' === $label ) {
			$label = __( 'All', 'elementor-implementation-toolkit' );
		}

		array_unshift(
			$options,
			[
				'value' => '',
				'label' => $label,
				'visual' => '',
				'count' => null,
			]
		);

		return $options;
	}
}
