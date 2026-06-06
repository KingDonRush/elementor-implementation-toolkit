<?php
/**
 * Filter Controller result meta, empty state, and pagination placeholders.
 */

namespace EIT\Elementor\FilterController\Renderers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MetaRenderer {

	public static function render( array $config ) {
		?>
		<div class="eit-filter-controller__meta">
			<?php if ( $config['showResultCount'] ) : ?>
				<div class="eit-result-count" data-eit-result-count aria-live="polite"></div>
			<?php endif; ?>
			<?php if ( $config['showActiveChips'] ) : ?>
				<div class="eit-active-filters" data-eit-active-filters></div>
			<?php endif; ?>
		</div>

		<div class="eit-empty-state" data-eit-empty hidden><?php echo esc_html( $config['emptyText'] ); ?></div>
		<nav class="eit-pagination" data-eit-pagination aria-label="<?php echo esc_attr__( 'Filtered listing pagination', 'elementor-implementation-toolkit' ); ?>"></nav>
		<?php
	}
}
