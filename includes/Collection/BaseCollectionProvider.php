<?php
/**
 * Shared, storage-key-contained behavior for core Collection providers.
 */

namespace EIT\Collection;

use EIT\Contracts\CollectionProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseCollectionProvider implements CollectionProviderInterface {

	public function get_version() {
		return '1.0.0';
	}

	protected function fields( array $contract ) {
		$fields = [];
		foreach ( $contract['fields'] ?? [] as $field ) {
			if ( is_array( $field ) && ! empty( $field['id'] ) ) {
				$fields[ (string) $field['id'] ] = $field;
			}
		}
		return $fields;
	}

	protected function apply_policy_scope( array $contract, array $request, array $context ) {
		return ( new CollectionPolicyScope() )->apply( $contract, $request, $context );
	}

	protected function intersect_ids( array $first, array $second ) {
		$first = array_values( array_unique( array_map( 'strval', $first ) ) );
		$second = array_values( array_unique( array_map( 'strval', $second ) ) );
		return $first ? array_values( array_intersect( $first, $second ) ) : $second;
	}

	protected function item( $id, $title, $url, array $record, array $fields ) {
		$values = [];
		foreach ( $fields as $field_id => $field ) {
			$key = (string) ( $field['storage']['key'] ?? '' );
			if ( '' !== $key && array_key_exists( $key, $record ) ) {
				$values[ $field_id ] = $record[ $key ];
			}
		}
		return [
			'id' => (string) $id,
			'title' => (string) $title,
			'url' => (string) $url,
			'values' => $values,
		];
	}

	protected function empty_result( array $request ) {
		return [
			'items' => [],
			'total' => 0,
			'page' => max( 1, absint( $request['page'] ?? 1 ) ),
			'per_page' => max( 1, absint( $request['per_page'] ?? 24 ) ),
			'pages' => 1,
			'facets' => [],
		];
	}

	protected function list_value( $value ) {
		if ( is_array( $value ) ) {
			return array_values( array_filter( $value, [ $this, 'has_value' ] ) );
		}
		return $this->has_value( $value ) ? [ $value ] : [];
	}

	protected function has_value( $value ) {
		return null !== $value && '' !== trim( (string) $value );
	}

	protected function numeric_type( array $field ) {
		return in_array( $field['type'] ?? '', [ 'integer', 'decimal', 'money', 'percentage', 'calculated' ], true );
	}
}
