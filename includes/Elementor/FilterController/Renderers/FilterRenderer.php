<?php
/**
 * Shared filter group wrapper and type dispatch for Filter Controller.
 */

namespace EIT\Elementor\FilterController\Renderers;

use EIT\Elementor\FilterController\Renderers\Types\ChoiceOptionsRenderer;
use EIT\Elementor\FilterController\Renderers\Types\DateRenderer;
use EIT\Elementor\FilterController\Renderers\Types\RangeRenderer;
use EIT\Elementor\FilterController\Renderers\Types\RatingRenderer;
use EIT\Elementor\FilterController\Renderers\Types\SearchRenderer;
use EIT\Elementor\FilterController\Renderers\Types\SelectRenderer;
use EIT\Elementor\FilterController\Renderers\Types\ToggleRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterRenderer {

	public static function render( $widget_id, array $filter, $index, array $settings = [] ) {
		$id           = $widget_id . '-' . $index;
		$type         = $filter['type'];
		$key          = $filter['key'];
		$name         = 'eit-' . $widget_id . '-' . $index;
		$layout_width = max( 10, min( 100, absint( $filter['layoutWidth'] ?? 100 ) ?: 100 ) );
		?>
		<div
			class="eit-filter-group eit-filter-group--<?php echo esc_attr( $type ); ?>"
			style="--eit-filter-column-span: <?php echo esc_attr( $layout_width ); ?>;"
			data-eit-filter-group="<?php echo esc_attr( $filter['id'] ); ?>"
			data-eit-field-source="<?php echo esc_attr( $filter['source'] ?? 'visible_text' ); ?>"
			data-eit-compare="<?php echo esc_attr( $filter['compare'] ?? '' ); ?>"
			data-eit-data-type="<?php echo esc_attr( $filter['dataType'] ?? '' ); ?>"
			data-eit-key-source="<?php echo esc_attr( $filter['keySource'] ?? '' ); ?>"
			data-eit-resolved-key="<?php echo esc_attr( $filter['resolvedKey'] ?? $key ); ?>"
		>
			<?php if ( $filter['showLabel'] ) : ?>
				<div class="eit-filter-group__label"><?php echo esc_html( $filter['label'] ); ?></div>
			<?php endif; ?>

			<?php self::render_type( $id, $type, $key, $name, $filter, $settings ); ?>
		</div>
		<?php
	}

	private static function render_type( $id, $type, $key, $name, array $filter, array $settings ) {
		if ( 'search' === $type ) {
			SearchRenderer::render( $id, $filter, $key );
			return;
		}

		if ( 'select' === $type ) {
			SelectRenderer::render( $filter, $key );
			return;
		}

		if ( 'range' === $type ) {
			RangeRenderer::render( $filter, $key, $settings );
			return;
		}

		if ( 'date' === $type ) {
			DateRenderer::render( $filter, $key );
			return;
		}

		if ( 'rating' === $type ) {
			RatingRenderer::render( $filter, $name, $key, $settings );
			return;
		}

		if ( 'toggle' === $type ) {
			ToggleRenderer::render( $filter, $key );
			return;
		}

		ChoiceOptionsRenderer::render( $type, $filter, $name, $key );
	}
}
