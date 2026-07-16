<?php
/**
 * Resolves immutable active Collection contracts by stable node UUID.
 */

namespace EIT\Collection;

use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionSurfaceResolver {

	private $artifacts;
	private $blueprints;

	public function __construct( ArtifactStore $artifacts = null, BlueprintStore $blueprints = null ) {
		$this->artifacts = $artifacts ?: new ArtifactStore();
		$this->blueprints = $blueprints ?: new BlueprintStore();
	}

	public function get( $collection_id ) {
		$artifact = $this->artifacts->active_by_node( strtolower( trim( (string) $collection_id ) ), 'collection_contract' );
		if ( ! $artifact ) {
			return null;
		}
		$blueprint = $this->blueprints->get( $artifact['blueprint_id'] );
		if ( ! $blueprint || (int) $blueprint['active_version_id'] !== (int) $artifact['version_id'] ) {
			return null;
		}
		$contract = array_merge(
			$artifact['payload'],
			[
				'blueprint_id' => $artifact['blueprint_id'],
				'version_id' => (int) $artifact['version_id'],
				'artifact_checksum' => $artifact['checksum'],
			]
		);
		$filter_id = $contract['filter_surface_id'] ?? '';
		$filter = $filter_id ? $this->artifacts->active_by_node( $filter_id, 'filter_contract' ) : null;
		$contract['filter_surface'] = $filter && (int) $filter['version_id'] === (int) $artifact['version_id'] ? $filter['payload'] : null;
		return $contract;
	}

	public function all() {
		$contracts = [];
		foreach ( $this->blueprints->all() as $blueprint ) {
			if ( empty( $blueprint['active_version_id'] ) ) {
				continue;
			}
			foreach ( $this->artifacts->for_version( $blueprint['active_version_id'], 'collection_contract' ) as $artifact ) {
				$contracts[] = array_merge(
					$artifact['payload'],
					[
						'blueprint_id' => $blueprint['id'],
						'version_id' => (int) $blueprint['active_version_id'],
						'artifact_checksum' => $artifact['checksum'],
					]
				);
			}
		}
		return $contracts;
	}
}
