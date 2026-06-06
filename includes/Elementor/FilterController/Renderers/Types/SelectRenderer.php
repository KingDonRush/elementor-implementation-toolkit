<?php
/**
 * Select filter markup.
 */

namespace EIT\Elementor\FilterController\Renderers\Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SelectRenderer {

	public static function render( array $filter, $key ) {
		$options     = $filter['options'] ?? [];
		$label       = $filter['label'] ?? __( 'Select filter', 'elementor-implementation-toolkit' );
		$placeholder = ( $filter['placeholder'] ?? '' ) ?: __( 'All', 'elementor-implementation-toolkit' );
		$class       = empty( $options ) ? 'eit-select-field eit-select-field--empty-options' : 'eit-select-field';
		?>
		<div class="<?php echo esc_attr( $class ); ?>" data-eit-select-field data-eit-option-count="<?php echo esc_attr( count( $options ) ); ?>">
			<select
				class="eit-select"
				aria-label="<?php echo esc_attr( $label ); ?>"
				data-eit-control
				data-eit-type="select"
				data-eit-key="<?php echo esc_attr( $key ); ?>"
			>
				<option value=""><?php echo esc_html( $placeholder ); ?></option>
				<?php foreach ( $options as $option ) : ?>
					<option value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="eit-select-field__arrow" aria-hidden="true">
				<svg class="eit-select-field__arrow-svg" viewBox="0 0 20 20" focusable="false" aria-hidden="true">
					<path d="M5.2 7.4 10 12.2l4.8-4.8 1.2 1.2-6 6-6-6 1.2-1.2Z" fill="currentColor"/>
				</svg>
			</span>
		</div>
		<?php
	}
}
