<?php
/**
 * Optional Elementor Pro/Pro Elements CCT loop adapter.
 */

namespace EIT\Elementor\Loop;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctLoopIntegration {

	public function init_hooks() {
		add_action( 'elementor/init', [ $this, 'register_skin_hooks' ], 100 );
	}

	public function register_skin_hooks() {
		if (
			! class_exists( '\Elementor\Skin_Base' )
			|| ! class_exists( '\ElementorPro\Modules\LoopBuilder\Skins\Skin_Loop_Base' )
		) {
			return;
		}

		foreach ( [ 'loop-grid', 'loop-carousel' ] as $widget ) {
			add_action(
				'elementor/widget/' . $widget . '/skins_init',
				function ( $loop_widget ) {
					$loop_widget->add_skin( new SkinLoopCct( $loop_widget ) );
				},
				20
			);
		}
	}
}
