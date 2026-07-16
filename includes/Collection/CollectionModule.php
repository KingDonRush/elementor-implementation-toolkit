<?php
/**
 * Boots Collection rendering and cache invalidation hooks.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionModule {

	private $invalidator;

	public function init_hooks() {
		$this->invalidator = new CollectionInvalidator();
		add_action( 'init', [ $this, 'register_runtime' ] );
		add_action( 'save_post', [ $this->invalidator, 'post_changed' ], 20, 2 );
		add_action( 'deleted_post', [ $this->invalidator, 'post_changed' ], 20, 2 );
		add_action( 'added_post_meta', [ $this->invalidator, 'post_meta_changed' ], 20, 3 );
		add_action( 'updated_post_meta', [ $this->invalidator, 'post_meta_changed' ], 20, 3 );
		add_action( 'deleted_post_meta', [ $this->invalidator, 'post_meta_changed' ], 20, 3 );
		add_action( 'set_object_terms', [ $this->invalidator, 'terms_changed' ], 20, 1 );
		add_action( 'eit_collection_entity_changed', [ $this->invalidator, 'cct_changed' ], 10, 2 );
		add_action( 'eit_collection_normalized_values_changed', [ $this->invalidator, 'normalized_changed' ], 10, 2 );
	}

	public function register_runtime() {
		add_shortcode( 'eit_collection', [ new CollectionRenderer(), 'shortcode' ] );
	}
}
