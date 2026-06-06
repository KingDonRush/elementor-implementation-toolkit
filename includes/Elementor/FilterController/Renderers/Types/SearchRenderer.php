<?php
/**
 * Search filter markup.
 */

namespace EIT\Elementor\FilterController\Renderers\Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SearchRenderer {

	public static function render( $id, array $filter, $key ) {
		?>
		<input
			id="<?php echo esc_attr( $id ); ?>"
			class="eit-input eit-input--search"
			type="search"
			placeholder="<?php echo esc_attr( $filter['placeholder'] ); ?>"
			data-eit-control
			data-eit-type="search"
			data-eit-key="<?php echo esc_attr( $key ); ?>"
		/>
		<?php
	}
}
