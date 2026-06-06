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
		$options = $filter['options'];
		$option  = $options[0] ?? [ 'value' => 'yes', 'label' => $filter['label'], 'visual' => '' ];
		?>
		<label class="eit-option eit-toggle">
			<input type="checkbox" value="<?php echo esc_attr( $option['value'] ); ?>" data-eit-control data-eit-type="toggle" data-eit-key="<?php echo esc_attr( $key ); ?>" />
			<span class="eit-toggle__switch" aria-hidden="true"></span>
			<span><?php echo esc_html( $option['label'] ); ?></span>
		</label>
		<?php
	}
}
