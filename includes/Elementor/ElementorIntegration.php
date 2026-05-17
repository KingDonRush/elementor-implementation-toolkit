<?php
/**
 * Elementor category and widget registration.
 */

namespace EIT\Elementor;

use EIT\Elementor\Widgets\FilterController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorIntegration {

	const CATEGORY = 'elementor-implementation-toolkit';

	public function init_hooks() {
		add_action( 'plugins_loaded', [ $this, 'init' ], 20 );
	}

	public function init() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		add_action( 'elementor/elements/categories_registered', [ $this, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );
	}

	public function register_category( $elements_manager ) {
		$elements_manager->add_category(
			self::CATEGORY,
			[
				'title' => esc_html__( 'Elementor Implementation Toolkit', 'elementor-implementation-toolkit' ),
				'icon'  => 'eicon-filter',
			]
		);
	}

	public function register_widgets( $widgets_manager ) {
		$widgets_manager->register( new FilterController() );
	}
}
