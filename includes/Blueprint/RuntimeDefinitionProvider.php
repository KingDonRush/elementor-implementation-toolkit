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
		$result = self::cpt_definitions_result();
		return is_wp_error( $result ) ? [] : $result;
	}

	public static function cct_definitions() {
		$result = self::cct_definitions_result();
		return is_wp_error( $result ) ? [] : $result;
	}

	public static function cpt_definitions_result() {
		return self::definitions( 'cpt' );
	}

	public static function cct_definitions_result() {
		return self::definitions( 'cct' );
	}

	public static function invalidate() {
		self::$definitions = null;
	}

	private static function definitions( $strategy ) {
		if ( null === self::$definitions ) {
			$definitions = [ 'cpt' => [], 'cct' => [] ];
			$artifacts = new ArtifactStore();
			$blueprints = ( new BlueprintStore() )->all_checked();
			if ( is_wp_error( $blueprints ) ) {
				return $blueprints;
			}
			foreach ( $blueprints as $blueprint ) {
				if ( empty( $blueprint['active_version_id'] ) ) {
					continue;
				}
				$entities = $artifacts->for_version_checked( $blueprint['active_version_id'], 'entity_definition' );
				if ( is_wp_error( $entities ) ) {
					return $entities;
				}
				foreach ( $entities as $artifact ) {
					$payload = $artifact['payload'];
					$compiled_strategy = sanitize_key( $payload['strategy'] ?? '' );
					$definition = $payload['definition'] ?? null;
					$slug = is_array( $definition ) ? sanitize_key( $definition['slug'] ?? '' ) : '';
					if ( ! isset( $definitions[ $compiled_strategy ] ) || '' === $slug ) {
						return new \WP_Error( 'eit_runtime_entity_definition_invalid', __( 'Compiled runtime authority contains an invalid Entity definition.', 'elementor-implementation-toolkit' ) );
					}
					if ( isset( $definitions[ $compiled_strategy ][ $slug ] ) ) {
						return new \WP_Error( 'eit_runtime_entity_definition_conflict', __( 'Two active Blueprints claim the same runtime Entity.', 'elementor-implementation-toolkit' ) );
					}
					$definitions[ $compiled_strategy ][ $slug ] = $definition;
				}
			}
			self::$definitions = $definitions;
		}
		return self::$definitions[ $strategy ] ?? [];
	}
}
