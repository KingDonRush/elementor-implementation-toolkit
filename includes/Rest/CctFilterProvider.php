<?php
/**
 * Server-side CCT query and Elementor Loop Item rendering for Filter Controller.
 */

namespace EIT\Rest;

use EIT\CCT\CurrentItemContext;
use EIT\CCT\DefinitionManager;
use EIT\CCT\Repository;
use EIT\Support\CctLoopTemplateCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctFilterProvider {

	private $current_item;
	private $current_type;

	public function resolve( array $payload ) {
		$type = DefinitionManager::sanitize_slug( $payload['cctType'] ?? '' );
		$template_id = absint( $payload['templateId'] ?? 0 );
		$definition = DefinitionManager::get( $type, false );

		if ( ! $definition || empty( $definition['public'] ) || ! CctLoopTemplateCatalog::is_public_loop_item( $template_id ) ) {
			return new \WP_Error( 'eit_invalid_cct_provider', __( 'The CCT provider configuration is not publicly renderable.', 'elementor-implementation-toolkit' ), [ 'status' => 400 ] );
		}

		$query_args = $this->query_args( $payload );
		$result = ( new Repository() )->query( $type, $query_args );

		return [
			'ids'        => array_values( wp_list_pluck( $result['items'], 'id' ) ),
			'total'      => $result['total'],
			'page'       => $result['page'],
			'pages'      => $result['pages'],
			'perPage'    => $result['per_page'],
			'pagination' => [
				'hasPrevious' => $result['page'] > 1,
				'hasNext'     => $result['page'] < $result['pages'],
			],
			'html'       => $this->render_items( $type, $template_id, $result['items'] ),
		];
	}

	public function add_item_attributes( $attributes ) {
		if ( ! $this->current_item ) {
			return $attributes;
		}

		$attributes['data-eit-item'] = '1';
		$attributes['data-eit-cct'] = $this->current_type;
		$attributes['data-eit-cct-id'] = (string) $this->current_item['id'];

		foreach ( DefinitionManager::fields( $this->current_type ) as $key => $field ) {
			if ( empty( $field['filterable'] ) || ! isset( $this->current_item[ $key ] ) ) {
				continue;
			}

			$value = $this->current_item[ $key ];
			$attributes[ 'data-eit-' . str_replace( '_', '-', $key ) ] = is_array( $value ) ? implode( ' ', $value ) : (string) $value;
		}

		return $attributes;
	}

	private function query_args( array $payload ) {
		$args = [
			'status'   => [ 'publish' ],
			'page'     => max( 1, absint( $payload['page'] ?? 1 ) ),
			'per_page' => max( 1, min( 96, absint( $payload['perPage'] ?? 12 ) ) ),
			'filters'  => [],
		];

		foreach ( array_slice( (array) ( $payload['filters'] ?? [] ), 0, 40 ) as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$type = sanitize_key( $filter['type'] ?? '' );
			$key = sanitize_key( $filter['key'] ?? '' );
			$value = $filter['value'] ?? '';

			if ( 'search' === $type ) {
				$args['search'] = sanitize_text_field( $value );
				continue;
			}

			if ( in_array( $type, [ 'range', 'date' ], true ) && is_array( $value ) ) {
				$min = $value['min'] ?? $value['from'] ?? '';
				$max = $value['max'] ?? $value['to'] ?? '';
				if ( '' !== (string) $min ) {
					$args['filters'][] = [ 'key' => $key, 'value' => $min, 'compare' => 'gte' ];
				}
				if ( '' !== (string) $max ) {
					$args['filters'][] = [ 'key' => $key, 'value' => $max, 'compare' => 'lte' ];
				}
				continue;
			}

			$args['filters'][] = [
				'key'     => $key,
				'value'   => $value,
				'compare' => 'rating' === $type ? 'gte' : sanitize_key( $filter['compare'] ?? '=' ),
			];
		}

		$this->append_sort( $args, sanitize_key( $payload['sort'] ?? 'default' ) );
		return $args;
	}

	private function append_sort( array &$args, $sort ) {
		$map = [
			'title_asc'  => [ 'title', 'ASC' ],
			'title_desc' => [ 'title', 'DESC' ],
			'date_asc'   => [ 'created_at', 'ASC' ],
			'date_desc'  => [ 'created_at', 'DESC' ],
		];

		if ( isset( $map[ $sort ] ) ) {
			$args['orderby'] = $map[ $sort ][0];
			$args['order'] = $map[ $sort ][1];
			return;
		}

		if ( preg_match( '/^data_(.+)_(text|number|date)_(asc|desc)$/', $sort, $matches ) ) {
			$args['orderby'] = sanitize_key( $matches[1] );
			$args['order'] = strtoupper( $matches[3] );
			return;
		}

		$args['orderby'] = 'menu_order';
		$args['order'] = 'ASC';
	}

	private function render_items( $type, $template_id, array $items ) {
		if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
			return '';
		}

		$document = \ElementorPro\Plugin::elementor()->documents->get( $template_id );
		if ( ! $document ) {
			return '';
		}

		ob_start();
		try {
			foreach ( $items as $item ) {
				$this->current_type = $type;
				$this->current_item = $item;
				CurrentItemContext::push( $type, $item );
				add_filter( 'elementor/document/wrapper_attributes', [ $this, 'add_item_attributes' ], 20 );

				try {
					$document->print_content();
				} finally {
					remove_filter( 'elementor/document/wrapper_attributes', [ $this, 'add_item_attributes' ], 20 );
					CurrentItemContext::pop();
				}
			}
		} finally {
			$this->current_item = null;
			$this->current_type = '';
			CurrentItemContext::clear();
		}

		return ob_get_clean();
	}
}
