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
	const STORAGE_CLAIMS = 'storage_claims';
	const LOCKS = 'locks';
	const RUNS = 'runs';
	const RECONCILIATIONS = 'reconciliations';
	const ROLLBACKS = 'rollbacks';
	const RELATIONS = 'relation_values';
	const MULTIVALUES = 'multivalue_values';
	const ENTRY_SUBMISSIONS = 'entry_submissions';
	const PENDING_UPLOADS = 'pending_uploads';
	const ACTION_JOBS = 'entry_action_jobs';
	const MIGRATIONS = 'migrations';
	const RUN_EVENTS = 'run_events';
	const QA_SCENARIOS = 'qa_scenarios';

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
			self::STORAGE_CLAIMS,
			self::LOCKS,
			self::RUNS,
			self::RECONCILIATIONS,
			self::ROLLBACKS,
			self::RELATIONS,
			self::MULTIVALUES,
			self::ENTRY_SUBMISSIONS,
			self::PENDING_UPLOADS,
			self::ACTION_JOBS,
			self::MIGRATIONS,
			self::RUN_EVENTS,
			self::QA_SCENARIOS,
		];
	}
}
