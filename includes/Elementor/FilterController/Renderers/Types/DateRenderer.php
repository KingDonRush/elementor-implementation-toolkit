<?php
/**
 * Date-range filter markup.
 */

namespace EIT\Elementor\FilterController\Renderers\Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DateRenderer {

	public static function render( array $filter, $key ) {
		$label      = $filter['label'] ?? __( 'Date range', 'elementor-implementation-toolkit' );
		$from_label = __( 'From', 'elementor-implementation-toolkit' );
		$to_label   = __( 'To', 'elementor-implementation-toolkit' );
		?>
		<div class="eit-date-range" data-eit-control data-eit-type="date" data-eit-key="<?php echo esc_attr( $key ); ?>">
			<label class="eit-date-range__field eit-date-range__field--from">
				<span class="eit-date-range__label"><?php echo esc_html( $from_label ); ?></span>
				<input class="eit-input eit-date-range__input" type="date" aria-label="<?php echo esc_attr( $from_label . ' ' . $label ); ?>" data-eit-date-from />
			</label>
			<span class="eit-date-range__separator" aria-hidden="true"><?php esc_html_e( 'to', 'elementor-implementation-toolkit' ); ?></span>
			<label class="eit-date-range__field eit-date-range__field--to">
				<span class="eit-date-range__label"><?php echo esc_html( $to_label ); ?></span>
				<input class="eit-input eit-date-range__input" type="date" aria-label="<?php echo esc_attr( $to_label . ' ' . $label ); ?>" data-eit-date-to />
			</label>
			<button type="button" class="eit-date-range__clear" data-eit-date-clear hidden aria-label="<?php echo esc_attr( sprintf( __( 'Clear %s date range', 'elementor-implementation-toolkit' ), $label ) ); ?>">&times;</button>
			<span class="eit-date-range__status" data-eit-date-status hidden><?php esc_html_e( 'From date is after To date. The range will be corrected before filtering.', 'elementor-implementation-toolkit' ); ?></span>
			<span class="eit-date-range__contract"><?php esc_html_e( 'Native date inputs use YYYY-MM-DD values. Stored field timezone and format can affect matching.', 'elementor-implementation-toolkit' ); ?></span>
		</div>
		<?php
	}
}
