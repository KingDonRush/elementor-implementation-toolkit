<?php
/**
 * Resolves immutable active Entry contracts by stable Surface UUID.
 */

namespace EIT\Entry;

use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntrySurfaceResolver {

	private $blueprints;
	private $artifacts;

	public function __construct( ?BlueprintStore $blueprints = null, ?ArtifactStore $artifacts = null ) {
		$this->blueprints = $blueprints ?: new BlueprintStore();
		$this->artifacts = $artifacts ?: new ArtifactStore();
	}

	public function get( $surface_id ) {
		$surface_id = strtolower( trim( (string) $surface_id ) );
		foreach ( $this->blueprints->all() as $blueprint ) {
			if ( empty( $blueprint['active_version_id'] ) ) {
				continue;
			}
			foreach ( $this->artifacts->for_version( $blueprint['active_version_id'], 'entry_contract' ) as $artifact ) {
				if ( $surface_id === strtolower( (string) ( $artifact['node_id'] ?? '' ) ) ) {
					return array_merge(
						$artifact['payload'],
						[
							'blueprint_id' => $blueprint['id'],
							'version_id' => (int) $blueprint['active_version_id'],
							'artifact_checksum' => $artifact['checksum'],
						]
					);
				}
			}
		}
		return null;
	}

	public function all() {
		$contracts = [];
		foreach ( $this->blueprints->all() as $blueprint ) {
			if ( empty( $blueprint['active_version_id'] ) ) {
				continue;
			}
			foreach ( $this->artifacts->for_version( $blueprint['active_version_id'], 'entry_contract' ) as $artifact ) {
				$contracts[] = array_merge( $artifact['payload'], [ 'blueprint_id' => $blueprint['id'], 'version_id' => (int) $blueprint['active_version_id'] ] );
			}
		}
		return $contracts;
	}
}
