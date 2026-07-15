<?php

namespace EIT\Tests;

use EIT\Rest\FilterRequestPolicy;
use PHPUnit\Framework\TestCase;

class FilterRequestPolicyTest extends TestCase {

	public function test_default_request_cost_is_bounded_and_nonzero() {
		$cost = ( new FilterRequestPolicy() )->cost( [ 'provider' => 'dom', 'items' => [] ] );

		self::assertSame( FilterRequestPolicy::DEFAULT_PER_PAGE * 4 + 1, $cost );
		self::assertLessThanOrEqual( FilterRequestPolicy::MAX_COST, $cost );
	}

	public function test_selected_values_increase_request_cost() {
		$policy = new FilterRequestPolicy();
		$base = $policy->cost( [ 'provider' => 'dom', 'items' => array_fill( 0, 10, [] ), 'filters' => [] ] );
		$filtered = $policy->cost(
			[
				'provider' => 'dom',
				'items'    => array_fill( 0, 10, [] ),
				'filters'  => [ [ 'value' => [ 'one', 'two', 'three' ] ] ],
			]
		);

		self::assertGreaterThan( $base, $filtered );
	}
}
