<?php
/**
 * Minimal semantic fallback output for Collection results.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionHtmlRenderer {

	public function render( array $items, array $fields ) {
		if ( ! $items ) {
			return '<div class="eit-collection-items" data-eit-collection-items><p class="eit-collection-empty">' . esc_html__( 'No matching items found.', 'elementor-implementation-toolkit' ) . '</p></div>';
		}
		ob_start();
		?>
		<div class="eit-collection-items" data-eit-collection-items>
			<?php foreach ( $items as $item ) : ?>
				<article class="eit-collection-item" data-eit-collection-item data-item-id="<?php echo esc_attr( $item['id'] ); ?>">
					<h2 class="eit-collection-item__title">
						<?php if ( $item['url'] ) : ?>
							<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $item['title'] ); ?>
						<?php endif; ?>
					</h2>
					<?php $this->render_values( $item['values'], $fields ); ?>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return trim( ob_get_clean() );
	}

	private function render_values( array $values, array $fields ) {
		$visible = array_filter(
			array_intersect_key( $fields, $values ),
			function ( $field, $field_id ) use ( $values ) {
				return $this->has_value( $values[ $field_id ] ?? null );
			},
			ARRAY_FILTER_USE_BOTH
		);
		if ( ! $visible ) {
			return;
		}
		?>
		<dl class="eit-collection-item__fields">
			<?php foreach ( $visible as $field_id => $field ) : ?>
				<dt><?php echo esc_html( $field['name'] ); ?></dt>
				<dd data-field-id="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $this->text( $values[ $field_id ], $field ) ); ?></dd>
			<?php endforeach; ?>
		</dl>
		<?php
	}

	private function text( $value, array $field = [] ) {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'elementor-implementation-toolkit' ) : __( 'No', 'elementor-implementation-toolkit' );
		}
		if ( is_array( $value ) ) {
			if ( array_key_exists( 'amount', $value ) ) {
				return trim( (string) $value['amount'] . ' ' . (string) ( $value['currency'] ?? '' ) );
			}
			if ( array_key_exists( 'url', $value ) ) {
				return (string) ( ( $value['alt'] ?? '' ) ?: wp_basename( $value['url'] ) );
			}
			$values = [];
			foreach ( $value as $item ) {
				$values[] = $this->text( $item, $field );
			}
			return implode( ', ', array_filter( $values ) );
		}
		if ( in_array( $field['type'] ?? '', [ 'single_choice', 'multiple_choice' ], true ) ) {
			foreach ( $field['validation']['options'] ?? [] as $option ) {
				if ( (string) ( $option['value'] ?? '' ) === (string) $value ) {
					return sanitize_text_field( $option['label'] ?? $value );
				}
			}
		}
		return wp_strip_all_tags( (string) $value );
	}

	private function has_value( $value ) {
		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return false;
		}
		if ( ! is_array( $value ) ) {
			return true;
		}
		foreach ( $value as $item ) {
			if ( $this->has_value( $item ) ) {
				return true;
			}
		}
		return false;
	}
}
