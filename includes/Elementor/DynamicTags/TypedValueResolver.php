<?php
/**
 * Resolves stable Field IDs into typed values across supported entity contexts.
 */

namespace EIT\Elementor\DynamicTags;

use EIT\CCT\CurrentItemContext;
use EIT\CCT\Repository as CctRepository;
use EIT\Elementor\Contracts\PublishedContractCatalog;
use EIT\Infrastructure\NormalizedValueStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TypedValueResolver {

	private $catalog;
	private $cct;
	private $normalized;

	public function __construct( ?PublishedContractCatalog $catalog = null ) {
		$this->catalog = $catalog ?: new PublishedContractCatalog();
		$this->cct = new CctRepository();
		$this->normalized = new NormalizedValueStore();
	}

	public function resolve( $field_id, $category = 'text', $entity_id = '' ) {
		$context = $this->catalog->field( $field_id, $entity_id );
		if ( ! $context || ! $this->can_read( $context['field'] ) ) {
			return null;
		}
		return [
			'field' => $context['field'],
			'entity' => $context['entity'],
			'value' => $this->format( $this->raw_value( $context ), $context['field'], $category ),
		];
	}

	public function format( $value, array $field, $category ) {
		if ( 'number' === $category ) {
			$value = 'money' === ( $field['type'] ?? '' ) && is_array( $value ) ? ( $value['amount'] ?? null ) : $value;
			return is_numeric( $value ) ? (float) $value : null;
		}
		if ( 'image' === $category ) {
			return $this->image( $value );
		}
		if ( 'gallery' === $category ) {
			return array_values( array_filter( array_map( [ $this, 'image' ], (array) $value ) ) );
		}
		if ( 'color' === $category ) {
			return sanitize_hex_color( (string) $value ) ?: '';
		}
		if ( 'url' === $category ) {
			return $this->url( $value, $field );
		}
		return $this->text( $value, $field );
	}

	private function raw_value( array $context ) {
		$field = $context['field'];
		$entity = $context['entity'];
		$current = CurrentItemContext::current();
		if ( is_array( $current['item'] ?? null ) ) {
			$item = is_array( $current['item']['values'] ?? null ) ? $current['item']['values'] : $current['item'];
			if ( array_key_exists( $field['id'], $item ) ) {
				return $item[ $field['id'] ];
			}
			if ( array_key_exists( $field['storage']['key'], $item ) ) {
				return $item[ $field['storage']['key'] ];
			}
		}

		$strategy = $entity['strategy'] ?? '';
		if ( 'cpt' === $strategy ) {
			return $this->cpt_value( $context );
		}
		if ( 'cct' === $strategy && $this->is_editor() ) {
			$result = $this->cct->query( $entity['definition']['slug'] ?? '', [ 'status' => [ 'publish', 'draft' ], 'page' => 1, 'per_page' => 1 ] );
			$item = $result['items'][0] ?? [];
			return $item[ $field['storage']['key'] ] ?? null;
		}
		if ( 'woocommerce' === ( $entity['adapter']['id'] ?? '' ) && class_exists( '\\EIT\\Woo\\WooValueGateway' ) ) {
			return ( new \EIT\Woo\WooValueGateway() )->read_current( $field['storage']['key'] );
		}
		return null;
	}

	private function cpt_value( array $context ) {
		$field = $context['field'];
		$entity = $context['entity'];
		$post_id = get_the_ID();
		$slug = $entity['definition']['slug'] ?? '';
		if ( ! $post_id || $slug !== get_post_type( $post_id ) ) {
			if ( ! $this->is_editor() ) {
				return null;
			}
			$posts = get_posts( [ 'post_type' => $slug, 'post_status' => [ 'publish', 'draft' ], 'posts_per_page' => 1, 'fields' => 'ids' ] );
			$post_id = absint( $posts[0] ?? 0 );
		}
		if ( ! $post_id ) {
			return null;
		}
		if ( 'taxonomy' === ( $field['type'] ?? '' ) ) {
			return wp_get_object_terms( $post_id, $field['taxonomy']['slug'] ?? $field['storage']['key'], [ 'fields' => 'ids' ] );
		}
		if ( 'relation' === ( $field['type'] ?? '' ) ) {
			return $this->normalized->relation_targets( $context['blueprint_id'], $field['id'], $post_id );
		}
		return get_post_meta( $post_id, $field['storage']['key'], true );
	}

	private function text( $value, array $field ) {
		if ( 'taxonomy' === ( $field['type'] ?? '' ) ) {
			$terms = $this->taxonomy_terms( $value, $field );
			if ( $terms ) {
				return implode( ', ', array_map( fn( $term ) => sanitize_text_field( $term->name ), $terms ) );
			}
		}
		if ( 'money' === ( $field['type'] ?? '' ) && is_array( $value ) ) {
			$amount = is_numeric( $value['amount'] ?? null ) ? (float) $value['amount'] : null;
			return null === $amount ? '' : trim( (string) ( $value['currency'] ?? '' ) . ' ' . $amount );
		}
		if ( is_array( $value ) ) {
			$values = array_map( fn( $item ) => $this->text( $item, $field ), $value );
			return implode( ', ', array_filter( $values, fn( $item ) => '' !== $item ) );
		}
		foreach ( $field['validation']['options'] ?? [] as $option ) {
			if ( (string) ( $option['value'] ?? '' ) === (string) $value ) {
				return sanitize_text_field( $option['label'] ?? $value );
			}
		}
		return wp_strip_all_tags( (string) $value );
	}

	private function image( $value ) {
		$id = absint( is_array( $value ) ? ( $value['id'] ?? 0 ) : $value );
		$url = is_array( $value ) ? esc_url_raw( $value['url'] ?? '' ) : '';
		if ( $id && ! $url ) {
			$url = wp_get_attachment_url( $id ) ?: '';
		}
		return $id || $url ? [ 'id' => $id ?: null, 'url' => $url ] : null;
	}

	private function url( $value, array $field ) {
		if ( 'taxonomy' === ( $field['type'] ?? '' ) ) {
			$term = $this->taxonomy_terms( $value, $field )[0] ?? null;
			$link = $term ? get_term_link( $term ) : '';
			return is_wp_error( $link ) ? '' : esc_url_raw( $link );
		}
		if ( in_array( $field['type'] ?? '', [ 'image', 'file' ], true ) ) {
			$image = $this->image( $value );
			return $image['url'] ?? '';
		}
		if ( 'email' === ( $field['type'] ?? '' ) && is_email( $value ) ) {
			return 'mailto:' . sanitize_email( $value );
		}
		if ( 'phone' === ( $field['type'] ?? '' ) ) {
			return 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $value );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		return esc_url_raw( (string) $value );
	}

	private function taxonomy_terms( $value, array $field ) {
		$taxonomy = sanitize_key( $field['taxonomy']['slug'] ?? '' );
		if ( ! $taxonomy ) {
			$taxonomy = 'category' === ( $field['storage']['key'] ?? '' ) ? 'product_cat' : ( 'tag' === ( $field['storage']['key'] ?? '' ) ? 'product_tag' : '' );
		}
		$values = array_values( array_filter( (array) $value, fn( $item ) => '' !== trim( (string) $item ) ) );
		if ( ! $taxonomy || ! $values ) {
			return [];
		}
		$numeric = ! array_filter( $values, fn( $item ) => ! ctype_digit( (string) $item ) );
		$args = [ 'taxonomy' => $taxonomy, 'hide_empty' => false ];
		$args[ $numeric ? 'include' : 'slug' ] = $numeric ? array_map( 'absint', $values ) : array_map( 'sanitize_title', $values );
		$terms = get_terms( $args );
		return is_wp_error( $terms ) ? [] : array_values( $terms );
	}

	private function can_read( array $field ) {
		if ( ! empty( $field['exposure']['public'] ) ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		$roles = array_values( array_filter( array_map( 'sanitize_key', $field['exposure']['roles'] ?? [] ) ) );
		return $roles ? (bool) array_intersect( $roles, $user->roles ) : current_user_can( 'edit_posts' );
	}

	private function is_editor() {
		return class_exists( '\\Elementor\\Plugin' )
			&& \Elementor\Plugin::$instance
			&& \Elementor\Plugin::$instance->editor
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}
