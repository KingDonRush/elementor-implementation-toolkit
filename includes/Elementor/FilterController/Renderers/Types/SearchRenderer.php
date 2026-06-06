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
		$label       = $filter['label'] ?? __( 'Search', 'elementor-implementation-toolkit' );
		$placeholder = $filter['placeholder'] ?? '';
		?>
		<div class="eit-search-field" data-eit-search-field>
			<span class="eit-search-field__icon" aria-hidden="true">
				<svg class="eit-search-field__icon-svg" viewBox="0 0 20 20" focusable="false" aria-hidden="true">
					<path d="M8.7 3.2a5.5 5.5 0 0 1 4.35 8.86l3.07 3.07-1.2 1.2-3.07-3.07A5.5 5.5 0 1 1 8.7 3.2Zm0 1.7a3.8 3.8 0 1 0 0 7.6 3.8 3.8 0 0 0 0-7.6Z" fill="currentColor"/>
				</svg>
			</span>
			<input
				id="<?php echo esc_attr( $id ); ?>"
				class="eit-input eit-input--search"
				type="search"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				aria-label="<?php echo esc_attr( $label ); ?>"
				data-eit-control
				data-eit-search-input
				data-eit-type="search"
				data-eit-key="<?php echo esc_attr( $key ); ?>"
			/>
			<button
				type="button"
				class="eit-search-field__clear"
				data-eit-search-clear
				aria-label="<?php echo esc_attr__( 'Clear search', 'elementor-implementation-toolkit' ); ?>"
				hidden
			>
				<span class="eit-search-field__clear-icon" aria-hidden="true">&times;</span>
			</button>
		</div>
		<?php
	}
}
