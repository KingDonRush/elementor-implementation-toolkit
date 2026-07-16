<?php
/**
 * Static presentation contracts that must survive Elementor editor rerenders.
 */

use PHPUnit\Framework\TestCase;

class ElementorPresentationHardeningTest extends TestCase {

	public function test_action_uses_elementor_button_markup_and_runtime_connection_status(): void {
		$source = $this->source( 'includes/Elementor/Widgets/ToolkitAction.php' );

		self::assertStringContainsString( "'elementor-button'", $source );
		self::assertStringContainsString( 'elementor-button-content-wrapper', $source );
		self::assertStringContainsString( 'data-eit-action-status', $source );
		self::assertStringNotContainsString( 'aria-controls="eit-entry-%1$s"', $source );
	}

	public function test_entry_controls_are_instance_scoped_and_describe_their_errors(): void {
		$surface = $this->source( 'includes/Elementor/Widgets/ToolkitEntrySurface.php' );
		$renderer = $this->source( 'includes/Entry/EntryRenderer.php' );
		$fields = $this->source( 'includes/Entry/EntryFieldRenderer.php' );

		self::assertStringContainsString( '$this->get_id()', $surface );
		self::assertStringContainsString( 'data-eit-entry-instance', $renderer );
		self::assertStringContainsString( 'data-eit-choice-group', $fields );
		self::assertStringContainsString( 'aria-describedby', $fields );
		self::assertStringContainsString( 'aria-invalid="false"', $fields );
	}

	public function test_attachment_fields_delegate_responsive_image_metadata_to_wordpress(): void {
		$source = $this->source( 'includes/Elementor/Widgets/ToolkitField.php' );

		self::assertStringContainsString( "'entity_id'", $source );
		self::assertStringContainsString( "'eit_field_category'", $source );
		self::assertStringContainsString( 'editor_field_options(', $source );
		self::assertStringContainsString( 'wp_get_attachment_image(', $source );
		self::assertStringContainsString( "'full'", $source );
		self::assertStringContainsString( "'decoding' => 'async'", $source );
	}

	private function source( string $path ): string {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
		self::assertNotFalse( $source );
		return $source;
	}
}
