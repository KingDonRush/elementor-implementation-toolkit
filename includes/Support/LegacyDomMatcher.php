<?php
/**
 * Matching and deterministic sorting for normalized legacy DOM items.
 */

namespace EIT\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyDomMatcher {

	public function filter( array $items, array $filters ) {
		return array_values(
			array_filter(
				$items,
				function ( $item ) use ( $filters ) {
					foreach ( $filters as $filter ) {
						if ( ! $this->matches( $item, $filter ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	public function sort( array $items, $sort ) {
		if ( '' === $sort || 'default' === $sort ) {
			return $items;
		}

		usort(
			$items,
			function ( $left, $right ) use ( $sort ) {
				$result = $this->sort_comparison( $left, $right, $sort );
				return 0 !== $result ? $result : ( $left['originalIndex'] ?? 0 ) <=> ( $right['originalIndex'] ?? 0 );
			}
		);

		return $items;
	}

	private function matches( array $item, array $filter ) {
		$type = $filter['type'];
		$key = $filter['key'];
		$value = $filter['value'];
		$data_type = $this->data_type( $filter );

		if ( 'search' === $type ) {
			return $this->contains( $item['text'] . ' ' . $item['title'], (string) $value );
		}

		if ( '' !== ( $filter['compare'] ?? '' ) ) {
			return $this->compare( $this->value_for_source( $item, $key, $filter['source'] ?? '' ), $value, $filter['compare'], $data_type );
		}

		$haystack = $this->value( $item, $key );
		if ( in_array( $type, [ 'checkbox', 'chips', 'swatch', 'multi' ], true ) ) {
			foreach ( is_array( $value ) ? $value : [ $value ] as $needle ) {
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
			return $this->between_number( $this->primary_value( $item, $key ), $value );
		}

		if ( 'date' === $type ) {
			return $this->between_date( $this->primary_value( $item, $key ), $value );
		}

		return 'rating' !== $type || $this->number( $this->primary_value( $item, $key ) ) >= (float) $value;
	}

	private function compare( $haystack, $value, $compare, $data_type ) {
		if ( 'exists' === $compare ) {
			return '' !== trim( (string) $haystack );
		}

		if ( 'between' === $compare ) {
			return 'date' === $data_type ? $this->between_date( $haystack, $value ) : $this->between_number( $haystack, $value );
		}

		if ( in_array( $compare, [ 'gte', 'lte' ], true ) ) {
			return $this->boundary( $haystack, $value, $compare, $data_type );
		}

		if ( 'equals' === $compare ) {
			return $this->equal( $haystack, $value, $data_type );
		}

		if ( 'in' === $compare ) {
			foreach ( is_array( $value ) ? $value : preg_split( '/\s*,\s*/', (string) $value ) as $candidate ) {
				if ( $this->equal( $haystack, $candidate, $data_type ) ) {
					return true;
				}
			}
			return false;
		}

		return $this->contains( $haystack, is_array( $value ) ? implode( ' ', array_map( 'strval', $value ) ) : (string) $value );
	}

	private function between_number( $haystack, $value ) {
		$value = is_array( $value ) ? $value : [];
		$min = $value['min'] ?? $value['from'] ?? null;
		$max = $value['max'] ?? $value['to'] ?? null;
		$number = $this->number( $haystack );

		return ( null === $min || '' === (string) $min || $number >= (float) $min )
			&& ( null === $max || '' === (string) $max || $number <= (float) $max );
	}

	private function between_date( $haystack, $value ) {
		$value = is_array( $value ) ? $value : [];
		$date = strtotime( (string) $haystack );
		$from = ! empty( $value['from'] ?? $value['min'] ?? '' ) ? strtotime( (string) ( $value['from'] ?? $value['min'] ) ) : null;
		$to = ! empty( $value['to'] ?? $value['max'] ?? '' ) ? strtotime( (string) ( $value['to'] ?? $value['max'] ) ) : null;

		if ( $from && $to && $from > $to ) {
			[ $from, $to ] = [ $to, $from ];
		}

		return $date && ( ! $from || $date >= $from ) && ( ! $to || $date <= $to );
	}

	private function boundary( $left, $right, $compare, $data_type ) {
		if ( is_array( $right ) ) {
			$right = $right['value'] ?? $right['min'] ?? $right['from'] ?? $right['max'] ?? $right['to'] ?? '';
		}

		if ( 'date' === $data_type ) {
			$left = strtotime( (string) $left );
			$right = strtotime( (string) $right );
			if ( ! $left || ! $right ) {
				return false;
			}
		} else {
			$left = $this->number( $left );
			$right = $this->number( $right );
		}

		return 'gte' === $compare ? $left >= $right : $left <= $right;
	}

	private function equal( $left, $right, $data_type ) {
		$right = is_array( $right ) ? reset( $right ) : $right;
		if ( 'number' === $data_type ) {
			return $this->number( $left ) === $this->number( $right );
		}
		if ( 'date' === $data_type ) {
			return strtotime( (string) $left ) && strtotime( (string) $left ) === strtotime( (string) $right );
		}

		return mb_strtolower( trim( (string) $left ) ) === mb_strtolower( trim( (string) $right ) );
	}

	private function sort_comparison( array $left, array $right, $sort ) {
		if ( in_array( $sort, [ 'title_asc', 'title_desc' ], true ) ) {
			$result = strcasecmp( $left['title'] ?: $left['text'], $right['title'] ?: $right['text'] );
			return 'title_desc' === $sort ? -$result : $result;
		}

		if ( in_array( $sort, [ 'date_asc', 'date_desc' ], true ) ) {
			$result = strtotime( $left['data']['date'] ?? '' ) <=> strtotime( $right['data']['date'] ?? '' );
			return 'date_desc' === $sort ? -$result : $result;
		}

		if ( preg_match( '/^data_(.+)_(text|number|date)_(asc|desc)$/', $sort, $matches ) ) {
			$result = $this->typed_sort( $this->sort_value( $left, $matches[1] ), $this->sort_value( $right, $matches[1] ), $matches[2] );
			return 'desc' === $matches[3] ? -$result : $result;
		}

		$key = false !== strpos( $sort, 'rating' ) ? 'rating' : 'sort';
		$result = $this->number( $left['data'][ $key ] ?? '' ) <=> $this->number( $right['data'][ $key ] ?? '' );
		return false !== strpos( $sort, 'desc' ) ? -$result : $result;
	}

	private function typed_sort( $left, $right, $type ) {
		if ( 'number' === $type ) {
			return $this->number( $left ) <=> $this->number( $right );
		}
		if ( 'date' === $type ) {
			return strtotime( (string) $left ) <=> strtotime( (string) $right );
		}
		return strcasecmp( (string) $left, (string) $right );
	}

	private function sort_value( array $item, $key ) {
		$key = sanitize_key( $key );
		return isset( $item['data'][ $key ] ) ? $item['data'][ $key ] : $this->value( $item, $key );
	}

	private function value( array $item, $key ) {
		if ( '' === $key ) {
			return $item['text'];
		}

		$values = isset( $item['data'][ $key ] ) ? [ $item['data'][ $key ] ] : [];
		$values[] = implode( ' ', $item['classes'] );
		$values[] = $item['text'];
		return implode( ' ', array_filter( array_map( 'strval', $values ) ) );
	}

	private function primary_value( array $item, $key ) {
		$key = sanitize_key( $key );
		return isset( $item['data'][ $key ] ) ? $item['data'][ $key ] : $this->value( $item, $key );
	}

	private function value_for_source( array $item, $key, $source ) {
		$key = sanitize_key( $key );
		if ( 'visible_text' === $source ) {
			return trim( $item['title'] . ' ' . $item['text'] );
		}
		if ( 'post_field' === $source && 'title' === $key ) {
			return $item['title'];
		}
		return isset( $item['data'][ $key ] ) ? $item['data'][ $key ] : $this->value( $item, $key );
	}

	private function data_type( array $filter ) {
		if ( '' !== ( $filter['dataType'] ?? '' ) ) {
			return $filter['dataType'];
		}
		if ( in_array( $filter['type'] ?? '', [ 'range', 'rating' ], true ) ) {
			return 'number';
		}
		return 'date' === ( $filter['type'] ?? '' ) ? 'date' : 'string';
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

		$tokens = preg_split( '/[\s,|;]+/u', mb_strtolower( (string) $haystack ), -1, PREG_SPLIT_NO_EMPTY );
		return in_array( $needle, $tokens ?: [], true );
	}

	private function number( $value ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		return preg_match( '/-?\d+(?:[\.,]\d+)?/', (string) $value, $matches )
			? (float) str_replace( ',', '.', $matches[0] )
			: 0.0;
	}
}
