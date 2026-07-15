<?php

namespace EIT\Tests;

use EIT\Support\LegacyDomMatcher;
use PHPUnit\Framework\TestCase;

class LegacyDomMatcherTest extends TestCase {

	public function test_choice_matching_uses_exact_tokens() {
		$items = [
			[ 'data' => [ 'kind' => 'plugin-pro' ], 'classes' => [], 'title' => 'Pro', 'text' => '', 'originalIndex' => 0 ],
			[ 'data' => [ 'kind' => 'plugin website' ], 'classes' => [], 'title' => 'Base', 'text' => '', 'originalIndex' => 1 ],
		];

		$result = ( new LegacyDomMatcher() )->filter(
			$items,
			[ [ 'type' => 'select', 'key' => 'kind', 'value' => 'plugin', 'compare' => '', 'source' => '', 'dataType' => '' ] ]
		);

		self::assertSame( [ 'Base' ], array_column( $result, 'title' ) );
	}

	public function test_equal_numeric_comparison_accepts_zero() {
		$items = [
			[ 'data' => [ 'quantity' => '0' ], 'classes' => [], 'title' => 'Zero', 'text' => '', 'originalIndex' => 0 ],
		];

		$result = ( new LegacyDomMatcher() )->filter(
			$items,
			[ [ 'type' => 'range', 'key' => 'quantity', 'value' => '0', 'compare' => 'equals', 'source' => 'data_attr', 'dataType' => 'number' ] ]
		);

		self::assertCount( 1, $result );
	}
}
