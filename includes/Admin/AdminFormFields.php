<?php
/**
 * Shared WordPress admin form fields.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminFormFields {

	private function text_field( $name, $label, $value, $placeholder = '' ) {
		?>
		<label class="eit-field">
			<span><?php echo esc_html( $label ); ?></span>
			<input type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
		</label>
		<?php
	}

	private function number_field( $name, $label, $value, $min = null, $max = null, $step = 1 ) {
		?>
		<label class="eit-field">
			<span><?php echo esc_html( $label ); ?></span>
			<input type="number" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo null !== $min ? 'min="' . esc_attr( $min ) . '"' : ''; ?> <?php echo null !== $max ? 'max="' . esc_attr( $max ) . '"' : ''; ?> step="<?php echo esc_attr( $step ); ?>" />
		</label>
		<?php
	}

	private function textarea_field( $name, $label, $value, $rows = 4 ) {
		?>
		<label class="eit-field eit-field--wide">
			<span><?php echo esc_html( $label ); ?></span>
			<textarea name="<?php echo esc_attr( $name ); ?>" rows="<?php echo esc_attr( $rows ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		</label>
		<?php
	}

	private function select_field( $name, $label, $value, array $options ) {
		?>
		<label class="eit-field">
			<span><?php echo esc_html( $label ); ?></span>
			<select name="<?php echo esc_attr( $name ); ?>">
				<?php foreach ( $options as $option_value => $option_label ) : ?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	private function checkbox_field( $name, $label, $checked ) {
		?>
		<label class="eit-check-field">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?> />
			<span><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}
}
