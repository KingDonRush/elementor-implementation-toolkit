<?php
/**
 * Browser-safe description of a published Collection and its Filter Surface.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionContractPresenter {

	private $projector;

	public function __construct( CollectionProjector $projector = null ) {
		$this->projector = $projector ?: new CollectionProjector();
	}

	public function present( array $contract ) {
		$fields = array_column(
			array_values( array_filter( $contract['fields'] ?? [], fn( $field ) => ! empty( $field['exposure']['public'] ) ) ),
			null,
			'id'
		);
		$allowed = array_fill_keys( array_keys( $fields ), true );
		$surface = is_array( $contract['filter_surface'] ?? null ) ? $contract['filter_surface'] : [];
		$controls = array_values(
			array_filter(
				$surface['controls'] ?? [],
				function ( $control ) use ( $allowed ) {
					return isset( $allowed[ $control['field_id'] ?? '' ] );
				}
			)
		);
		$sort = array_values(
			array_filter(
				$surface['sort_options'] ?? [],
				function ( $option ) use ( $allowed ) {
					return isset( $allowed[ $option['field_id'] ?? '' ] );
				}
			)
		);
		return [
			'collection_id' => $contract['collection_id'],
			'name' => $contract['name'],
			'access' => $contract['access'],
			'fields' => $this->projector->field_summaries( $fields ),
			'filter_surface' => [
				'id' => $surface['surface_id'] ?? '',
				'name' => $surface['name'] ?? '',
				'controls' => $controls,
				'sort_options' => $sort,
				'url_state' => ! empty( $surface['url_state'] ),
				'active_chips' => ! empty( $surface['active_chips'] ),
				'apply_mode' => $surface['apply_mode'] ?? 'automatic',
			],
			'page_size' => (int) $contract['page_size'],
			'explain_available' => ! empty( $contract['explain'] ),
		];
	}
}
