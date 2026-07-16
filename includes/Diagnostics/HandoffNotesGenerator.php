<?php
/**
 * Generates factual Markdown from stored diagnostics only.
 */

namespace EIT\Diagnostics;

use EIT\Blueprint\MigrationService;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\MigrationStore;
use EIT\Infrastructure\RunStore;
use EIT\Infrastructure\ScenarioStore;
use EIT\Infrastructure\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HandoffNotesGenerator {

	private $blueprints;
	private $migrations;
	private $runs;
	private $scenarios;
	private $migration_service;

	public function __construct( array $dependencies = [] ) {
		$this->blueprints = $dependencies['blueprints'] ?? new BlueprintStore();
		$this->migrations = $dependencies['migrations'] ?? new MigrationStore();
		$this->runs = $dependencies['runs'] ?? new RunStore();
		$this->scenarios = $dependencies['scenarios'] ?? new ScenarioStore();
		$this->migration_service = $dependencies['migration_service'] ?? new MigrationService();
	}

	public function generate() {
		$schema = SchemaManager::verify();
		$inventory = $this->migration_service->inventory();
		$systems = $this->blueprints->all();
		$migrations = $this->migrations->all();
		$scenarios = $this->scenarios->all();
		$runs = $this->runs->recent( 20 );
		$lines = [
			'# Elementor Implementation Toolkit handoff',
			'',
			'Generated from observed local diagnostics at ' . gmdate( 'c' ) . '.',
			'',
			'## Runtime',
			'',
			'- Toolkit: ' . EIT_VERSION,
			'- WordPress: ' . get_bloginfo( 'version' ),
			'- PHP: ' . PHP_VERSION,
			'- Elementor: ' . ( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'not detected' ),
			'- WooCommerce: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'not detected' ),
			'- Blueprint schema: ' . ( is_wp_error( $schema ) ? 'failed — ' . $schema->get_error_code() : 'verified' ),
			'',
			'## Systems',
			'',
		];
		if ( empty( $systems ) ) {
			$lines[] = '- No stored systems.';
		} else {
			foreach ( $systems as $system ) {
				$lines[] = sprintf( '- %s (`%s`): draft revision %d; active version ID %s.', $this->text( $system['name'] ), $system['id'], (int) $system['draft_revision'], $system['active_version_id'] ?: 'none' );
			}
		}
		$lines = array_merge( $lines, [ '', '## Shadow migrations', '' ] );
		if ( empty( $migrations ) ) {
			$lines[] = '- No legacy source has been materialized as a draft.';
		} else {
			foreach ( $migrations as $migration ) {
				$checks = $migration['comparison']['checks'] ?? [];
				$matched = count( array_filter( $checks, fn( $check ) => ! empty( $check['match'] ) ) );
				$lines[] = sprintf( '- %s `%s`: %s; %d/%d recorded checks match; Blueprint `%s`.', $migration['source_type'], $this->text( $migration['source_key'] ), $migration['status'], $matched, count( $checks ), $migration['blueprint_id'] );
			}
		}
		$pilot = $inventory['pilot'];
		$lines = array_merge(
			$lines,
			[
				'',
				'## Pilot inventory',
				'',
				sprintf( '- CPT `__imoveis`: expected %d, observed %d.', $pilot['cpt']['expected_records'], $pilot['cpt']['observed_records'] ),
				sprintf( '- CCT `projects`: expected %d, observed %d.', $pilot['cct']['expected_records'], $pilot['cct']['observed_records'] ),
				sprintf( '- Active Elementor documents with Toolkit widgets: expected %d, observed %d (%s).', $pilot['elementor']['expected_active_documents'], $pilot['elementor']['observed_active_documents'], implode( ', ', $pilot['elementor']['source_keys'] ) ?: 'none' ),
				'- Elementor revisions remain counted only as backup evidence and are excluded from active usage.',
				'',
				'## QA scenarios',
				'',
			]
		);
		if ( empty( $scenarios ) ) {
			$lines[] = '- No saved scenario.';
		} else {
			foreach ( $scenarios as $scenario ) {
				$lines[] = sprintf( '- %s (`%s`): %s.', $this->text( $scenario['name'] ), $scenario['kind'], $scenario['status'] );
			}
		}
		$failed_runs = array_filter( $runs, fn( $run ) => ! in_array( $run['status'], [ 'succeeded', 'running' ], true ) );
		$lines = array_merge( $lines, [ '', '## Recent exceptions', '' ] );
		if ( empty( $failed_runs ) ) {
			$lines[] = '- No failed or blocked run among the latest 20 records.';
		} else {
			foreach ( $failed_runs as $run ) {
				$lines[] = sprintf( '- `%s` %s: %s (%s).', $run['request_id'], $run['operation'], $run['status'], $run['error_code'] ?: 'no error code' );
			}
		}
		$lines = array_merge( $lines, [ '', '## Open gates', '' ] );
		if ( $pilot['elementor']['expected_active_documents'] !== $pilot['elementor']['observed_active_documents'] ) {
			$lines[] = '- Pilot drift: the runtime exposes a different number of active Toolkit Elementor documents than the three-document target.';
		}
		if ( ! defined( 'WC_VERSION' ) ) {
			$lines[] = '- WooCommerce live-runtime canary has not run because WooCommerce is not installed.';
		}
		if ( empty( $scenarios ) || array_filter( $scenarios, fn( $scenario ) => 'passed' !== $scenario['status'] ) ) {
			$lines[] = '- One or more required QA scenarios have no passing recorded result.';
		}
		$lines[] = '- Human browser approval is external evidence and is not inferred from automated health checks.';
		return implode( "\n", $lines ) . "\n";
	}

	private function text( $value ) {
		return str_replace( [ "\r", "\n", '|' ], [ ' ', ' ', '\\|' ], sanitize_text_field( $value ) );
	}
}
