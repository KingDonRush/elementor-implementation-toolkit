<?php
/**
 * Pure contracts for safe calculations, conditions and Field-ID validation.
 */

use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\Uuid;
use EIT\Entry\EntryValueProcessor;
use EIT\Entry\SafeExpression;
use PHPUnit\Framework\TestCase;

class EntryRuntimeContractTest extends TestCase {

	public function test_safe_expression_resolves_only_numeric_field_placeholders(): void {
		$price = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:price' );
		$quantity = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:quantity' );
		$expression = "{{$price}} * {{$quantity}} + 2";

		$result = ( new SafeExpression() )->evaluate( $expression, [ $price => 10, $quantity => 3 ] );
		self::assertSame( 32.0, $result );
		self::assertTrue( is_wp_error( ( new SafeExpression() )->evaluate( 'phpinfo()', [] ) ) );
		self::assertTrue( is_wp_error( ( new SafeExpression() )->evaluate( '10 / 0', [] ) ) );
	}

	public function test_required_zero_is_valid_and_calculated_values_ignore_browser_input(): void {
		$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
		$quantity = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:required-zero' );
		$total = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:calculated' );
		$fields = [
			$factory->make( $quantity, 'Quantity', 'integer', [ 'validation' => [ 'required' => true ] ] ),
			$factory->make( $total, 'Total', 'calculated', [ 'validation' => [ 'expression' => "{{$quantity}} * 2" ] ] ),
		];
		$contract = [ 'fields' => $fields, 'conditions' => [], 'calculations' => [ [ 'field_id' => $total, 'expression' => "{{$quantity}} * 2" ] ] ];

		$result = ( new EntryValueProcessor() )->process( $contract, [ $quantity => '0', $total => 999 ] );
		self::assertFalse( is_wp_error( $result ) );
		self::assertSame( 0, $result['values'][ $quantity ] );
		self::assertSame( 0.0, $result['values'][ $total ] );
	}

	public function test_hidden_condition_discards_browser_value_and_dynamic_required_is_enforced(): void {
		$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
		$source = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:condition-source' );
		$target = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:condition-target' );
		$condition = [ 'source_field_id' => $source, 'target_field_id' => $target, 'operator' => 'equals', 'value' => 'yes', 'effect' => 'show' ];
		$contract = [ 'fields' => [ $factory->make( $source, 'Toggle', 'short_text' ), $factory->make( $target, 'Details', 'short_text', [ 'validation' => [ 'required' => true ] ] ) ], 'conditions' => [ $condition ], 'calculations' => [] ];

		$hidden = ( new EntryValueProcessor() )->process( $contract, [ $source => 'no', $target => 'must not persist' ] );
		$shown = ( new EntryValueProcessor() )->process( $contract, [ $source => 'yes', $target => '' ] );
		self::assertArrayNotHasKey( $target, $hidden['values'] );
		self::assertTrue( is_wp_error( $shown ) );
		self::assertArrayHasKey( $target, $shown->get_error_data()['fields'] );
	}
}
