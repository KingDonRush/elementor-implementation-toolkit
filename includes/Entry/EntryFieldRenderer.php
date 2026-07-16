<?php
/**
 * Semantic, accessible frontend controls derived from Field Contracts.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryFieldRenderer {

	public function render( array $field, $value = null, $instance_id = '' ) {
		$id = 'eit-entry-field-' . sanitize_html_class( $instance_id ) . '-' . sanitize_html_class( $field['id'] );
		$type = $field['type'];
		$required = ! empty( $field['validation']['required'] );
		$described_by = trim( $id . '-error ' . ( ! empty( $field['validation']['help'] ) ? $id . '-help' : '' ) );
		?>
		<div class="eit-entry-field" data-eit-entry-field="<?php echo esc_attr( $field['id'] ); ?>" data-field-type="<?php echo esc_attr( $type ); ?>">
			<?php if ( ! in_array( $type, [ 'boolean', 'multiple_choice' ], true ) ) : ?>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['name'] ); ?><?php if ( $required ) : ?><span aria-hidden="true"> *</span><?php endif; ?></label>
			<?php endif; ?>
			<?php $this->control( $field, $value, $id, $required, $described_by ); ?>
			<?php if ( ! empty( $field['validation']['help'] ) ) : ?><p class="eit-entry-help" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $field['validation']['help'] ); ?></p><?php endif; ?>
			<p class="eit-entry-field-error" id="<?php echo esc_attr( $id ); ?>-error" data-eit-field-error aria-live="polite"></p>
		</div>
		<?php
	}

	private function control( array $field, $value, $id, $required, $described_by ) {
		$type = $field['type'];
		if ( in_array( $type, [ 'long_text', 'rich_text' ], true ) ) {
			$this->textarea( $value, $id, $required, $described_by );
		} elseif ( in_array( $type, [ 'single_choice', 'taxonomy' ], true ) ) {
			$this->select( $field, $value, $id, $required, false, $described_by );
		} elseif ( 'multiple_choice' === $type ) {
			$this->choices( $field, $value, $id, $required, $described_by );
		} elseif ( 'boolean' === $type ) {
			$this->boolean( $field, $value, $id, $described_by );
		} elseif ( 'money' === $type ) {
			$this->money( $field, $value, $id, $required, $described_by );
		} elseif ( in_array( $type, [ 'image', 'gallery', 'file' ], true ) ) {
			$this->media( $field, $value, $id, $required, $described_by );
		} elseif ( 'relation' === $type ) {
			$multiple = in_array( $field['relation']['cardinality'] ?? 'many_to_many', [ 'one_to_many', 'many_to_many' ], true );
			if ( empty( $field['relation']['options_collection_id'] ) ) {
				printf( '<p class="eit-entry-configuration-needed">%s</p>', esc_html__( 'Connect an authorized target Collection before this relation can be edited.', 'elementor-implementation-toolkit' ) );
			} else {
				$this->relation( $field, $value, $id, $required, $multiple, $described_by );
			}
		} elseif ( 'repeatable_group' === $type ) {
			$this->repeater( $field, $value, $id, $required, $described_by );
		} elseif ( 'schedule' === $type ) {
			$this->schedule( $value, $id, $required, $described_by );
		} elseif ( in_array( $type, [ 'address', 'geopoint', 'availability' ], true ) ) {
			$this->object_fields( $field, $value, $id, $described_by );
		} elseif ( 'calculated' === $type ) {
			printf( '<output id="%1$s" class="eit-entry-calculated" data-eit-calculated aria-live="polite" aria-describedby="%2$s">%3$s</output>', esc_attr( $id ), esc_attr( $described_by ), esc_html( is_scalar( $value ) ? $value : __( 'Complete the number fields to calculate.', 'elementor-implementation-toolkit' ) ) );
		} else {
			$this->input( $field, $value, $id, $required, $described_by );
		}
	}

	private function input( array $field, $value, $id, $required, $described_by ) {
		$types = [ 'integer' => 'number', 'decimal' => 'number', 'percentage' => 'number', 'email' => 'email', 'phone' => 'tel', 'url' => 'url', 'color' => 'color', 'date' => 'date', 'time' => 'time', 'datetime' => 'datetime-local' ];
		$type = $types[ $field['type'] ] ?? 'text';
		$attributes = $this->validation_attributes( $field );
		printf( '<input id="%1$s" type="%2$s" value="%3$s" %4$s %5$s aria-describedby="%6$s" aria-invalid="false">', esc_attr( $id ), esc_attr( $type ), esc_attr( is_scalar( $value ) ? $value : '' ), $required ? 'required' : '', $attributes, esc_attr( $described_by ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function textarea( $value, $id, $required, $described_by ) {
		printf( '<textarea id="%1$s" rows="6" %2$s aria-describedby="%3$s" aria-invalid="false">%4$s</textarea>', esc_attr( $id ), $required ? 'required' : '', esc_attr( $described_by ), esc_textarea( (string) $value ) );
	}

	private function select( array $field, $value, $id, $required, $multiple, $described_by, $attributes = '' ) {
		$options = $field['validation']['options'] ?? [];
		$selected = $this->selected_values( $value );
		printf( '<select id="%1$s" %2$s %3$s aria-describedby="%4$s" aria-invalid="false" %5$s>', esc_attr( $id ), $multiple ? 'multiple' : '', $required ? 'required' : '', esc_attr( $described_by ), $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes are an internal literal assembled by relation().
		if ( ! $multiple ) {
			printf( '<option value="">%s</option>', esc_html__( 'Choose an option', 'elementor-implementation-toolkit' ) );
		}
		foreach ( $options as $option ) {
			$option = is_array( $option ) ? $option : [ 'value' => $option, 'label' => $option ];
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option['value'] ?? '' ), selected( in_array( (string) ( $option['value'] ?? '' ), $selected, true ), true, false ), esc_html( $option['label'] ?? $option['value'] ?? '' ) );
		}
		echo '</select>';
	}

	private function selected_values( $value ) {
		return array_values(
			array_filter(
				array_map(
					function ( $item ) {
						$item = is_array( $item ) ? ( $item['id'] ?? $item['target_id'] ?? $item['value'] ?? '' ) : $item;
						return trim( (string) $item );
					},
					(array) $value
				),
				'strlen'
			)
		);
	}

	private function relation( array $field, $value, $id, $required, $multiple, $described_by ) {
		$collection_id = $field['relation']['options_collection_id'];
		$status_id = $id . '-relation-status';
		$search_id = $id . '-relation-search';
		printf( '<div class="eit-entry-relation" data-eit-relation-picker data-collection-id="%s">', esc_attr( $collection_id ) );
		if ( ! empty( $field['relation']['search_enabled'] ) ) {
			printf( '<label class="screen-reader-text" for="%1$s">%2$s</label><input id="%1$s" type="search" data-eit-relation-search autocomplete="off" placeholder="%3$s">', esc_attr( $search_id ), esc_html__( 'Search relation options', 'elementor-implementation-toolkit' ), esc_attr__( 'Search options', 'elementor-implementation-toolkit' ) );
		}
		$this->select( $field, $value, $id, $required, $multiple, trim( $described_by . ' ' . $status_id ), 'data-eit-relation-select' );
		printf( '<p id="%1$s" class="eit-entry-relation-status" data-eit-relation-status role="status" aria-live="polite"></p><button type="button" class="eit-entry-secondary" data-eit-relation-more hidden>%2$s</button>', esc_attr( $status_id ), esc_html__( 'Load more options', 'elementor-implementation-toolkit' ) );
		echo '</div>';
	}

	private function choices( array $field, $value, $id, $required, $described_by ) {
		$selected = array_map( 'strval', (array) $value );
		printf( '<fieldset data-eit-choice-group aria-describedby="%1$s" aria-required="%2$s" aria-invalid="false"><legend>%3$s%4$s</legend><div class="eit-entry-options">', esc_attr( $described_by ), $required ? 'true' : 'false', esc_html( $field['name'] ), $required ? '<span aria-hidden="true"> *</span>' : '' );
		foreach ( $field['validation']['options'] ?? [] as $index => $option ) {
			$option = is_array( $option ) ? $option : [ 'value' => $option, 'label' => $option ];
			$option_id = $id . '-' . $index;
			printf( '<label for="%1$s"><input id="%1$s" type="checkbox" value="%2$s" %3$s aria-describedby="%4$s" aria-invalid="false"> %5$s</label>', esc_attr( $option_id ), esc_attr( $option['value'] ?? '' ), checked( in_array( (string) ( $option['value'] ?? '' ), $selected, true ), true, false ), esc_attr( $described_by ), esc_html( $option['label'] ?? $option['value'] ?? '' ) );
		}
		echo '</div></fieldset>';
	}

	private function boolean( array $field, $value, $id, $described_by ) {
		printf( '<label class="eit-entry-toggle" for="%1$s"><input id="%1$s" type="checkbox" %2$s aria-describedby="%3$s" aria-invalid="false"> <span>%4$s</span></label>', esc_attr( $id ), checked( (bool) $value, true, false ), esc_attr( $described_by ), esc_html( $field['name'] ) );
	}

	private function money( array $field, $value, $id, $required, $described_by ) {
		$value = is_array( $value ) ? $value : [ 'amount' => $value ];
		printf( '<div class="eit-entry-money"><span aria-hidden="true">%1$s</span><input id="%2$s" type="number" step="0.01" value="%3$s" data-money-amount %4$s aria-describedby="%5$s" aria-invalid="false"><input type="hidden" data-money-currency value="%6$s"></div>', esc_html( $field['validation']['currency_symbol'] ?? '$' ), esc_attr( $id ), esc_attr( $value['amount'] ?? '' ), $required ? 'required' : '', esc_attr( $described_by ), esc_attr( $value['currency'] ?? $field['validation']['currency'] ?? 'USD' ) );
	}

	private function media( array $field, $value, $id, $required, $described_by ) {
		$multiple = 'gallery' === $field['type'];
		$accept = in_array( $field['type'], [ 'image', 'gallery' ], true ) ? 'image/*' : ( $field['validation']['accept'] ?? '' );
		printf( '<input id="%1$s" type="file" %2$s %3$s %4$s data-eit-media-input aria-describedby="%5$s" aria-invalid="false">', esc_attr( $id ), $multiple ? 'multiple' : '', $required && empty( $value ) ? 'required' : '', $accept ? 'accept="' . esc_attr( $accept ) . '"' : '', esc_attr( $described_by ) );
		printf( '<input type="hidden" data-eit-media-value value="%s">', esc_attr( wp_json_encode( $value ) ) );
		echo '<div class="eit-entry-media-preview" data-eit-media-preview aria-live="polite">';
		foreach ( 'gallery' === $field['type'] ? (array) $value : [ $value ] as $item ) {
			if ( is_array( $item ) && ! empty( $item['url'] ) ) {
				printf( '<span><img src="%1$s" alt=""><small>%2$s</small></span>', esc_url( $item['url'] ), esc_html( $item['name'] ?? '' ) );
			}
		}
		echo '</div>';
	}

	private function repeater( array $field, $value, $id, $required, $described_by ) {
		$children = $field['validation']['children'] ?? [];
		if ( ! $children ) {
			printf( '<p class="eit-entry-configuration-needed">%s</p>', esc_html__( 'Define this group fields in the Blueprint before using the Surface.', 'elementor-implementation-toolkit' ) );
			return;
		}
		$maximum = min( FieldValueSanitizer::MAX_LIST_ITEMS, max( 1, absint( $field['validation']['max_items'] ?? FieldValueSanitizer::MAX_LIST_ITEMS ) ) );
		$minimum = min( $maximum, max( $required ? 1 : 0, absint( $field['validation']['min_items'] ?? 0 ) ) );
		$rows = array_slice( (array) $value, 0, $maximum );
		while ( count( $rows ) < $minimum ) {
			$rows[] = [];
		}
		printf( '<div id="%1$s" class="eit-entry-repeater" data-eit-repeater data-eit-min-rows="%2$s" data-eit-max-rows="%3$s" role="group" aria-required="%4$s" aria-describedby="%5$s">', esc_attr( $id ), esc_attr( $minimum ), esc_attr( $maximum ), $required ? 'true' : 'false', esc_attr( $described_by ) );
		foreach ( $rows as $row ) {
			$this->repeater_row( $children, is_array( $row ) ? $row : [] );
		}
		echo '<template data-eit-repeater-template>';
		$this->repeater_row( $children, [] );
		echo '</template><button type="button" class="eit-entry-secondary" data-eit-add-row>' . esc_html__( 'Add row', 'elementor-implementation-toolkit' ) . '</button></div>';
	}

	private function repeater_row( array $children, array $value ) {
		echo '<div class="eit-entry-repeater-row" data-eit-repeater-row>';
		foreach ( $children as $child ) {
			$this->repeater_child( $child, $value[ $child['id'] ?? '' ] ?? null );
		}
		echo '<button type="button" class="eit-entry-link" data-eit-remove-row>' . esc_html__( 'Remove row', 'elementor-implementation-toolkit' ) . '</button></div>';
	}

	private function repeater_child( array $child, $value ) {
		$id = (string) ( $child['id'] ?? '' );
		$type = $child['type'] ?? 'short_text';
		$required = ! empty( $child['validation']['required'] );
		echo '<label><span>' . esc_html( $child['name'] ?? '' ) . ( $required ? '<span aria-hidden="true"> *</span>' : '' ) . '</span>';
		if ( 'single_choice' === $type ) {
			echo '<select data-eit-repeater-child="' . esc_attr( $id ) . '"' . ( $required ? ' required' : '' ) . '><option value="">' . esc_html__( 'Choose an option', 'elementor-implementation-toolkit' ) . '</option>';
			foreach ( $child['validation']['options'] ?? [] as $option ) {
				$option = is_array( $option ) ? $option : [ 'value' => $option, 'label' => $option ];
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option['value'] ?? '' ), selected( (string) ( $option['value'] ?? '' ), (string) $value, false ), esc_html( $option['label'] ?? $option['value'] ?? '' ) );
			}
			echo '</select>';
		} elseif ( 'boolean' === $type ) {
			printf( '<input type="checkbox" data-eit-repeater-child="%1$s" %2$s>', esc_attr( $id ), checked( (bool) $value, true, false ) );
		} else {
			$types = [ 'integer' => 'number', 'decimal' => 'number', 'date' => 'date', 'time' => 'time', 'datetime' => 'datetime-local' ];
			$input_type = $types[ $type ] ?? 'text';
			printf( '<input type="%1$s" data-eit-repeater-child="%2$s" value="%3$s" %4$s %5$s>', esc_attr( $input_type ), esc_attr( $id ), esc_attr( is_scalar( $value ) ? $value : '' ), $required ? 'required' : '', $this->validation_attributes( $child ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes are escaped by validation_attributes().
		}
		echo '</label>';
	}

	private function schedule( $value, $id, $required, $described_by ) {
		$days = [
			'monday' => __( 'Monday', 'elementor-implementation-toolkit' ),
			'tuesday' => __( 'Tuesday', 'elementor-implementation-toolkit' ),
			'wednesday' => __( 'Wednesday', 'elementor-implementation-toolkit' ),
			'thursday' => __( 'Thursday', 'elementor-implementation-toolkit' ),
			'friday' => __( 'Friday', 'elementor-implementation-toolkit' ),
			'saturday' => __( 'Saturday', 'elementor-implementation-toolkit' ),
			'sunday' => __( 'Sunday', 'elementor-implementation-toolkit' ),
		];
		$options = array_map( fn( $label, $day ) => [ 'value' => $day, 'label' => $label ], $days, array_keys( $days ) );
		$field = [
			'validation' => [
				'max_items' => 50,
				'children' => [
					[ 'id' => 'day', 'name' => __( 'Day', 'elementor-implementation-toolkit' ), 'type' => 'single_choice', 'validation' => [ 'required' => true, 'options' => $options ] ],
					[ 'id' => 'start', 'name' => __( 'Starts', 'elementor-implementation-toolkit' ), 'type' => 'time', 'validation' => [ 'required' => true ] ],
					[ 'id' => 'end', 'name' => __( 'Ends', 'elementor-implementation-toolkit' ), 'type' => 'time', 'validation' => [ 'required' => true ] ],
				],
			],
		];
		$this->repeater( $field, $value, $id, $required, $described_by );
	}

	private function object_fields( array $field, $value, $id, $described_by ) {
		$required = ! empty( $field['validation']['required'] );
		$keys = $this->object_keys( $field['type'] );
		$value = is_array( $value ) ? $value : [];
		printf( '<div class="eit-entry-object" id="%1$s" role="group" aria-required="%2$s" aria-describedby="%3$s">', esc_attr( $id ), $required ? 'true' : 'false', esc_attr( $described_by ) );
		foreach ( $keys as $key => $label ) {
			echo '<label><span>' . esc_html( $label ) . '</span>';
			if ( 'availability' === $field['type'] && 'status' === $key && ! empty( $field['validation']['statuses'] ) ) {
				echo '<select data-eit-object-key="status"><option value="">' . esc_html__( 'Choose a status', 'elementor-implementation-toolkit' ) . '</option>';
				foreach ( $field['validation']['statuses'] as $status ) {
					$status = is_array( $status ) ? $status : [ 'value' => $status, 'label' => $status ];
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( sanitize_key( $status['value'] ?? '' ) ), selected( (string) ( $status['value'] ?? '' ), (string) ( $value[ $key ] ?? '' ), false ), esc_html( $status['label'] ?? $status['value'] ?? '' ) );
				}
				echo '</select>';
			} else {
				$input = $this->object_input( $field['type'], $key );
				printf( '<input type="%1$s" data-eit-object-key="%2$s" value="%3$s" %4$s>', esc_attr( $input['type'] ), esc_attr( $key ), esc_attr( $value[ $key ] ?? '' ), $input['attributes'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- object_input returns an internal escaped attribute allowlist.
			}
			echo '</label>';
		}
		echo '</div>';
	}

	private function object_keys( $type ) {
		if ( 'geopoint' === $type ) {
			return [ 'latitude' => __( 'Latitude', 'elementor-implementation-toolkit' ), 'longitude' => __( 'Longitude', 'elementor-implementation-toolkit' ) ];
		}
		if ( 'availability' === $type ) {
			return [ 'status' => __( 'Status', 'elementor-implementation-toolkit' ), 'starts' => __( 'Starts', 'elementor-implementation-toolkit' ), 'ends' => __( 'Ends', 'elementor-implementation-toolkit' ) ];
		}
		return [
			'street' => __( 'Street', 'elementor-implementation-toolkit' ),
			'city' => __( 'City', 'elementor-implementation-toolkit' ),
			'region' => __( 'Region', 'elementor-implementation-toolkit' ),
			'postal_code' => __( 'Postal code', 'elementor-implementation-toolkit' ),
			'country' => __( 'Country', 'elementor-implementation-toolkit' ),
		];
	}

	private function object_input( $type, $key ) {
		if ( 'geopoint' === $type ) {
			$range = 'latitude' === $key ? [ -90, 90 ] : [ -180, 180 ];
			return [ 'type' => 'number', 'attributes' => 'step="any" min="' . esc_attr( $range[0] ) . '" max="' . esc_attr( $range[1] ) . '"' ];
		}
		if ( 'availability' === $type && in_array( $key, [ 'starts', 'ends' ], true ) ) {
			return [ 'type' => 'datetime-local', 'attributes' => '' ];
		}
		return [ 'type' => 'text', 'attributes' => '' ];
	}

	private function validation_attributes( array $field ) {
		$rules = $field['validation'] ?? [];
		$attributes = [];
		foreach ( [ 'min', 'max', 'step', 'min_length' => 'minlength', 'max_length' => 'maxlength' ] as $key => $attribute ) {
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
