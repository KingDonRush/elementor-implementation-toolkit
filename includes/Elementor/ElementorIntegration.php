<?php
/**
 * Elementor category and widget registration.
 */

namespace EIT\Elementor;

use EIT\Elementor\DynamicTags\DynamicTagsIntegration;
use EIT\Elementor\Loop\CctLoopIntegration;
use EIT\Elementor\Widgets\FilterController;
use EIT\Elementor\Widgets\ToolkitAction;
use EIT\Elementor\Widgets\ToolkitCollectionSurface;
use EIT\Elementor\Widgets\ToolkitEntrySurface;
use EIT\Elementor\Widgets\ToolkitField;
use EIT\Elementor\Widgets\ToolkitFilterSurface;

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
		( new DynamicTagsIntegration() )->init_hooks();
		( new CctLoopIntegration() )->init_hooks();
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
		$widgets_manager->register( new ToolkitField() );
		$widgets_manager->register( new ToolkitCollectionSurface() );
		$widgets_manager->register( new ToolkitFilterSurface() );
		$widgets_manager->register( new ToolkitEntrySurface() );
		$widgets_manager->register( new ToolkitAction() );
		$widgets_manager->register( new FilterController() );
	}
}
