<?php
/**
 * Pure request-boundary tests for published Collection contracts.
 */

use EIT\Collection\CollectionRequestValidator;
use PHPUnit\Framework\TestCase;

class CollectionRuntimeContractTest extends TestCase {

	public function test_request_accepts_zero_and_normalizes_ranges(): void {
		$validator = new CollectionRequestValidator();
		$request = $validator->validate(
			$this->contract(),
			[
				'filters' => [
					[ 'field_id' => 'price-id', 'operator' => 'between', 'value' => [ 'min' => '10', 'max' => '0' ] ],
					[ 'field_id' => 'status-id', 'operator' => 'equals', 'value' => '0' ],
				],
			]
		);

		self::assertFalse( is_wp_error( $request ) );
		self::assertSame( [ 'min' => 0.0, 'max' => 10.0 ], $request['filters'][0]['value'] );
		self::assertSame( '0', $request['filters'][1]['value'] );
		self::assertSame( 24, $request['per_page'] );
	}

	public function test_browser_cannot_choose_provider_or_storage_key(): void {
		$validator = new CollectionRequestValidator();
		$provider = $validator->validate( $this->contract(), [ 'provider' => 'legacy_dom' ] );
		$storage = $validator->validate(
			$this->contract(),
			[ 'filters' => [ [ 'field_id' => 'price-id', 'operator' => 'equals', 'value' => 10, 'storage_key' => 'price' ] ] ]
		);

		self::assertSame( 'eit_collection_input_not_allowed', $provider->get_error_code() );
		self::assertSame( 'eit_collection_filter_invalid', $storage->get_error_code() );
	}

	public function test_unpublished_field_operator_and_facet_are_rejected(): void {
		$validator = new CollectionRequestValidator();
		$field = $validator->validate( $this->contract(), [ 'filters' => [ [ 'field_id' => 'private-id', 'operator' => 'equals', 'value' => 'x' ] ] ] );
		$operator = $validator->validate( $this->contract(), [ 'filters' => [ [ 'field_id' => 'price-id', 'operator' => 'contains', 'value' => 10 ] ] ] );
		$facet = $validator->validate( $this->contract(), [ 'facets' => [ 'price-id' ] ] );

		self::assertSame( 'eit_collection_filter_not_allowed', $field->get_error_code() );
		self::assertSame( 'eit_collection_filter_not_allowed', $operator->get_error_code() );
		self::assertSame( 'eit_collection_facets_not_allowed', $facet->get_error_code() );
	}

	public function test_legacy_snapshot_is_bounded_and_provider_scoped(): void {
		$validator = new CollectionRequestValidator();
		$not_legacy = $validator->validate( $this->contract(), [ 'legacy_snapshot' => [ [ 'clientId' => 'one' ] ] ] );
		$legacy = $this->contract();
		$legacy['provider']['id'] = 'legacy_dom';
		$too_many = $validator->validate( $legacy, [ 'legacy_snapshot' => array_fill( 0, 201, [] ) ] );

		self::assertSame( 'eit_collection_snapshot_not_allowed', $not_legacy->get_error_code() );
		self::assertSame( 'eit_collection_snapshot_invalid', $too_many->get_error_code() );
	}

	private function contract(): array {
		return [
			'provider' => [ 'id' => 'wp_query' ],
			'page_size' => 24,
			'filter_field_ids' => [ 'price-id', 'status-id' ],
			'sort_field_ids' => [ 'price-id' ],
			'search_field_ids' => [],
			'default_sort' => [ 'field_id' => 'price-id', 'direction' => 'asc' ],
			'filter_surface' => [ 'facet_field_ids' => [ 'status-id' ] ],
			'explain' => true,
			'fields' => [
				[ 'id' => 'price-id', 'name' => 'Price', 'type' => 'decimal' ],
				[ 'id' => 'status-id', 'name' => 'Status', 'type' => 'single_choice' ],
				[ 'id' => 'private-id', 'name' => 'Private', 'type' => 'short_text' ],
			],
		];
	}
}
