<?php
/**
 * Bounded normalization for the 1.x legacy DOM provider.
 */

namespace EIT\Support;

use EIT\Rest\FilterRequestPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyDomPayload {

	const MAX_TEXT_LENGTH = 2000;

	public function normalize( $payload ) {
		$payload = is_array( $payload ) ? $payload : [];

		return [
			'items'    => $this->items( $payload['items'] ?? [] ),
			'filters'  => $this->filters( $payload['filters'] ?? [] ),
			'sort'     => $this->sort( $payload['sort'] ?? '' ),
			'page'     => max( 1, absint( $payload['page'] ?? 1 ) ),
			'per_page' => max( 1, min( FilterRequestPolicy::MAX_PER_PAGE, absint( $payload['perPage'] ?? FilterRequestPolicy::DEFAULT_PER_PAGE ) ) ),
		];
	}

	private function items( $items ) {
		$items = is_array( $items ) ? array_slice( $items, 0, FilterRequestPolicy::MAX_DOM_ITEMS ) : [];
		$normalized = [];

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$client_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $item['clientId'] ?? 'eit-item-' . $index ) );
			if ( '' === $client_id ) {
				continue;
			}

			$normalized[] = [
				'clientId'      => $client_id,
				'originalIndex' => absint( $item['originalIndex'] ?? $index ),
				'title'         => sanitize_text_field( $item['title'] ?? '' ),
				'text'          => $this->limit_text( wp_strip_all_tags( (string) ( $item['text'] ?? '' ) ) ),
				'classes'       => $this->string_list( $item['classes'] ?? [] ),
				'data'          => $this->data_map( $item['data'] ?? [] ),
			];
		}

		return $normalized;
	}

	private function filters( $filters ) {
		$filters = is_array( $filters ) ? array_slice( $filters, 0, FilterRequestPolicy::MAX_FILTERS ) : [];
		$normalized = [];

		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$type = sanitize_key( $filter['type'] ?? '' );
			$value = $filter['value'] ?? null;
			$compare = $this->allowed( $filter['compare'] ?? '', [ 'contains', 'equals', 'in', 'between', 'gte', 'lte', 'exists' ] );
			if ( '' === $type || ( 'exists' !== $compare && $this->is_empty( $value ) ) ) {
				continue;
			}

			$normalized[] = [
				'key'      => sanitize_key( $filter['key'] ?? '' ),
				'type'     => $type,
				'value'    => $this->filter_value( $value ),
				'compare'  => $compare,
				'source'   => $this->allowed( $filter['source'] ?? '', [ 'visible_text', 'data_attr', 'taxonomy', 'meta', 'post_field' ] ),
				'dataType' => $this->allowed( $filter['dataType'] ?? $filter['data_type'] ?? '', [ 'string', 'number', 'date', 'boolean' ] ),
			];
		}

		return $normalized;
	}

	private function filter_value( $value ) {
		if ( ! is_array( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		$normalized = [];
		foreach ( array_slice( $value, 0, 25, true ) as $key => $item ) {
			$normalized[ sanitize_key( $key ) ] = is_array( $item )
				? $this->filter_value( $item )
				: sanitize_text_field( (string) $item );
		}

		return $normalized;
	}

	private function sort( $sort ) {
		$sort = sanitize_key( $sort );
		$allowed = [ '', 'default', 'title_asc', 'title_desc', 'date_asc', 'date_desc', 'numeric_asc', 'numeric_desc', 'rating_desc', 'rating_asc' ];

		return in_array( $sort, $allowed, true ) || preg_match( '/^data_[a-z0-9_-]+_(text|number|date)_(asc|desc)$/', $sort )
			? $sort
			: 'default';
	}

	private function data_map( $value ) {
		$normalized = [];
		foreach ( is_array( $value ) ? $value : [] as $key => $item ) {
			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$item = implode( ' ', array_map( 'sanitize_text_field', array_map( 'strval', $item ) ) );
			}
			$normalized[ $key ] = $this->limit_text( sanitize_text_field( (string) $item ) );
		}

		return $normalized;
	}

	private function string_list( $value ) {
		$value = is_array( $value ) ? $value : preg_split( '/\s+/', (string) $value );
		return array_values( array_filter( array_unique( array_map( 'sanitize_html_class', $value ) ) ) );
	}

	private function allowed( $value, array $allowed ) {
		$value = sanitize_key( $value );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	private function is_empty( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! $this->is_empty( $item ) ) {
					return false;
				}
			}
			return true;
		}

		return '' === trim( (string) $value );
	}

	private function limit_text( $text ) {
		return mb_substr( trim( (string) $text ), 0, self::MAX_TEXT_LENGTH );
	}
}
