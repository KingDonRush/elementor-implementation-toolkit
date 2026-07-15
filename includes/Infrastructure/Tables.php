<?php
/**
 * Resolves dedicated Blueprint infrastructure table names.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tables {

	const BLUEPRINTS = 'blueprints';
	const VERSIONS = 'blueprint_versions';
	const ARTIFACTS = 'artifacts';
	const BINDINGS = 'bindings';
	const CHANGE_SETS = 'change_sets';
	const LOCKS = 'locks';
	const RUNS = 'runs';
	const RECONCILIATIONS = 'reconciliations';
	const ROLLBACKS = 'rollbacks';
	const RELATIONS = 'relation_values';
	const MULTIVALUES = 'multivalue_values';

	public static function name( $table ) {
		global $wpdb;

		if ( ! in_array( $table, self::keys(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown Toolkit infrastructure table.' );
		}
		return $wpdb->prefix . 'eit_' . $table;
	}

	public static function keys() {
		return [
			self::BLUEPRINTS,
			self::VERSIONS,
			self::ARTIFACTS,
			self::BINDINGS,
			self::CHANGE_SETS,
			self::LOCKS,
			self::RUNS,
			self::RECONCILIATIONS,
			self::ROLLBACKS,
			self::RELATIONS,
			self::MULTIVALUES,
		];
	}
}
