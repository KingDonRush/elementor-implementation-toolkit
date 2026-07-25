<?php
/**
 * Verifies the local V1 release-candidate migration, diagnostics and pilot evidence.
 */

use EIT\Blueprint\BlueprintModule;
use EIT\Blueprint\Compiler;
use EIT\Blueprint\ImpactMapBuilder;
use EIT\Blueprint\MigrationService;
use EIT\Blueprint\ShadowComparator;
use EIT\Diagnostics\HandoffNotesGenerator;
use EIT\Diagnostics\ScenarioRunner;
use EIT\Elementor\ElementorDocumentRenderer;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\MigrationStore;
use EIT\Infrastructure\RunStore;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\Tables;
use EIT\Rest\BlueprintAdminController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assertions = 0;
$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
$sources = [
	'cpt|__imoveis' => [ 'type' => 'cpt', 'key' => '__imoveis', 'fields' => 5, 'records' => 1, 'status' => 'verified' ],
	'cct|projects' => [ 'type' => 'cct', 'key' => 'projects', 'fields' => 7, 'records' => 6, 'status' => 'mismatch' ],
	'elementor_document|301' => [ 'type' => 'elementor_document', 'key' => '301', 'fields' => 0, 'records' => 0, 'status' => 'verified' ],
	'elementor_document|340' => [ 'type' => 'elementor_document', 'key' => '340', 'fields' => 0, 'records' => 0, 'status' => 'verified' ],
	'elementor_document|479' => [ 'type' => 'elementor_document', 'key' => '479', 'fields' => 0, 'records' => 0, 'status' => 'verified' ],
];

try {
	$assert( SchemaManager::VERSION === get_option( SchemaManager::VERSION_OPTION ), 'Installed Blueprint schema version is stale.' );
	$assert( true === SchemaManager::verify() && 19 === count( Tables::keys() ), 'Release-candidate infrastructure is incomplete.' );
	$assert( file_exists( __DIR__ . '/verify-destructive-field-migrations.php' ), 'The destructive Field migration WordPress gate is missing.' );
	$assert( file_exists( __DIR__ . '/verify-entry-storage-atomicity.php' ), 'The atomic Entry storage WordPress gate is missing.' );
	$assert( file_exists( __DIR__ . '/verify-reconciliation-integrity.php' ), 'The complete publication reconciliation WordPress gate is missing.' );
	$assert( file_exists( __DIR__ . '/verify-legacy-first-activation-rollback.php' ), 'The first-activation legacy rollback WordPress gate is missing.' );
	$assert( file_exists( __DIR__ . '/verify-parameterized-routes.php' ), 'The parameterized Route WordPress gate is missing.' );

	$service = new MigrationService();
	$inventory = $service->inventory();
	$assert( 'shadow_read_only' === $inventory['mode'] && true === $inventory['read_only'], 'Legacy inspection mutated its source options.' );
	$assert( 1 === $inventory['pilot']['cpt']['observed_records'], 'CPT __imoveis pilot must contain one record.' );
	$assert( 6 === $inventory['pilot']['cct']['observed_records'], 'CCT projects pilot must contain six records.' );
	$active_documents = array_map( 'intval', $inventory['pilot']['elementor']['source_keys'] );
	sort( $active_documents );
	$assert( [ 301, 340, 479 ] === $active_documents, 'Active Elementor Toolkit documents drifted from the three-document pilot.' );

	$migrations = [];
	foreach ( ( new MigrationStore() )->all() as $record ) {
		$migrations[ $record['source_type'] . '|' . $record['source_key'] ] = $record;
	}
	$scenarios = [];
	foreach ( ( new ScenarioRunner() )->all() as $scenario ) {
		$key = ( $scenario['request']['source_type'] ?? '' ) . '|' . ( $scenario['request']['source_key'] ?? '' );
		if ( 'migration_shadow' === $scenario['kind'] ) {
			$scenarios[ $key ] = $scenario;
		}
	}
	$blueprints = new BlueprintStore();
	$compiler = new Compiler( null, null, null, BlueprintModule::registries() );
	$comparator = new ShadowComparator();
	$impacts = new ImpactMapBuilder();
	$renderer = new ElementorDocumentRenderer();
	$runner = new ScenarioRunner();

	foreach ( $sources as $identity => $expected ) {
		$record = $migrations[ $identity ] ?? null;
		$assert( $record && $expected['status'] === $record['status'], 'Stored migration status does not reflect the independent authority gate: ' . $identity );
		$checks = $record['comparison']['checks'] ?? [];
		$assert( isset( $checks['semantic_contract_checksum'], $checks['capability_downgrades'] ), 'Stored semantic authority checks are missing: ' . $identity );
		$assert( 'independent_authority_projection' === ( $record['comparison']['verification_scope'] ?? '' ), 'Stored evidence predates independent authority probes: ' . $identity );
		$assert( 64 === strlen( (string) ( $record['comparison']['compiler_checksum'] ?? '' ) ), 'Stored evidence is not bound to compiler output: ' . $identity );
		$assert( ( $record['comparison']['query_plan']['shadow_queries'] ?? PHP_INT_MAX ) <= ( $record['comparison']['query_plan']['budget'] ?? -1 ), 'Stored shadow query budget failed: ' . $identity );

		$stored = $blueprints->get( $record['blueprint_id'] );
		$assert( $stored && null === $stored['active_version_id'], 'Pilot migration published runtime instead of a draft: ' . $identity );
		$document = $stored['draft_document'];
		$validation = BlueprintModule::lifecycle()->validate_draft( $record['blueprint_id'] );
		$compiled = $compiler->compile( $document );
		$assert( $validation->is_valid() && $compiled->is_valid(), 'Imported pilot draft is not executable: ' . $identity );

		$fresh = $comparator->compare( $expected['type'], $expected['key'], $document );
		$assert( $expected['status'] === $fresh['status'], 'Fresh independent authority result drifted: ' . $identity );
		$assert( $compiled->checksum() === ( $fresh['compiler_checksum'] ?? '' ), 'Fresh evidence is not bound to the current compiler: ' . $identity );
		$assert( 'independent_authority_projection' === ( $fresh['verification_scope'] ?? '' ) && false === ( $fresh['runtime_switched'] ?? true ), 'Shadow evidence must identify independent authorities without implying a runtime switch: ' . $identity );
		$expected_legacy = 'elementor_document' === $expected['type'] ? 'wordpress_post_meta' : 'legacy_option';
		$assert( $expected_legacy === ( $fresh['authorities']['legacy']['authority'] ?? '' ) && 'compiled_candidate_artifact' === ( $fresh['authorities']['candidate']['authority'] ?? '' ), 'Shadow authorities are not independent: ' . $identity );
		if ( 'elementor_document' === $expected['type'] ) {
			$assert( 'source_wordpress_document' === ( $fresh['authorities']['legacy']['binding_mode'] ?? '' ) && 'same_wordpress_document' === ( $fresh['authorities']['candidate']['binding_mode'] ?? '' ), 'Elementor evidence pretends to be a dual-runtime comparison: ' . $identity );
		} elseif ( 'cct' === $expected['type'] ) {
			$assert( 'direct_table_contract_hydration' === ( $fresh['record_probe']['mode'] ?? '' ) && ! empty( $fresh['record_probe']['runtime_definition_independent'] ), 'CCT records reentered combined runtime authority.' );
		} elseif ( 'cpt' === $expected['type'] ) {
			$assert( 'wordpress_api_diagnostic' === ( $fresh['record_probe']['mode'] ?? '' ) && empty( $fresh['record_probe']['runtime_definition_independent'] ), 'CPT diagnostic was mislabeled as runtime-independent.' );
		}
		if ( 'cct|projects' === $identity ) {
			$downgrades = $fresh['checks']['capability_downgrades']['shadow'] ?? [];
			$assert( false === ( $fresh['checks']['semantic_contract_checksum']['match'] ?? true ), 'Projects semantic contract incorrectly claims equivalence.' );
			$assert( false === ( $fresh['checks']['capability_downgrades']['match'] ?? true ), 'Projects capability downgrade is not release-blocking.' );
			$assert( 'summary' === ( $downgrades[0]['storage_key'] ?? '' ) && [ 'filter' ] === ( $downgrades[0]['capabilities'] ?? [] ), 'Projects blocker must identify the filterable textarea field.' );
		} else {
			$assert( ! in_array( false, array_column( $fresh['checks'], 'match' ), true ), 'Compatible shadow checks do not match: ' . $identity );
		}
		$impact = $impacts->build( $document, [ 'affected_node_ids' => array_column( $document['nodes'], 'id' ) ] );
		$assert( $expected['fields'] === $impact['summary']['fields'], 'Impact Map field count drifted: ' . $identity );
		$assert( $expected['records'] === $impact['summary']['records'], 'Impact Map record count drifted: ' . $identity );
		if ( 'elementor_document' === $expected['type'] ) {
			$documents = array_merge( $impact['pages'], $impact['templates'] );
			$assert( 1 === count( $documents ) && (int) $expected['key'] === $documents[0]['id'], 'Impact Map missed the explicit Elementor document: ' . $identity );
			$assert( '' !== trim( $renderer->render( $expected['key'] ) ), 'Elementor shadow renderer returned empty HTML: ' . $identity );
		}

		$scenario = $scenarios[ $identity ] ?? null;
		$assert( $scenario, 'Migration QA scenario is missing: ' . $identity );
		$scenario = $runner->run( $scenario['id'] );
		$expected_scenario_status = 'verified' === $expected['status'] ? 'passed' : 'failed';
		$assert( ! is_wp_error( $scenario ) && $expected_scenario_status === $scenario['status'], 'Migration QA replay did not preserve the expected gate result: ' . $identity );
	}

	$runs = new RunStore();
	$qa_runs = array_values( array_filter( $runs->recent( 100 ), fn( $run ) => 'qa_scenario' === $run['operation'] ) );
	$assert( count( $qa_runs ) >= 5, 'Flight Recorder did not retain the five scenario runs.' );
	foreach ( array_slice( $qa_runs, 0, 5 ) as $run ) {
		$detail = $runs->with_events( $run['id'] );
		$encoded = wp_json_encode( $detail['events'] ?? [] );
		$assert( count( $detail['events'] ?? [] ) >= 3, 'QA run has no ordered Flight Recorder evidence.' );
		$assert( false === stripos( $encoded, '_elementor_data' ) && false === stripos( $encoded, 'authorization:' ), 'Flight Recorder persisted disallowed full content or authorization.' );
	}

	$handoff = ( new HandoffNotesGenerator() )->generate();
	$assert( false !== strpos( $handoff, 'CPT `__imoveis`: expected 1, observed 1.' ), 'Handoff omitted the CPT pilot fact.' );
	$assert( false !== strpos( $handoff, 'CCT `projects`: expected 6, observed 6.' ), 'Handoff omitted the CCT pilot fact.' );
	$assert( false !== strpos( $handoff, 'projects' ) && false !== strpos( $handoff, 'capability downgrade' ), 'Handoff hid the projects capability blocker.' );
	$assert( false !== strpos( $handoff, 'WooCommerce live-runtime canary has not run' ), 'Handoff hid the absent WooCommerce canary.' );
	$assert( false !== strpos( $handoff, 'Human browser approval is external evidence' ), 'Handoff inferred visual approval.' );
	$assert( false === strpos( $handoff, '_elementor_data' ), 'Handoff persisted raw Elementor content.' );

	$server = rest_get_server();
	if ( ! isset( $server->get_routes()['/eit/v1/migration/inventory'] ) ) {
		do_action( 'rest_api_init' );
	}
	if ( ! isset( $server->get_routes()['/eit/v1/migration/inventory'] ) ) {
		( new BlueprintAdminController() )->register_routes();
	}
	$routes = $server->get_routes();
	foreach ( [ '/eit/v1/migration/inventory', '/eit/v1/migration/prepare', '/eit/v1/migration/apply', '/eit/v1/qa-scenarios', '/eit/v1/handoff-notes' ] as $route ) {
		$assert( isset( $routes[ $route ] ), 'Release-candidate REST route is missing: ' . $route );
	}

	WP_CLI::success( sprintf( 'Release gate audited with %d assertions; projects remains blocked by one explicit capability downgrade.', $assertions ) );
} catch ( Throwable $error ) {
	WP_CLI::error( $error->getMessage() );
}
