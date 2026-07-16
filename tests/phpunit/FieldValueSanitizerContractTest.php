<?php
/**
 * Typed structural primitive tests for Entry input.
 */

use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\Uuid;
use EIT\Entry\FieldValueSanitizer;
use PHPUnit\Framework\TestCase;

class FieldValueSanitizerContractTest extends TestCase {

	public function test_repeatable_children_use_their_semantic_types_and_keep_zero(): void {
		$name = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'repeater:name' );
		$price = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'repeater:price' );
		$field = $this->field(
			'repeatable_group',
			[
				'children' => [
					[ 'id' => $name, 'name' => 'Name', 'type' => 'short_text', 'validation' => [ 'required' => true ] ],
					[ 'id' => $price, 'name' => 'Price', 'type' => 'decimal', 'validation' => [ 'required' => true ] ],
				],
			]
		);

		$result = ( new FieldValueSanitizer() )->sanitize( [ [ $name => ' Extra ', $price => '0' ] ], $field );

		self::assertSame( [ [ $name => 'Extra', $price => 0.0 ] ], $result );
	}

	public function test_repeatable_rejects_unknown_children_and_invalid_typed_values(): void {
		$child = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'repeater:date' );
		$field = $this->field( 'repeatable_group', [ 'children' => [ [ 'id' => $child, 'name' => 'Date', 'type' => 'date' ] ] ] );
		$unknown = ( new FieldValueSanitizer() )->sanitize( [ [ 'raw_key' => 'value' ] ], $field );
		$invalid = ( new FieldValueSanitizer() )->sanitize( [ [ $child => 'tomorrow' ] ], $field );

		self::assertSame( 'eit_entry_value_invalid', $unknown->get_error_code() );
		self::assertSame( 'eit_entry_value_invalid', $invalid->get_error_code() );
	}

	public function test_schedule_geopoint_and_availability_have_closed_shapes(): void {
		$sanitizer = new FieldValueSanitizer();
		$schedule = $sanitizer->sanitize( [ [ 'day' => 'monday', 'start' => '09:00', 'end' => '17:30' ] ], $this->field( 'schedule' ) );
		$geopoint = $sanitizer->sanitize( [ 'latitude' => '-23.5505', 'longitude' => '-46.6333' ], $this->field( 'geopoint' ) );
		$availability = $sanitizer->sanitize( [ 'status' => 'open', 'starts' => '2026-07-16T09:00', 'ends' => '2026-07-16T17:00' ], $this->field( 'availability', [ 'statuses' => [ 'open', 'closed' ] ] ) );

		self::assertSame( 'monday', $schedule[0]['day'] );
		self::assertSame( -23.5505, $geopoint['latitude'] );
		self::assertSame( 'open', $availability['status'] );
		self::assertTrue( is_wp_error( $sanitizer->sanitize( [ 'latitude' => 200, 'longitude' => 0 ], $this->field( 'geopoint' ) ) ) );
		self::assertTrue( is_wp_error( $sanitizer->sanitize( [ [ 'start' => '09:00', 'end' => '17:30' ] ], $this->field( 'schedule' ) ) ) );
	}

	public function test_empty_compounds_normalize_to_absence_and_repeater_minimum_is_enforced(): void {
		$sanitizer = new FieldValueSanitizer();
		$child = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'repeater:optional-child' );
		$repeater = $this->field(
			'repeatable_group',
			[
				'min_items' => 1,
				'children' => [ [ 'id' => $child, 'name' => 'Optional child', 'type' => 'short_text', 'validation' => [] ] ],
			]
		);

		self::assertNull( $sanitizer->sanitize( [ 'amount' => '', 'currency' => 'USD' ], $this->field( 'money' ) ) );
		self::assertNull( $sanitizer->sanitize( [ 'street' => '', 'city' => '' ], $this->field( 'address' ) ) );
		self::assertNull( $sanitizer->sanitize( [ 'latitude' => '', 'longitude' => '' ], $this->field( 'geopoint' ) ) );
		self::assertNull( $sanitizer->sanitize( [ 'status' => '', 'starts' => '', 'ends' => '' ], $this->field( 'availability' ) ) );
		self::assertTrue( is_wp_error( $sanitizer->sanitize( [ [ $child => '' ] ], $repeater ) ) );
	}

	public function test_media_accepts_only_well_formed_opaque_pending_tokens(): void {
		$sanitizer = new FieldValueSanitizer();
		$token = str_repeat( 'a1', 32 );

		self::assertSame( [ 'pending_token' => $token ], $sanitizer->sanitize( [ 'pending_token' => $token, 'name' => 'browser-only.png' ], $this->field( 'image' ) ) );
		self::assertTrue( is_wp_error( $sanitizer->sanitize( [ 'pending_token' => '../public-file.png' ], $this->field( 'image' ) ) ) );
	}

	private function field( $type, array $validation = [] ): array {
		return ( new FieldContractFactory( new FieldPrimitiveRegistry() ) )->make(
			Uuid::v5( Uuid::LEGACY_NAMESPACE, 'typed:' . $type ),
			ucwords( str_replace( '_', ' ', $type ) ),
			$type,
			[ 'validation' => $validation ]
		);
	}
}
