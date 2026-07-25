<?php
/**
 * Resolves relation and taxonomy identities before Entry persistence.
 */

namespace EIT\Entry;

use EIT\CCT\Repository as CctRepository;
use EIT\Infrastructure\NormalizedValueStore;
use EIT\Woo\WooValueGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryReferenceValidator {

	private $taxonomy_checker;
	private $relation_checker;
	private $normalized;

	public function __construct( ?callable $taxonomy_checker = null, ?callable $relation_checker = null, ?NormalizedValueStore $normalized = null ) {
		$this->taxonomy_checker = $taxonomy_checker;
		$this->relation_checker = $relation_checker;
		$this->normalized = $normalized ?: new NormalizedValueStore();
	}

	public function validate( array $contract, array $values, $guest = false, $item_id = 0 ) {
		$errors = [];
		foreach ( $contract['fields'] ?? [] as $field ) {
			$field_id = $field['id'] ?? '';
			if ( ! array_key_exists( $field_id, $values ) ) {
				continue;
			}
			if ( 'taxonomy' === ( $field['type'] ?? '' ) ) {
				$this->validate_ids( $contract, $field, (array) $values[ $field_id ], $guest, 'taxonomy', $errors );
			} elseif ( 'relation' === ( $field['type'] ?? '' ) ) {
				$ids = array_map( fn( $target ) => is_array( $target ) ? ( $target['id'] ?? '' ) : $target, (array) $values[ $field_id ] );
				$cardinality = $field['relation']['cardinality'] ?? 'many_to_many';
				if ( in_array( $cardinality, [ 'many_to_one', 'one_to_one' ], true ) && 1 < count( array_unique( $ids ) ) ) {
					$errors[ $field_id ] = __( 'This relation accepts only one target.', 'elementor-implementation-toolkit' );
					continue;
				}
				if ( in_array( $cardinality, [ 'one_to_one', 'one_to_many' ], true ) && ! $this->targets_are_available( $contract, $field, $ids, $item_id ) ) {
					$errors[ $field_id ] = __( 'A selected target already belongs to another item in this relation.', 'elementor-implementation-toolkit' );
					continue;
				}
				$this->validate_ids( $contract, $field, $ids, $guest, 'relation', $errors );
			}
		}
		return $errors ? $this->error( $errors ) : true;
	}

	private function targets_are_available( array $contract, array $field, array $target_ids, $item_id ) {
		$sources = $this->normalized->source_ids_for_relation_targets(
			$contract['blueprint_id'] ?? '',
			$field['id'] ?? '',
			array_values( array_unique( array_map( 'strval', $target_ids ) ) )
		);
		$current = absint( $item_id ) ? (string) absint( $item_id ) : '';
		return ! array_filter( $sources, fn( $source_id ) => '' === $current || $current !== (string) $source_id );
	}

	private function validate_ids( array $contract, array $field, array $ids, $guest, $kind, array &$errors ) {
		foreach ( array_unique( array_map( 'strval', $ids ) ) as $id ) {
			$allowed = 'taxonomy' === $kind
				? $this->taxonomy_allowed( $contract, $field, $id, $guest )
				: $this->relation_allowed( $contract, $field, $id, $guest );
			if ( true !== $allowed ) {
				$errors[ $field['id'] ] = __( 'A selected target is unavailable or outside your permitted scope.', 'elementor-implementation-toolkit' );
				break;
			}
		}
	}

	private function taxonomy_allowed( array $contract, array $field, $term_id, $guest ) {
		if ( $this->taxonomy_checker ) {
			return true === call_user_func( $this->taxonomy_checker, $contract, $field, $term_id, $guest );
		}
		$taxonomy = $this->taxonomy_slug( $field );
		$term_id = absint( $term_id );
		if ( ! $term_id || ! $taxonomy || ! taxonomy_exists( $taxonomy ) || ! term_exists( $term_id, $taxonomy ) ) {
			return false;
		}
		$post_type = $this->target_post_type( $contract['entity'] ?? [] );
		if ( $post_type && ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
			return false;
		}
		$object = get_taxonomy( $taxonomy );
		if ( ! $object ) {
			return false;
		}
		$allowed = $guest
			? ! empty( $object->public )
			: current_user_can( $object->cap->assign_terms ?? 'manage_categories' );
		return (bool) apply_filters( 'eit_entry_taxonomy_target_allowed', $allowed, $contract, $field, $term_id, get_current_user_id() );
	}

	private function relation_allowed( array $contract, array $field, $target_id, $guest ) {
		if ( $this->relation_checker ) {
			return true === call_user_func( $this->relation_checker, $contract, $field, $target_id, $guest );
		}
		$relation = $field['relation'] ?? [];
		$target = $relation['target'] ?? [];
		$target_id = absint( $target_id );
		if ( ! $target_id || empty( $relation['target_entity_id'] ) || ! $target ) {
			return false;
		}
		$context = $this->relation_context( $target, $target_id );
		if ( ! $context ) {
			return false;
		}
		$allowed = $this->can_read_target( $target, $target_id, $context, $guest );
		if ( $allowed && 'own' === ( $relation['ownership'] ?? 'any' ) ) {
			$allowed = ! $guest && ( get_current_user_id() === (int) $context['author_id'] || current_user_can( 'manage_options' ) );
		}
		if ( $allowed && 'assigned' === ( $relation['object_scope'] ?? 'entity' ) ) {
			$allowed = (bool) apply_filters( 'eit_entry_relation_scope_allowed', false, $contract, $field, $context, get_current_user_id() );
		}
		return (bool) apply_filters( 'eit_entry_relation_target_allowed', $allowed, $contract, $field, $context, get_current_user_id() );
	}

	private function relation_context( array $target, $target_id ) {
		if ( 'cct' === ( $target['strategy'] ?? '' ) ) {
			$item = ( new CctRepository() )->get( $target['definition']['slug'] ?? '', $target_id );
			return $item ? [ 'id' => $target_id, 'author_id' => (int) ( $item['author_id'] ?? 0 ), 'status' => $item['status'] ?? '' ] : null;
		}
		if ( 'woocommerce' === ( $target['adapter']['id'] ?? '' ) ) {
			$product = ( new WooValueGateway() )->get( $target_id );
			return $product && is_callable( [ $product, 'get_status' ] )
				? [ 'id' => $target_id, 'author_id' => (int) get_post_field( 'post_author', $target_id ), 'status' => $product->get_status() ]
				: null;
		}
		if ( 'cpt' === ( $target['strategy'] ?? '' ) ) {
			$post = get_post( $target_id );
			return $post && ( $target['definition']['slug'] ?? '' ) === $post->post_type
				? [ 'id' => $target_id, 'author_id' => (int) $post->post_author, 'status' => $post->post_status ]
				: null;
		}
		$context = apply_filters( 'eit_entry_relation_target_context', null, $target, $target_id );
		return is_array( $context ) && ! empty( $context['id'] ) ? $context : null;
	}

	private function can_read_target( array $target, $target_id, array $context, $guest ) {
		$public = ! empty( $target['definition']['public'] ) && 'publish' === ( $context['status'] ?? '' );
		if ( $guest ) {
			return $public;
		}
		if ( 'cpt' === ( $target['strategy'] ?? '' ) || 'woocommerce' === ( $target['adapter']['id'] ?? '' ) ) {
			return current_user_can( 'read_post', $target_id );
		}
		return $public || get_current_user_id() === (int) $context['author_id'] || current_user_can( 'manage_options' );
	}

	private function taxonomy_slug( array $field ) {
		$key = sanitize_key( $field['storage']['key'] ?? '' );
		return sanitize_key( $field['taxonomy']['slug'] ?? ( 'category' === $key ? 'product_cat' : ( 'tag' === $key ? 'product_tag' : '' ) ) );
	}

	private function target_post_type( array $entity ) {
		return 'woocommerce' === ( $entity['adapter']['id'] ?? '' ) ? 'product' : ( 'cpt' === ( $entity['strategy'] ?? '' ) ? sanitize_key( $entity['definition']['slug'] ?? '' ) : '' );
	}

	private function error( array $fields ) {
		return new \WP_Error( 'eit_entry_reference_forbidden', __( 'One or more selected targets are outside this Entry Surface.', 'elementor-implementation-toolkit' ), [ 'status' => 403, 'fields' => $fields ] );
	}
}
