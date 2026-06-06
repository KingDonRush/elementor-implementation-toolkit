<?php
/**
 * Filter Controller action button markup.
 */

namespace EIT\Elementor\FilterController\Renderers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ActionButtonsRenderer {

	public static function render( array $settings ) {
		?>
		<div class="eit-filter-actions">
			<?php if ( ( $settings['show_apply'] ?? '' ) === 'yes' ) : ?>
				<button type="submit" class="eit-button eit-button--apply" data-eit-apply>
					<?php echo esc_html( $settings['apply_text'] ?? __( 'Apply filters', 'elementor-implementation-toolkit' ) ); ?>
				</button>
			<?php endif; ?>
			<button type="button" class="eit-button eit-button--reset" data-eit-reset>
				<?php echo esc_html( $settings['reset_text'] ?? __( 'Reset', 'elementor-implementation-toolkit' ) ); ?>
			</button>
		</div>
		<?php
	}
}
