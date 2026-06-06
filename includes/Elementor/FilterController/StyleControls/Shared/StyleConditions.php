<?php
/**
 * Shared style-control conditions for Filter Controller modules.
 */

namespace EIT\Elementor\FilterController\StyleControls\Shared;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StyleConditions {

	public static function count_or_chips_conditions() {
		return [
			'relation' => 'or',
			'terms'    => [
				[
					'name'     => 'show_result_count',
					'operator' => '==',
					'value'    => 'yes',
				],
				[
					'name'     => 'show_active_chips',
					'operator' => '==',
					'value'    => 'yes',
				],
			],
		];
	}
}
