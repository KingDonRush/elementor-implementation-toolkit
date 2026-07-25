<?php
/**
 * Read-only Elementor document inventory and deterministic draft projection.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorDocumentImporter {

	private $canonicalizer;

	public function __construct( ?Canonicalizer $canonicalizer = null ) {
		$this->canonicalizer = $canonicalizer ?: new Canonicalizer();
	}

	public function inspect_all() {
		$result = [];
		foreach ( $this->document_ids() as $post_id ) {
			$result[ (string) $post_id ] = $this->import( $post_id );
		}
		return $result;
	}

	public function inventory() {
		$items = [];
		foreach ( $this->document_ids() as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$data = (string) get_post_meta( $post_id, '_elementor_data', true );
			$widgets = $this->toolkit_widgets( $data );
			$items[] = [
				'id' => (int) $post_id,
				'title' => get_the_title( $post_id ) ?: sprintf( 'Elementor #%d', $post_id ),
				'post_type' => $post->post_type,
				'status' => $post->post_status,
				'template_type' => sanitize_key( get_post_meta( $post_id, '_elementor_template_type', true ) ),
				'data_checksum' => hash( 'sha256', $data ),
				'data_bytes' => strlen( $data ),
				'toolkit_widgets' => $widgets,
				'active_toolkit_usage' => 'publish' === $post->post_status && ! empty( $widgets ),
				'revision_count' => count( wp_get_post_revisions( $post_id, [ 'fields' => 'ids' ] ) ),
			];
		}
		return $items;
	}

	public function snapshot() {
		$snapshot = [];
		foreach ( $this->inventory() as $item ) {
			$snapshot[ (string) $item['id'] ] = [
				'post_type' => $item['post_type'],
				'status' => $item['status'],
				'data_checksum' => $item['data_checksum'],
				'toolkit_widgets' => array_column( $item['toolkit_widgets'], 'type' ),
				'revision_count' => $item['revision_count'],
			];
		}
		return $snapshot;
	}

	public function import( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || 'builder' !== get_post_meta( $post->ID, '_elementor_edit_mode', true ) || 'revision' === $post->post_type ) {
			return null;
		}
		$seed = 'elementor-document:' . $post->ID;
		$blueprint_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'blueprint:' . $seed );
		$presentation_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'presentation:' . $seed );
		$route_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'route:' . $seed );
		$title = get_the_title( $post ) ?: sprintf( 'Elementor #%d', $post->ID );
		$permalink = 'publish' === $post->post_status ? get_permalink( $post ) : '';
		$blueprint = [
			'api_version' => BlueprintValidator::API_VERSION,
			'kind' => BlueprintValidator::KIND,
			'id' => $blueprint_id,
			'slug' => 'legacy-elementor-' . $post->ID,
			'name' => 'Imported ' . sanitize_text_field( $title ),
			'version' => 1,
			'nodes' => [
				[
					'id' => $presentation_id,
					'type' => 'presentation',
					'lane' => 'presentation',
					'name' => sanitize_text_field( $title ),
					'config' => [ 'adapter' => 'elementor', 'document_id' => (int) $post->ID, 'legacy_document' => true ],
				],
				[
					'id' => $route_id,
					'type' => 'route',
					'lane' => 'presentation',
					'name' => 'Existing document location',
					'config' => [ 'path' => $permalink ?: 'elementor-document/' . $post->ID, 'exposure' => $permalink ? 'public' : 'internal' ],
				],
			],
			'connections' => [
				[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'connection:' . $seed ), 'type' => 'routes', 'from' => $presentation_id, 'to' => $route_id ],
			],
			'origin' => [ 'mode' => 'legacy_shadow', 'source' => 'elementor_document', 'source_key' => (string) $post->ID, 'imported_at' => null ],
		];
		$blueprint['checksum'] = $this->canonicalizer->checksum( $blueprint );
		return $blueprint;
	}

	private function document_ids() {
		$post_types = array_values( get_post_types( [], 'names' ) );
		return get_posts(
			[
				'post_type' => $post_types,
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page' => -1,
				'fields' => 'ids',
				'meta_key' => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded administrative migration inventory.
				'meta_value' => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded administrative migration inventory.
				'orderby' => 'ID',
				'order' => 'ASC',
				'no_found_rows' => true,
				'suppress_filters' => false,
			]
		);
	}

	private function toolkit_widgets( $data ) {
		$document = json_decode( $data, true );
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
		$walk( $document );
		return $widgets;
	}
}
