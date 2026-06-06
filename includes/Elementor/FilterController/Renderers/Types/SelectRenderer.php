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
		$options = $filter['options'];
		?>
		<select class="eit-select" data-eit-control data-eit-type="select" data-eit-key="<?php echo esc_attr( $key ); ?>">
			<option value=""><?php echo esc_html( $filter['placeholder'] ?: __( 'All', 'elementor-implementation-toolkit' ) ); ?></option>
			<?php foreach ( $options as $option ) : ?>
				<option value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
