<?php
/**
 * Resolves labels for already-selected relation identities without raw keys.
 */

namespace EIT\Entry;

use EIT\CCT\Repository as CctRepository;
use EIT\Woo\WooValueGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RelationLabelResolver {

	private $cct;
	private $references;
	private $woo;

	public function __construct( ?EntryReferenceValidator $references = null, ?CctRepository $cct = null, ?WooValueGateway $woo = null ) {
		$this->references = $references ?: new EntryReferenceValidator();
		$this->cct = $cct ?: new CctRepository();
		$this->woo = $woo ?: new WooValueGateway();
	}

	public function options( array $contract, array $field, $value, $item_id = 0 ) {
		$ids = $this->identities( $value );
		if ( ! $ids ) {
			return [];
		}
		$authorized = $this->references->validate( [ 'blueprint_id' => $contract['blueprint_id'] ?? '', 'fields' => [ $field ] ], [ $field['id'] => array_map( fn( $id ) => [ 'id' => $id ], $ids ) ], ! is_user_logged_in(), $item_id );
		if ( is_wp_error( $authorized ) ) {
			return array_map( fn( $id ) => [ 'value' => $id, 'label' => __( 'Unavailable selection', 'elementor-implementation-toolkit' ) ], $ids );
		}
		$target = $field['relation']['target'] ?? [];
		if ( 'cpt' === ( $target['strategy'] ?? '' ) ) {
			$labels = $this->cpt_labels( $target, $ids );
		} elseif ( 'cct' === ( $target['strategy'] ?? '' ) ) {
			$labels = $this->cct_labels( $target, $ids );
		} elseif ( 'woocommerce' === ( $target['adapter']['id'] ?? '' ) ) {
			$labels = $this->woo_labels( $ids );
		} else {
			$labels = apply_filters( 'eit_entry_relation_option_labels', [], $target, $ids, $contract, $field );
		}
		$labels = is_array( $labels ) ? $labels : [];
		return array_map(
			fn( $id ) => [
				'value' => $id,
				'label' => sanitize_text_field( $labels[ $id ] ?? sprintf( __( 'Item %s', 'elementor-implementation-toolkit' ), $id ) ),
			],
			$ids
		);
	}

	public function identities( $value ) {
		$ids = [];
		foreach ( array_slice( (array) $value, 0, 100 ) as $item ) {
			$id = is_array( $item ) ? ( $item['id'] ?? $item['target_id'] ?? $item['value'] ?? '' ) : $item;
			$id = trim( (string) $id );
			if ( '' !== $id && ! isset( $ids[ $id ] ) ) {
				$ids[ $id ] = $id;
			}
		}
		return array_values( $ids );
	}

	private function cpt_labels( array $target, array $ids ) {
		$posts = get_posts(
			[
				'post_type' => sanitize_key( $target['definition']['slug'] ?? '' ),
				'post_status' => 'any',
				'post__in' => array_map( 'absint', $ids ),
				'posts_per_page' => count( $ids ),
				'orderby' => 'post__in',
				'no_found_rows' => true,
			]
		);
		$labels = [];
		foreach ( $posts as $post ) {
			$labels[ (string) $post->ID ] = get_the_title( $post );
		}
		return $labels;
	}

	private function cct_labels( array $target, array $ids ) {
		$result = $this->cct->query(
			$target['definition']['slug'] ?? '',
			[
				'include' => array_map( 'absint', $ids ),
				'status' => [ 'publish', 'draft', 'review', 'archived' ],
				'per_page' => count( $ids ),
			]
		);
		return array_column( $result['items'] ?? [], 'title', 'id' );
	}

	private function woo_labels( array $ids ) {
		$statuses = is_callable( 'wc_get_product_statuses' ) ? (array) call_user_func( 'wc_get_product_statuses' ) : [];
		$args = [ 'include' => array_map( 'absint', $ids ), 'limit' => count( $ids ) ];
		if ( $statuses ) {
			$args['status'] = array_keys( $statuses );
		}
		$products = function_exists( 'wc_get_products' )
			? wc_get_products( $args )
			: array_filter( array_map( [ $this->woo, 'get' ], array_map( 'absint', $ids ) ) );
		$labels = [];
		foreach ( $products as $product ) {
			if ( is_callable( [ $product, 'get_id' ] ) && is_callable( [ $product, 'get_name' ] ) ) {
				$labels[ (string) $product->get_id() ] = $product->get_name();
			}
		}
		return $labels;
	}
}
