<?php
/**
 * Server-side filtering for indexed DOM listing items.
 */

namespace EIT\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterResolver {

	const MAX_ITEMS = 500;
	const MAX_FILTERS = 40;
	const MAX_TEXT_LENGTH = 5000;

	public function resolve( $payload ) {
		$payload = is_array( $payload ) ? $payload : [];
		$items   = $this->normalize_items( $payload['items'] ?? [] );
		$filters = $this->normalize_filters( $payload['filters'] ?? [] );
		$sort    = $this->normalize_sort( $payload['sort'] ?? '' );
		$page    = max( 1, absint( $payload['page'] ?? 1 ) );
		$per_page = max( 1, min( 96, absint( $payload['perPage'] ?? 12 ) ) );

		$matched = [];

		foreach ( $items as $item ) {
			$enriched = $this->enrich_item( $item );

			if ( $this->matches_filters( $enriched, $filters ) ) {
				$matched[] = $enriched;
			}
		}

		$matched = $this->sort_items( $matched, $sort );
		$total   = count( $matched );
		$pages   = max( 1, (int) ceil( $total / $per_page ) );
		$page    = min( $page, $pages );
		$offset  = ( $page - 1 ) * $per_page;
		$current = array_slice( $matched, $offset, $per_page );

		return [
			'ids'        => array_values( wp_list_pluck( $current, 'clientId' ) ),
			'allIds'     => array_values( wp_list_pluck( $matched, 'clientId' ) ),
			'total'      => $total,
			'page'       => $page,
			'pages'      => $pages,
			'perPage'    => $per_page,
			'pagination' => [
				'hasPrevious' => $page > 1,
				'hasNext'     => $page < $pages,
			],
		];
	}

	private function normalize_items( $items ) {
		$items = is_array( $items ) ? array_slice( $items, 0, self::MAX_ITEMS ) : [];
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
				'postId'        => absint( $item['postId'] ?? 0 ),
				'url'           => esc_url_raw( $item['url'] ?? '' ),
				'title'         => sanitize_text_field( $item['title'] ?? '' ),
				'text'          => $this->limit_text( wp_strip_all_tags( (string) ( $item['text'] ?? '' ) ) ),
				'classes'       => $this->normalize_string_list( $item['classes'] ?? [] ),
				'data'          => $this->normalize_data_map( $item['data'] ?? [] ),
			];
		}

		return $normalized;
	}

	private function normalize_filters( $filters ) {
		$filters = is_array( $filters ) ? array_slice( $filters, 0, self::MAX_FILTERS ) : [];
		$normalized = [];

		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$key = sanitize_key( $filter['key'] ?? '' );
			$type = sanitize_key( $filter['type'] ?? '' );
			$value = $filter['value'] ?? null;

			if ( '' === $type || $this->is_empty_value( $value ) ) {
				continue;
			}

			$normalized[] = [
				'key'   => $key,
				'type'  => $type,
				'value' => $this->normalize_filter_value( $value ),
			];
		}

		return $normalized;
	}

	private function normalize_sort( $sort ) {
		$sort = sanitize_key( $sort );
		$allowed = [ '', 'default', 'title_asc', 'title_desc', 'date_asc', 'date_desc', 'numeric_asc', 'numeric_desc', 'rating_desc', 'rating_asc' ];

		if ( in_array( $sort, $allowed, true ) || preg_match( '/^data_[a-z0-9_-]+_(text|number|date)_(asc|desc)$/', $sort ) ) {
			return $sort;
		}

		return 'default';
	}

	private function normalize_filter_value( $value ) {
		if ( is_array( $value ) ) {
			$normalized = [];

			foreach ( $value as $key => $item ) {
				if ( is_array( $item ) ) {
					$normalized[ sanitize_key( $key ) ] = $this->normalize_filter_value( $item );
				} else {
					$normalized[ sanitize_key( $key ) ] = sanitize_text_field( (string) $item );
				}
			}

			return $normalized;
		}

		return sanitize_text_field( (string) $value );
	}

	private function enrich_item( array $item ) {
		if ( ! $item['postId'] && $item['url'] ) {
			$item['postId'] = url_to_postid( $item['url'] );
		}

		if ( $item['postId'] && $this->is_public_post( $item['postId'] ) ) {
			$post = get_post( $item['postId'] );

			if ( $post ) {
				$item['data']['post_type'] = $post->post_type;
				$item['data']['date'] = get_the_date( 'Y-m-d', $post );
				$item['title'] = $item['title'] ?: get_the_title( $post );
				$item['text'] = trim( $item['text'] . ' ' . get_the_title( $post ) . ' ' . wp_strip_all_tags( get_the_excerpt( $post ) ) );
			}

			foreach ( get_object_taxonomies( get_post_type( $item['postId'] ), 'names' ) as $taxonomy ) {
				$terms = get_the_terms( $item['postId'], $taxonomy );

				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}

				$item['data'][ $taxonomy ] = implode(
					' ',
					array_map(
						function ( $term ) {
							return $term->slug . ' ' . $term->name;
						},
						$terms
					)
				);
			}
		}

		return $item;
	}

	private function matches_filters( array $item, array $filters ) {
		foreach ( $filters as $filter ) {
			if ( ! $this->matches_filter( $item, $filter ) ) {
				return false;
			}
		}

		return true;
	}

	private function matches_filter( array $item, array $filter ) {
		$type = $filter['type'];
		$key = $filter['key'];
		$value = $filter['value'];

		if ( 'search' === $type ) {
			return $this->contains( $item['text'] . ' ' . $item['title'], (string) $value );
		}

		$haystack = $this->get_item_value( $item, $key );

		if ( in_array( $type, [ 'checkbox', 'chips', 'swatch', 'multi' ], true ) ) {
			$needles = is_array( $value ) ? $value : [ $value ];

			foreach ( $needles as $needle ) {
				if ( $this->contains_token( $haystack, (string) $needle ) ) {
					return true;
				}
			}

			return false;
		}

		if ( in_array( $type, [ 'radio', 'select', 'toggle' ], true ) ) {
			return $this->contains_token( $haystack, (string) $value );
		}

		if ( 'range' === $type ) {
			$number = $this->to_number( $haystack );
			$min = isset( $value['min'] ) && '' !== $value['min'] ? (float) $value['min'] : null;
			$max = isset( $value['max'] ) && '' !== $value['max'] ? (float) $value['max'] : null;

			return ( null === $min || $number >= $min ) && ( null === $max || $number <= $max );
		}

		if ( 'date' === $type ) {
			$date = strtotime( $haystack );
			$from = ! empty( $value['from'] ) ? strtotime( $value['from'] ) : null;
			$to = ! empty( $value['to'] ) ? strtotime( $value['to'] ) : null;

			if ( ! $date ) {
				return false;
			}

			return ( ! $from || $date >= $from ) && ( ! $to || $date <= $to );
		}

		if ( 'rating' === $type ) {
			return $this->to_number( $haystack ) >= (float) $value;
		}

		return true;
	}

	private function sort_items( array $items, $sort ) {
		if ( '' === $sort || 'default' === $sort ) {
			return $items;
		}

		usort(
			$items,
			function ( $a, $b ) use ( $sort ) {
				$result = 0;

				if ( 'title_asc' === $sort || 'title_desc' === $sort ) {
					$result = strcasecmp( $a['title'] ?: $a['text'], $b['title'] ?: $b['text'] );
					$result = 'title_desc' === $sort ? - $result : $result;
				} elseif ( 'date_asc' === $sort || 'date_desc' === $sort ) {
					$result = strtotime( $a['data']['date'] ?? '' ) <=> strtotime( $b['data']['date'] ?? '' );
					$result = 'date_desc' === $sort ? - $result : $result;
				} elseif ( preg_match( '/^data_(.+)_(text|number|date)_(asc|desc)$/', $sort, $matches ) ) {
					$result = $this->compare_sort_values( $this->get_sort_value( $a, $matches[1] ), $this->get_sort_value( $b, $matches[1] ), $matches[2] );
					$result = 'desc' === $matches[3] ? - $result : $result;
				} else {
					$key = false !== strpos( $sort, 'rating' ) ? 'rating' : 'sort';
					$result = $this->to_number( $a['data'][ $key ] ?? '' ) <=> $this->to_number( $b['data'][ $key ] ?? '' );
					$result = false !== strpos( $sort, 'desc' ) ? - $result : $result;
				}

				if ( 0 !== $result ) {
					return $result;
				}

				return ( $a['originalIndex'] ?? 0 ) <=> ( $b['originalIndex'] ?? 0 );
			}
		);

		return $items;
	}

	private function compare_sort_values( $left, $right, $type ) {
		if ( 'number' === $type ) {
			return $this->to_number( $left ) <=> $this->to_number( $right );
		}

		if ( 'date' === $type ) {
			return strtotime( (string) $left ) <=> strtotime( (string) $right );
		}

		return strcasecmp( (string) $left, (string) $right );
	}

	private function get_sort_value( array $item, $key ) {
		$key = sanitize_key( $key );

		if ( '' !== $key && isset( $item['data'][ $key ] ) ) {
			return $item['data'][ $key ];
		}

		return $this->get_item_value( $item, $key );
	}

	private function get_item_value( array $item, $key ) {
		if ( '' === $key ) {
			return $item['text'];
		}

		$values = [];

		if ( isset( $item['data'][ $key ] ) ) {
			$values[] = $item['data'][ $key ];
		}

		$values[] = implode( ' ', $item['classes'] );
		$values[] = $item['text'];

		return implode( ' ', array_filter( array_map( 'strval', $values ) ) );
	}

	private function normalize_string_list( $value ) {
		$value = is_array( $value ) ? $value : preg_split( '/\s+/', (string) $value );
		$value = array_map( 'sanitize_html_class', $value );

		return array_values( array_filter( array_unique( $value ) ) );
	}

	private function normalize_data_map( $value ) {
		$value = is_array( $value ) ? $value : [];
		$normalized = [];

		foreach ( $value as $key => $item ) {
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

	private function contains( $haystack, $needle ) {
		$needle = trim( mb_strtolower( (string) $needle ) );

		return '' === $needle || false !== strpos( mb_strtolower( (string) $haystack ), $needle );
	}

	private function contains_token( $haystack, $needle ) {
		$needle = trim( mb_strtolower( (string) $needle ) );

		if ( '' === $needle ) {
			return true;
		}

		$haystack = mb_strtolower( (string) $haystack );

		return false !== strpos( $haystack, $needle );
	}

	private function to_number( $value ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}

		if ( preg_match( '/-?\d+(?:[\.,]\d+)?/', (string) $value, $matches ) ) {
			return (float) str_replace( ',', '.', $matches[0] );
		}

		return 0.0;
	}

	private function is_empty_value( $value ) {
		if ( is_array( $value ) ) {
			return empty( array_filter( $value, [ $this, 'not_empty' ] ) );
		}

		return '' === trim( (string) $value );
	}

	private function not_empty( $value ) {
		return ! $this->is_empty_value( $value );
	}

	private function limit_text( $text ) {
		$text = trim( (string) $text );

		return mb_substr( $text, 0, self::MAX_TEXT_LENGTH );
	}

	private function is_public_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== get_post_status( $post ) ) {
			return false;
		}

		$post_type = get_post_type_object( $post->post_type );

		return $post_type && $post_type->public;
	}
}
