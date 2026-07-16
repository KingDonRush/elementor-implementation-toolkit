<?php
/**
 * Pure compatibility mapping tests for a published Collection in Elementor.
 */

use EIT\Elementor\FilterController\CollectionWidgetContract;
use PHPUnit\Framework\TestCase;

class CollectionWidgetContractTest extends TestCase {

	public function test_widget_contract_uses_field_ids_and_compiled_decisions(): void {
		$mapped = ( new CollectionWidgetContract() )->map( $this->contract() );

		self::assertSame( 'price-id', $mapped['filters'][0]['key'] );
		self::assertSame( 'between', $mapped['filters'][0]['compare'] );
		self::assertSame( 'checkbox', $mapped['filters'][1]['type'] );
		self::assertSame( 'in', $mapped['filters'][1]['compare'] );
		self::assertSame( [ 'status-id' ], $mapped['facet_field_ids'] );
		self::assertSame( 24, $mapped['settings']['per_page'] );
		self::assertSame( 'status-id:desc', $mapped['sort_options'][2]['value'] );
		self::assertArrayNotHasKey( 'storage_key', $mapped['filters'][0] );
		self::assertArrayNotHasKey( 'target_selector', $mapped['settings'] );
	}

	private function contract(): array {
		return [
			'page_size' => 24,
			'fields' => [
				[ 'id' => 'price-id', 'name' => 'Price', 'type' => 'decimal', 'validation' => [ 'min' => 0, 'max' => 500, 'step' => 10 ] ],
				[ 'id' => 'status-id', 'name' => 'Status', 'type' => 'multiple_choice' ],
			],
			'filter_surface' => [
				'controls' => [
					[ 'field_id' => 'price-id', 'label' => 'Price', 'control' => 'range', 'operators' => [ 'equals', 'between' ], 'options' => [] ],
					[ 'field_id' => 'status-id', 'label' => 'Status', 'control' => 'options', 'operators' => [ 'in', 'not_in' ], 'options' => [ [ 'value' => 'open', 'label' => 'Open' ] ] ],
				],
				'facet_field_ids' => [ 'status-id' ],
				'sort_options' => [ [ 'field_id' => 'status-id', 'label' => 'Status', 'directions' => [ 'asc', 'desc' ] ] ],
				'url_state' => true,
				'active_chips' => true,
			],
		];
	}
}
