<?php
/**
 * Bulk-loads normalized relation and repeater values for one result page.
 */

namespace EIT\Collection;

use EIT\Infrastructure\NormalizedValueStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionNormalizedHydrator {

	private $values;

	public function __construct( ?NormalizedValueStore $values = null ) {
		$this->values = $values ?: new NormalizedValueStore();
	}

	public function hydrate( array $items, array $contract ) {
		$ids = array_column( $items, 'id' );
		if ( ! $ids ) {
			return $items;
		}
		foreach ( $contract['fields'] ?? [] as $field ) {
			$type = $field['type'] ?? '';
			if ( 'relation' === $type ) {
				$rows = $this->values->relation_targets_for_sources( $contract['blueprint_id'], $field['id'], $ids );
				$items = $this->merge( $items, $field['id'], $rows, 'target_id' );
			} elseif ( 'repeatable_group' === $type ) {
				$rows = $this->values->multivalue_rows_for_owners( $contract['blueprint_id'], $field['id'], $ids );
				$items = $this->merge( $items, $field['id'], $rows, 'value' );
			}
		}
		return $items;
	}

	private function merge( array $items, $field_id, array $rows, $column ) {
		foreach ( $items as &$item ) {
			$records = $rows[ (string) $item['id'] ] ?? [];
			$item['values'][ $field_id ] = array_values( array_column( $records, $column ) );
		}
		unset( $item );
		return $items;
	}
}
