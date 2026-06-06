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
		$options = $filter['options'];
		?>
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
		<?php
	}
}
