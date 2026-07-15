<?php
/**
 * Semantic, accessible frontend controls derived from Field Contracts.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryFieldRenderer {

	public function render( array $field, $value = null ) {
		$id = 'eit-entry-field-' . $field['id'];
		$type = $field['type'];
		$required = ! empty( $field['validation']['required'] );
		?>
		<div class="eit-entry-field" data-eit-entry-field="<?php echo esc_attr( $field['id'] ); ?>" data-field-type="<?php echo esc_attr( $type ); ?>">
			<?php if ( 'boolean' !== $type ) : ?>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['name'] ); ?><?php if ( $required ) : ?><span aria-hidden="true"> *</span><?php endif; ?></label>
			<?php endif; ?>
			<?php $this->control( $field, $value, $id, $required ); ?>
			<?php if ( ! empty( $field['validation']['help'] ) ) : ?><p class="eit-entry-help" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $field['validation']['help'] ); ?></p><?php endif; ?>
			<p class="eit-entry-field-error" id="<?php echo esc_attr( $id ); ?>-error" data-eit-field-error aria-live="polite"></p>
		</div>
		<?php
	}

	private function control( array $field, $value, $id, $required ) {
		$type = $field['type'];
		if ( in_array( $type, [ 'long_text', 'rich_text' ], true ) ) {
			$this->textarea( $value, $id, $required );
		} elseif ( in_array( $type, [ 'single_choice', 'taxonomy' ], true ) ) {
			$this->select( $field, $value, $id, $required, false );
		} elseif ( 'multiple_choice' === $type ) {
			$this->choices( $field, $value, $id );
		} elseif ( 'boolean' === $type ) {
			$this->boolean( $field, $value, $id );
		} elseif ( 'money' === $type ) {
			$this->money( $field, $value, $id, $required );
		} elseif ( in_array( $type, [ 'image', 'gallery', 'file' ], true ) ) {
			$this->media( $field, $value, $id, $required );
		} elseif ( 'relation' === $type ) {
			$this->select( $field, $value, $id, $required, true );
		} elseif ( 'repeatable_group' === $type ) {
			$this->repeater( $field, $value, $id );
		} elseif ( 'schedule' === $type ) {
			$this->schedule( $value, $id );
		} elseif ( in_array( $type, [ 'address', 'geopoint', 'availability' ], true ) ) {
			$this->object_fields( $field, $value, $id );
		} elseif ( 'calculated' === $type ) {
			printf( '<output id="%1$s" class="eit-entry-calculated" data-eit-calculated aria-live="polite">%2$s</output>', esc_attr( $id ), esc_html( is_scalar( $value ) ? $value : __( 'Complete the number fields to calculate.', 'elementor-implementation-toolkit' ) ) );
		} else {
			$this->input( $field, $value, $id, $required );
		}
	}

	private function input( array $field, $value, $id, $required ) {
		$types = [ 'integer' => 'number', 'decimal' => 'number', 'percentage' => 'number', 'email' => 'email', 'phone' => 'tel', 'url' => 'url', 'date' => 'date', 'time' => 'time', 'datetime' => 'datetime-local' ];
		$type = $types[ $field['type'] ] ?? 'text';
		$attributes = $this->validation_attributes( $field );
		printf( '<input id="%1$s" type="%2$s" value="%3$s" %4$s %5$s>', esc_attr( $id ), esc_attr( $type ), esc_attr( is_scalar( $value ) ? $value : '' ), $required ? 'required' : '', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function textarea( $value, $id, $required ) {
		printf( '<textarea id="%1$s" rows="6" %2$s>%3$s</textarea>', esc_attr( $id ), $required ? 'required' : '', esc_textarea( (string) $value ) );
	}

	private function select( array $field, $value, $id, $required, $multiple ) {
		$options = $field['validation']['options'] ?? [];
		$selected = array_map(
			'strval',
			array_map(
				function ( $item ) {
					return is_array( $item ) ? ( $item['id'] ?? '' ) : $item;
				},
				(array) $value
			)
		);
		if ( $multiple && ! $options ) {
			printf( '<p class="eit-entry-configuration-needed">%s</p>', esc_html__( 'Connect a target Collection before this relation can be edited.', 'elementor-implementation-toolkit' ) );
			return;
		}
		printf( '<select id="%1$s" %2$s %3$s>', esc_attr( $id ), $multiple ? 'multiple' : '', $required ? 'required' : '' );
		if ( ! $multiple ) {
			printf( '<option value="">%s</option>', esc_html__( 'Choose an option', 'elementor-implementation-toolkit' ) );
		}
		foreach ( $options as $option ) {
			$option = is_array( $option ) ? $option : [ 'value' => $option, 'label' => $option ];
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option['value'] ?? '' ), selected( in_array( (string) ( $option['value'] ?? '' ), $selected, true ), true, false ), esc_html( $option['label'] ?? $option['value'] ?? '' ) );
		}
		echo '</select>';
	}

	private function choices( array $field, $value, $id ) {
		$selected = array_map( 'strval', (array) $value );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html( $field['name'] ) . '</legend><div class="eit-entry-options">';
		foreach ( $field['validation']['options'] ?? [] as $index => $option ) {
			$option = is_array( $option ) ? $option : [ 'value' => $option, 'label' => $option ];
			$option_id = $id . '-' . $index;
			printf( '<label for="%1$s"><input id="%1$s" type="checkbox" value="%2$s" %3$s> %4$s</label>', esc_attr( $option_id ), esc_attr( $option['value'] ?? '' ), checked( in_array( (string) ( $option['value'] ?? '' ), $selected, true ), true, false ), esc_html( $option['label'] ?? $option['value'] ?? '' ) );
		}
		echo '</div></fieldset>';
	}

	private function boolean( array $field, $value, $id ) {
		printf( '<label class="eit-entry-toggle" for="%1$s"><input id="%1$s" type="checkbox" %2$s> <span>%3$s</span></label>', esc_attr( $id ), checked( (bool) $value, true, false ), esc_html( $field['name'] ) );
	}

	private function money( array $field, $value, $id, $required ) {
		$value = is_array( $value ) ? $value : [ 'amount' => $value ];
		printf( '<div class="eit-entry-money"><span aria-hidden="true">%1$s</span><input id="%2$s" type="number" step="0.01" value="%3$s" data-money-amount %4$s><input type="hidden" data-money-currency value="%5$s"></div>', esc_html( $field['validation']['currency_symbol'] ?? '$' ), esc_attr( $id ), esc_attr( $value['amount'] ?? '' ), $required ? 'required' : '', esc_attr( $value['currency'] ?? $field['validation']['currency'] ?? 'USD' ) );
	}

	private function media( array $field, $value, $id, $required ) {
		$multiple = 'gallery' === $field['type'];
		$accept = in_array( $field['type'], [ 'image', 'gallery' ], true ) ? 'image/*' : ( $field['validation']['accept'] ?? '' );
		printf( '<input id="%1$s" type="file" %2$s %3$s %4$s data-eit-media-input>', esc_attr( $id ), $multiple ? 'multiple' : '', $required && empty( $value ) ? 'required' : '', $accept ? 'accept="' . esc_attr( $accept ) . '"' : '' );
		printf( '<input type="hidden" data-eit-media-value value="%s">', esc_attr( wp_json_encode( $value ) ) );
		echo '<div class="eit-entry-media-preview" data-eit-media-preview aria-live="polite">';
		foreach ( 'gallery' === $field['type'] ? (array) $value : [ $value ] as $item ) {
			if ( is_array( $item ) && ! empty( $item['url'] ) ) {
				printf( '<span><img src="%1$s" alt=""><small>%2$s</small></span>', esc_url( $item['url'] ), esc_html( $item['name'] ?? '' ) );
			}
		}
		echo '</div>';
	}

	private function repeater( array $field, $value, $id ) {
		$children = $field['validation']['children'] ?? [];
		if ( ! $children ) {
			printf( '<p class="eit-entry-configuration-needed">%s</p>', esc_html__( 'Define this group fields in the Blueprint before using the Surface.', 'elementor-implementation-toolkit' ) );
			return;
		}
		printf( '<div id="%s" class="eit-entry-repeater" data-eit-repeater>', esc_attr( $id ) );
		foreach ( (array) $value as $row ) {
			$this->repeater_row( $children, is_array( $row ) ? $row : [] );
		}
		echo '<template data-eit-repeater-template>';
		$this->repeater_row( $children, [] );
		echo '</template><button type="button" class="eit-entry-secondary" data-eit-add-row>' . esc_html__( 'Add row', 'elementor-implementation-toolkit' ) . '</button></div>';
	}

	private function repeater_row( array $children, array $value ) {
		echo '<div class="eit-entry-repeater-row" data-eit-repeater-row>';
		foreach ( $children as $child ) {
			$child_id = $child['id'] ?? '';
			printf( '<label>%1$s<input type="text" data-eit-repeater-child="%2$s" value="%3$s"></label>', esc_html( $child['name'] ?? '' ), esc_attr( $child_id ), esc_attr( $value[ $child_id ] ?? '' ) );
		}
		echo '<button type="button" class="eit-entry-link" data-eit-remove-row>' . esc_html__( 'Remove row', 'elementor-implementation-toolkit' ) . '</button></div>';
	}

	private function schedule( $value, $id ) {
		$field = [ 'validation' => [ 'children' => [ [ 'id' => 'start', 'name' => __( 'Starts', 'elementor-implementation-toolkit' ) ], [ 'id' => 'end', 'name' => __( 'Ends', 'elementor-implementation-toolkit' ) ] ] ] ];
		$this->repeater( $field, $value, $id );
	}

	private function object_fields( array $field, $value, $id ) {
		$keys = 'geopoint' === $field['type'] ? [ 'latitude', 'longitude' ] : ( 'availability' === $field['type'] ? [ 'status', 'starts', 'ends' ] : [ 'street', 'city', 'region', 'postal_code', 'country' ] );
		$value = is_array( $value ) ? $value : [];
		echo '<div class="eit-entry-object" id="' . esc_attr( $id ) . '">';
		foreach ( $keys as $key ) {
			printf( '<label>%1$s<input type="text" data-eit-object-key="%2$s" value="%3$s"></label>', esc_html( ucwords( str_replace( '_', ' ', $key ) ) ), esc_attr( $key ), esc_attr( $value[ $key ] ?? '' ) );
		}
		echo '</div>';
	}

	private function validation_attributes( array $field ) {
		$rules = $field['validation'] ?? [];
		$attributes = [];
		foreach ( [ 'min', 'max', 'min_length' => 'minlength', 'max_length' => 'maxlength' ] as $key => $attribute ) {
			if ( is_int( $key ) ) {
				$key = $attribute;
			}
			if ( isset( $rules[ $key ] ) ) {
				$attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $rules[ $key ] ) . '"';
			}
		}
		return implode( ' ', $attributes );
	}
}
