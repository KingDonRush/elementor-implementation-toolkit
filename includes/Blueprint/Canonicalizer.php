<?php
/**
 * Produces semantic Blueprint checksums independent from canvas position.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Canonicalizer {

	public function checksum( array $blueprint ) {
		return hash( 'sha256', $this->encode( $blueprint ) );
	}

	public function encode( array $blueprint ) {
		return wp_json_encode( $this->normalize( $blueprint ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public function normalize( array $blueprint ) {
		unset( $blueprint['checksum'], $blueprint['updated_at'] );
		$blueprint['nodes'] = $this->normalize_records( $blueprint['nodes'] ?? [], true );
		$blueprint['connections'] = $this->normalize_records( $blueprint['connections'] ?? [], false );
		return $this->sort_recursive( $blueprint );
	}

	private function normalize_records( $records, $is_node ) {
		$normalized = [];
		foreach ( is_array( $records ) ? $records : [] as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			unset( $record['position'], $record['selected'] );
			if ( $is_node ) {
				unset( $record['ui'] );
			}
			$normalized[] = $this->sort_recursive( $record );
		}
		usort(
			$normalized,
			function ( $left, $right ) {
				return strcmp( (string) ( $left['id'] ?? '' ), (string) ( $right['id'] ?? '' ) );
			}
		);
		return $normalized;
	}

	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ $this, 'sort_recursive' ], $value );
		}
		ksort( $value );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->sort_recursive( $child );
		}
		return $value;
	}
}
