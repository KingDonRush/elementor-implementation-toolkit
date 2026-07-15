<?php
/**
 * Persists governed Entry values through WordPress and CCT APIs.
 */

namespace EIT\Entry;

use EIT\CCT\Repository as CctRepository;
use EIT\Infrastructure\NormalizedValueStore;
use EIT\Infrastructure\Transaction;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryStorageGateway {

	private $cct;
	private $normalized;
	private $transaction;

	public function __construct( CctRepository $cct = null, NormalizedValueStore $normalized = null, Transaction $transaction = null ) {
		$this->cct = $cct ?: new CctRepository();
		$this->normalized = $normalized ?: new NormalizedValueStore();
		$this->transaction = $transaction ?: new Transaction();
	}

	public function item_context( array $contract, $item_id ) {
		$item_id = absint( $item_id );
		if ( ! $item_id ) {
			return null;
		}
		if ( 'cpt' === ( $contract['entity']['strategy'] ?? '' ) ) {
			$post = get_post( $item_id );
			$slug = $contract['entity']['definition']['slug'] ?? '';
			return $post && $slug === $post->post_type
				? [ 'id' => $item_id, 'author_id' => (int) $post->post_author, 'status' => $this->from_post_status( $post->post_status ) ]
				: null;
		}
		if ( 'cct' === ( $contract['entity']['strategy'] ?? '' ) ) {
			$item = $this->cct->get( $contract['entity']['definition']['slug'] ?? '', $item_id );
			return $item ? [ 'id' => $item_id, 'author_id' => (int) ( $item['author_id'] ?? 0 ), 'status' => $item['status'] ] : null;
		}
		return null;
	}

	public function load_values( array $contract, $item_id ) {
		$context = $this->item_context( $contract, $item_id );
		if ( ! $context ) {
			return new \WP_Error( 'eit_entry_item_not_found', __( 'The requested entry does not belong to this Surface.', 'elementor-implementation-toolkit' ) );
		}
		$strategy = $contract['entity']['strategy'] ?? '';
		$record = 'cct' === $strategy ? $this->cct->get( $contract['entity']['definition']['slug'], $item_id ) : null;
		$values = [];
		foreach ( $contract['fields'] ?? [] as $field ) {
			$field_id = $field['id'];
			if ( 'relation' === $field['type'] ) {
				$values[ $field_id ] = $this->normalized->relation_targets( $contract['blueprint_id'], $field_id, $item_id );
			} elseif ( 'repeatable_group' === $field['type'] ) {
				$rows = $this->normalized->multivalue_rows( $contract['blueprint_id'], $field_id, $item_id );
				$values[ $field_id ] = array_column( $rows, 'value' );
			} elseif ( 'taxonomy' === $field['type'] && 'cpt' === $strategy ) {
				$values[ $field_id ] = wp_get_object_terms( $item_id, $field['taxonomy']['slug'] ?? $field['storage']['key'], [ 'fields' => 'ids' ] );
			} else {
				$stored = 'cpt' === $strategy ? get_post_meta( $item_id, $field['storage']['key'], true ) : ( $record[ $field['storage']['key'] ] ?? null );
				$values[ $field_id ] = $this->hydrate_value( $stored, $field );
			}
		}
		return [ 'values' => $values, 'item' => $context, 'content' => 'cpt' === $strategy ? (string) get_post_field( 'post_content', $item_id ) : '' ];
	}

	public function save( array $contract, array $values, $item_id, $status, $actor_id, $content = null ) {
		return $this->transaction->run(
			function () use ( $contract, $values, $item_id, $status, $actor_id, $content ) {
				$result = 'cpt' === ( $contract['entity']['strategy'] ?? '' )
					? $this->save_cpt( $contract, $values, $item_id, $status, $actor_id, $content )
					: $this->save_cct( $contract, $values, $item_id, $status, $actor_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$normalized = $this->save_normalized( $contract, $values, $result );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}
				$this->attach_media( $contract, $values, $result );
				return $result;
			}
		);
	}

	private function save_cpt( array $contract, array $values, $item_id, $status, $actor_id, $content ) {
		$definition = $contract['entity']['definition'];
		$title = sanitize_text_field( $values[ $contract['title_field_id'] ] ?? '' );
		$post = [ 'post_type' => $definition['slug'], 'post_title' => $title, 'post_status' => $this->to_post_status( $status ) ];
		if ( null !== $content && in_array( $contract['entity']['mode'] ?? '', [ 'editorial', 'hybrid' ], true ) ) {
			$post['post_content'] = wp_kses_post( $content );
		}
		if ( $item_id ) {
			$post['ID'] = absint( $item_id );
			$id = wp_update_post( wp_slash( $post ), true );
		} else {
			$post['post_author'] = absint( $actor_id );
			$id = wp_insert_post( wp_slash( $post ), true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( $contract['fields'] as $field ) {
			if ( ! array_key_exists( $field['id'], $values ) || in_array( $field['type'], [ 'relation', 'repeatable_group' ], true ) ) {
				continue;
			}
			if ( 'taxonomy' === $field['type'] ) {
				$terms = wp_set_object_terms( $id, $values[ $field['id'] ], $field['taxonomy']['slug'] ?? $field['storage']['key'], false );
				if ( is_wp_error( $terms ) ) {
					return $terms;
				}
			} else {
				update_post_meta( $id, $field['storage']['key'], $values[ $field['id'] ] );
			}
		}
		return $id;
	}

	private function save_cct( array $contract, array $values, $item_id, $status, $actor_id ) {
		$existing = $item_id ? $this->cct->get( $contract['entity']['definition']['slug'], $item_id ) : null;
		$record = [
			'title' => sanitize_text_field( $values[ $contract['title_field_id'] ] ?? '' ),
			'status' => $status,
			'author_id' => absint( $existing['author_id'] ?? $actor_id ),
		];
		foreach ( $contract['fields'] as $field ) {
			if ( array_key_exists( $field['id'], $values ) && ! in_array( $field['type'], [ 'relation', 'repeatable_group' ], true ) ) {
				$record[ $field['storage']['key'] ] = $this->storage_value( $values[ $field['id'] ], $field );
			}
		}
		return $this->cct->save( $contract['entity']['definition']['slug'], $record, $item_id );
	}

	private function save_normalized( array $contract, array $values, $item_id ) {
		foreach ( $contract['fields'] as $field ) {
			if ( ! array_key_exists( $field['id'], $values ) ) {
				continue;
			}
			if ( 'relation' === $field['type'] ) {
				$result = $this->normalized->replace_relation_targets( $contract['blueprint_id'], $field['id'], $item_id, $values[ $field['id'] ] );
			} elseif ( 'repeatable_group' === $field['type'] ) {
				$result = $this->normalized->replace_multivalue_rows( $contract['blueprint_id'], $field['id'], $item_id, $values[ $field['id'] ] );
			} else {
				continue;
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	private function storage_value( $value, array $field ) {
		if ( 'money' === $field['type'] ) {
			return $value['amount'] ?? null;
		}
		if ( in_array( $field['type'], [ 'image', 'file' ], true ) ) {
			return $value['id'] ?? null;
		}
		if ( 'gallery' === $field['type'] ) {
			return array_column( $value, 'id' );
		}
		if ( is_array( $value ) && 'multiple_choice' !== $field['type'] ) {
			return wp_json_encode( $value );
		}
		return $value;
	}

	private function attach_media( array $contract, array $values, $item_id ) {
		foreach ( $contract['fields'] as $field ) {
			if ( ! isset( $values[ $field['id'] ] ) || ! in_array( $field['type'], [ 'image', 'gallery', 'file' ], true ) ) {
				continue;
			}
			$items = 'gallery' === $field['type'] ? (array) $values[ $field['id'] ] : [ $values[ $field['id'] ] ];
			foreach ( $items as $item ) {
				$attachment_id = absint( is_array( $item ) ? ( $item['id'] ?? 0 ) : $item );
				if ( ! $attachment_id ) {
					continue;
				}
				if ( 'cpt' === ( $contract['entity']['strategy'] ?? '' ) ) {
					wp_update_post( [ 'ID' => $attachment_id, 'post_parent' => $item_id ] );
				} else {
					update_post_meta( $attachment_id, '_eit_entry_cct_owner', $contract['entity_id'] . ':' . $item_id );
				}
				delete_post_meta( $attachment_id, '_eit_entry_pending_surface' );
				delete_post_meta( $attachment_id, '_eit_entry_pending_actor' );
				delete_post_meta( $attachment_id, '_eit_entry_pending_at' );
			}
		}
	}

	private function hydrate_value( $value, array $field ) {
		if ( 'money' === $field['type'] ) {
			return [ 'amount' => null === $value ? '' : (float) $value, 'currency' => $field['validation']['currency'] ?? 'USD' ];
		}
		if ( in_array( $field['type'], [ 'image', 'file' ], true ) ) {
			return $value ? [ 'id' => absint( $value ) ] : null;
		}
		if ( 'gallery' === $field['type'] ) {
			return array_map(
				function ( $item ) {
					return [ 'id' => absint( is_array( $item ) ? ( $item['id'] ?? 0 ) : $item ) ];
				},
				(array) $value
			);
		}
		if ( is_string( $value ) && in_array( $field['type'], [ 'availability', 'address', 'geopoint', 'schedule', 'taxonomy' ], true ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : [];
		}
		return $value;
	}

	private function to_post_status( $status ) {
		return [ 'draft' => 'draft', 'review' => 'pending', 'publish' => 'publish', 'archived' => 'eit_archived' ][ $status ] ?? 'draft';
	}

	private function from_post_status( $status ) {
		return [ 'draft' => 'draft', 'pending' => 'review', 'publish' => 'publish', 'eit_archived' => 'archived' ][ $status ] ?? 'draft';
	}
}
