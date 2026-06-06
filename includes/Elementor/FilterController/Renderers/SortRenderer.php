<?php
/**
 * Filter Controller sort control markup.
 */

namespace EIT\Elementor\FilterController\Renderers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SortRenderer {

	public static function render( $widget_id, array $settings, array $sort_options ) {
		if ( ( $settings['show_sort'] ?? 'yes' ) !== 'yes' || empty( $sort_options ) ) {
			return;
		}

		?>
		<div class="eit-filter-group eit-filter-group--sort">
			<label class="eit-filter-group__label" for="<?php echo esc_attr( $widget_id . '-sort' ); ?>">
				<?php echo esc_html( $settings['sort_label'] ?? __( 'Sort by', 'elementor-implementation-toolkit' ) ); ?>
			</label>
			<select id="<?php echo esc_attr( $widget_id . '-sort' ); ?>" class="eit-select" data-eit-sort>
				<?php foreach ( $sort_options as $option ) : ?>
					<option value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}
}
