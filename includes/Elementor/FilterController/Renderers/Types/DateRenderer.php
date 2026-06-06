<?php
/**
 * Date-range filter markup.
 */

namespace EIT\Elementor\FilterController\Renderers\Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DateRenderer {

	public static function render( $key ) {
		?>
		<div class="eit-date-range" data-eit-control data-eit-type="date" data-eit-key="<?php echo esc_attr( $key ); ?>">
			<input class="eit-input" type="date" data-eit-date-from />
			<input class="eit-input" type="date" data-eit-date-to />
		</div>
		<?php
	}
}
