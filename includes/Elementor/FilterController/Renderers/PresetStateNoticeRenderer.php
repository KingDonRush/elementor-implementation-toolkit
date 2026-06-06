<?php
/**
 * Filter Controller preset state notice markup.
 */

namespace EIT\Elementor\FilterController\Renderers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PresetStateNoticeRenderer {

	public static function render( array $settings ) {
		if ( 'missing' !== ( $settings['preset_resolution_state'] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="eit-filter-controller__notice is-warning">
			<strong><?php esc_html_e( 'Linked filter preset is missing.', 'elementor-implementation-toolkit' ); ?></strong>
			<span><?php esc_html_e( 'Select another preset or import a local copy in the Elementor widget controls.', 'elementor-implementation-toolkit' ); ?></span>
		</div>
		<?php
	}
}
