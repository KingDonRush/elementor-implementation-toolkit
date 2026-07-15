<?php
/**
 * Projects active compiled Entity artifacts into the legacy runtime registrars.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RuntimeDefinitionProvider {

	private static $definitions;

	public static function cpt_definitions() {
		return self::definitions( 'cpt' );
	}

	public static function cct_definitions() {
		return self::definitions( 'cct' );
	}

	public static function invalidate() {
		self::$definitions = null;
	}

	private static function definitions( $strategy ) {
		if ( null === self::$definitions ) {
			self::$definitions = [ 'cpt' => [], 'cct' => [] ];
			$artifacts = new ArtifactStore();
			foreach ( ( new BlueprintStore() )->all() as $blueprint ) {
				if ( empty( $blueprint['active_version_id'] ) ) {
					continue;
				}
				foreach ( $artifacts->for_version( $blueprint['active_version_id'], 'entity_definition' ) as $artifact ) {
					$payload = $artifact['payload'];
					$compiled_strategy = $payload['strategy'] ?? '';
					$definition = $payload['definition'] ?? null;
					$slug = is_array( $definition ) ? sanitize_key( $definition['slug'] ?? '' ) : '';
					if ( isset( self::$definitions[ $compiled_strategy ] ) && '' !== $slug ) {
						self::$definitions[ $compiled_strategy ][ $slug ] = $definition;
					}
				}
			}
		}
		return self::$definitions[ $strategy ] ?? [];
	}
}
