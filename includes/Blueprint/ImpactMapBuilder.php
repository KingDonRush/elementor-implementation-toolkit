<?php
/**
 * Resolves concrete WordPress, Elementor and adapter consumers for an impact plan.
 */

namespace EIT\Blueprint;

use EIT\CCT\Repository as CctRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImpactMapBuilder {

	public function build( array $blueprint, array $impact ) {
		$affected = array_fill_keys( $impact['affected_node_ids'] ?? [], true );
		$tokens = [ (string) ( $blueprint['id'] ?? '' ) ];
		$fields = [];
		$adapters = [];
		$records = [];
		$explicit_documents = [];
		if ( ! empty( $blueprint['origin']['source_key'] ) && 'elementor_document' !== ( $blueprint['origin']['source'] ?? '' ) ) {
			$tokens[] = (string) $blueprint['origin']['source_key'];
		}
		foreach ( $blueprint['nodes'] ?? [] as $node ) {
			$node_id = (string) ( $node['id'] ?? '' );
			if ( empty( $affected ) || isset( $affected[ $node_id ] ) ) {
				$tokens[] = $node_id;
			}
			if ( 'field_group' === ( $node['type'] ?? '' ) && ( empty( $affected ) || isset( $affected[ $node_id ] ) ) ) {
				foreach ( $node['config']['fields'] ?? [] as $field ) {
					$fields[] = [ 'id' => (string) ( $field['id'] ?? '' ), 'name' => (string) ( $field['name'] ?? '' ), 'type' => sanitize_key( $field['type'] ?? '' ) ];
					$tokens[] = (string) ( $field['id'] ?? '' );
					$tokens = array_merge( $tokens, array_map( 'strval', array_merge( [ $field['storage']['key'] ?? '' ], (array) ( $field['storage']['aliases'] ?? [] ) ) ) );
				}
			}
			if ( 'adapter' === ( $node['type'] ?? '' ) ) {
				$adapters[] = [ 'node_id' => $node_id, 'id' => sanitize_key( $node['config']['adapter_id'] ?? '' ), 'name' => (string) ( $node['name'] ?? '' ) ];
			}
			if ( 'presentation' === ( $node['type'] ?? '' ) ) {
				$adapters[] = [ 'node_id' => $node_id, 'id' => sanitize_key( $node['config']['adapter'] ?? 'elementor' ), 'name' => (string) ( $node['name'] ?? '' ) ];
				$document_id = absint( $node['config']['document_id'] ?? $node['config']['template_id'] ?? 0 );
				if ( $document_id ) {
					$explicit_documents[] = $document_id;
				}
			}
			if ( 'entity' === ( $node['type'] ?? '' ) && ( empty( $affected ) || isset( $affected[ $node_id ] ) ) ) {
				$tokens[] = (string) ( $node['config']['slug'] ?? '' );
				$records[] = $this->entity_records( $node );
			}
		}

		$usage = $this->elementor_usage( array_values( array_filter( array_unique( $tokens ) ) ), $explicit_documents );
		return [
			'pages' => $usage['pages'],
			'templates' => $usage['templates'],
			'widgets' => $usage['widgets'],
			'fields' => $fields,
			'records' => $records,
			'adapters' => array_values( array_unique( $adapters, SORT_REGULAR ) ),
			'revision_backup_count' => $usage['revision_backup_count'],
			'summary' => [
				'pages' => count( $usage['pages'] ),
				'templates' => count( $usage['templates'] ),
				'widgets' => count( $usage['widgets'] ),
				'fields' => count( $fields ),
				'records' => array_sum( array_column( $records, 'count' ) ),
				'adapters' => count( array_unique( array_column( $adapters, 'id' ) ) ),
			],
		];
	}

	private function elementor_usage( array $tokens, array $explicit_documents ) {
		$post_types = array_values( get_post_types( [], 'names' ) );
		$document_ids = get_posts(
			[
				'post_type' => $post_types,
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page' => -1,
				'fields' => 'ids',
				'meta_key' => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Administrative impact inventory.
				'meta_value' => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Administrative impact inventory.
				'no_found_rows' => true,
			]
		);
		$pages = [];
		$templates = [];
		$widgets = [];
		$revision_count = 0;
		foreach ( $document_ids as $document_id ) {
			$data = (string) get_post_meta( $document_id, '_elementor_data', true );
			$matched = in_array( (int) $document_id, array_map( 'intval', $explicit_documents ), true );
			$decoded = json_decode( $data, true );
			$matched_tokens = $this->matching_tokens( $decoded, $tokens );
			$matched = $matched || ! empty( $matched_tokens );
			$document_widgets = $this->toolkit_widgets( $decoded );
			if ( ! $matched ) {
				continue;
			}
			$post = get_post( $document_id );
			$document = [ 'id' => (int) $document_id, 'title' => get_the_title( $document_id ), 'status' => $post->post_status, 'matched_contract_ids' => $matched_tokens ];
			if ( 'elementor_library' === $post->post_type ) {
				$document['template_type'] = sanitize_key( get_post_meta( $document_id, '_elementor_template_type', true ) );
				$templates[] = $document;
			} else {
				$document['post_type'] = $post->post_type;
				$pages[] = $document;
			}
			foreach ( $document_widgets as $widget ) {
				$widgets[] = array_merge( [ 'document_id' => (int) $document_id ], $widget );
			}
			$revision_count += count( wp_get_post_revisions( $document_id, [ 'fields' => 'ids' ] ) );
		}
		return [ 'pages' => $pages, 'templates' => $templates, 'widgets' => $widgets, 'revision_backup_count' => $revision_count ];
	}

	private function toolkit_widgets( $decoded ) {
		$widgets = [];
		$walk = function ( $value ) use ( &$walk, &$widgets ) {
			if ( ! is_array( $value ) ) {
				return;
			}
			if ( isset( $value['widgetType'] ) && 0 === strpos( (string) $value['widgetType'], 'eit-' ) ) {
				$widgets[] = [ 'id' => sanitize_key( $value['id'] ?? '' ), 'type' => sanitize_key( $value['widgetType'] ) ];
			}
			foreach ( $value as $child ) {
				$walk( $child );
			}
		};
		$walk( $decoded );
		return $widgets;
	}

	private function matching_tokens( $document, array $tokens ) {
		$matches = [];
		$walk = function ( $value ) use ( &$walk, &$matches, $tokens ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $child ) {
					$walk( $child );
				}
				return;
			}
			if ( ! is_string( $value ) ) {
				return;
			}
			$decoded_value = rawurldecode( $value );
			foreach ( $tokens as $token ) {
				if ( '' === $token ) {
					continue;
				}
				$exact = hash_equals( $token, $value );
				$stable_id = Uuid::is_valid( $token ) && 1 === preg_match( '/(?<![0-9a-f-])' . preg_quote( $token, '/' ) . '(?![0-9a-f-])/i', $decoded_value );
				$binding = 1 === preg_match( '/["\'](?:key|field_id|collection_id|surface_id|preset_id)["\']\s*:\s*["\']' . preg_quote( $token, '/' ) . '["\']/i', $decoded_value );
				if ( $exact || $stable_id || $binding ) {
					$matches[ $token ] = true;
				}
			}
		};
		$walk( $document );
		return array_keys( $matches );
	}

	private function entity_records( array $entity ) {
		$config = $entity['config'] ?? [];
		$recommendation = ( new StorageRecommendation() )->recommend( $entity );
		$strategy = sanitize_key( $recommendation['selected'] ?? '' );
		$adapter_id = sanitize_key( $config['adapter_id'] ?? $config['owner'] ?? '' );
		$slug = sanitize_key( $config['slug'] ?? '' );
		$count = 0;
		if ( 'cpt' === $strategy && post_type_exists( $slug ) ) {
			$statuses = wp_count_posts( $slug );
			$count = array_sum( array_map( 'intval', array_intersect_key( (array) $statuses, array_flip( [ 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'eit_archived' ] ) ) ) );
		} elseif ( 'cct' === $strategy ) {
			$result = ( new CctRepository() )->query( $slug, [ 'status' => [ 'publish', 'draft', 'pending', 'private', 'eit_archived' ], 'per_page' => 1 ] );
			$count = (int) ( $result['total'] ?? 0 );
		} elseif ( 'adapter' === $strategy && 'woocommerce' === $adapter_id && function_exists( 'wc_get_products' ) ) {
			$result = wc_get_products( [ 'limit' => 1, 'paginate' => true, 'return' => 'ids' ] );
			$count = is_object( $result ) ? (int) $result->total : 0;
		}
		return [ 'entity_id' => (string) $entity['id'], 'name' => (string) $entity['name'], 'strategy' => $strategy, 'count' => $count ];
	}
}
