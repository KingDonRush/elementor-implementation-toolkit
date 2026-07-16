<?php
/**
 * Converts normalized relation filters into bounded source identity constraints.
 */

namespace EIT\Collection;

use EIT\Infrastructure\NormalizedValueStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionRelationConstraints {

	private $values;

	public function __construct( NormalizedValueStore $values = null ) {
		$this->values = $values ?: new NormalizedValueStore();
	}

	public function apply( array $contract, array $request ) {
		$fields = array_column( $contract['fields'] ?? [], null, 'id' );
		$include = null;
		$exclude = [];
		foreach ( $request['filters'] ?? [] as $filter ) {
			$field = $fields[ $filter['field_id'] ?? '' ] ?? null;
			if ( 'relation' !== ( $field['type'] ?? '' ) ) {
				continue;
			}
			$sources = $this->values->source_ids_for_relation_targets(
				$contract['blueprint_id'],
				$field['id'],
				is_array( $filter['value'] ?? null ) ? $filter['value'] : [ $filter['value'] ?? '' ]
			);
			if ( 'not_in' === ( $filter['operator'] ?? '' ) ) {
				$exclude = array_merge( $exclude, $sources );
			} else {
				$include = null === $include ? $sources : array_intersect( $include, $sources );
			}
		}
		$request['_relation_include'] = null === $include ? [] : ( $include ?: [ 0 ] );
		$request['_relation_exclude'] = array_values( array_unique( $exclude ) );
		return $request;
	}

	public function facet_counts( array $contract, $field_id, array $source_ids ) {
		return $this->values->relation_target_counts( $contract['blueprint_id'], $field_id, $source_ids );
	}
}
