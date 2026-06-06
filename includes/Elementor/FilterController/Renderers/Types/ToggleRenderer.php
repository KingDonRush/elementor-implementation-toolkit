<?php
/**
 * Toggle filter markup.
 */

namespace EIT\Elementor\FilterController\Renderers\Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToggleRenderer {

	public static function render( array $filter, $key ) {
		$options = $filter['options'] ?? [];
		$label   = $filter['label'] ?? __( 'Toggle', 'elementor-implementation-toolkit' );
		$option  = $options[0] ?? [ 'value' => 'yes', 'label' => $label, 'visual' => '' ];
		?>
		<label class="eit-option eit-option--toggle eit-toggle" data-eit-toggle data-eit-toggle-on-value="<?php echo esc_attr( $option['value'] ); ?>">
			<input type="checkbox" value="<?php echo esc_attr( $option['value'] ); ?>" data-eit-control data-eit-type="toggle" data-eit-key="<?php echo esc_attr( $key ); ?>" />
			<span class="eit-toggle__switch" aria-hidden="true">
				<span class="eit-toggle__state eit-toggle__state--off"><?php esc_html_e( 'Off', 'elementor-implementation-toolkit' ); ?></span>
				<span class="eit-toggle__state eit-toggle__state--on"><?php esc_html_e( 'On', 'elementor-implementation-toolkit' ); ?></span>
			</span>
			<span class="eit-toggle__text">
				<span class="eit-toggle__label"><?php echo esc_html( $option['label'] ); ?></span>
				<span class="eit-toggle__contract">
					<?php
					printf(
						esc_html__( 'Checked sends %s; unchecked clears this filter.', 'elementor-implementation-toolkit' ),
						esc_html( $option['value'] )
					);
					?>
				</span>
			</span>
		</label>
		<?php
	}
}
