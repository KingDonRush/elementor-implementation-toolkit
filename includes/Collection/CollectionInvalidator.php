<?php
/**
 * Maps content mutations to entity generation bumps for query cache safety.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionInvalidator {

	private $resolver;
	private $cache;

	public function __construct( CollectionSurfaceResolver $resolver = null, CollectionCache $cache = null ) {
		$this->resolver = $resolver ?: new CollectionSurfaceResolver();
		$this->cache = $cache ?: new CollectionCache();
	}

	public function post_changed( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( 'elementor_library' === $post->post_type ) {
			$this->presentation_changed( $post->ID );
			return;
		}
		if ( 'product' === $post->post_type ) {
			$this->adapter_changed( 'woocommerce' );
			return;
		}
		$this->source_changed( 'cpt', $post->post_type );
	}

	public function post_meta_changed( $meta_id, $post_id, $meta_key ) {
		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return;
		}
		foreach ( $this->source_contracts( 'cpt', $post_type ) as $contract ) {
			$keys = array_column( $contract['fields'] ?? [], 'storage' );
			$keys = array_column( $keys, 'key' );
			if ( in_array( (string) $meta_key, $keys, true ) ) {
				$this->cache->bump( $contract['entity_id'] );
			}
		}
	}

	public function terms_changed( $object_id ) {
		$post_type = get_post_type( $object_id );
		if ( 'product' === $post_type ) {
			$this->adapter_changed( 'woocommerce' );
		} elseif ( $post_type ) {
			$this->source_changed( 'cpt', $post_type );
		}
	}

	public function cct_changed( $entity_id, $type ) {
		if ( $entity_id ) {
			$this->cache->bump( $entity_id );
			return;
		}
		$this->source_changed( 'cct', $type );
	}

	public function normalized_changed( $blueprint_id, $field_id ) {
		foreach ( $this->resolver->all() as $contract ) {
			if ( $blueprint_id !== ( $contract['blueprint_id'] ?? '' ) || ! in_array( $field_id, array_column( $contract['fields'] ?? [], 'id' ), true ) ) {
				continue;
			}
			$this->cache->bump( $contract['entity_id'] );
		}
	}

	private function source_changed( $strategy, $slug ) {
		foreach ( $this->source_contracts( $strategy, $slug ) as $contract ) {
			$this->cache->bump( $contract['entity_id'] );
		}
	}

	private function adapter_changed( $adapter_id ) {
		foreach ( $this->resolver->all() as $contract ) {
			if ( $adapter_id === ( $contract['entity']['adapter']['id'] ?? '' ) ) {
				$this->cache->bump( $contract['entity_id'] );
			}
		}
	}

	private function presentation_changed( $template_id ) {
		foreach ( $this->resolver->all() as $contract ) {
			if ( absint( $template_id ) === absint( $contract['presentation']['template_id'] ?? 0 ) ) {
				$this->cache->bump( $contract['entity_id'] );
			}
		}
	}

	private function source_contracts( $strategy, $slug ) {
		return array_values(
			array_filter(
				$this->resolver->all(),
				function ( $contract ) use ( $strategy, $slug ) {
					return $strategy === ( $contract['entity']['strategy'] ?? '' )
						&& $slug === ( $contract['entity']['definition']['slug'] ?? '' );
				}
			)
		);
	}
}
