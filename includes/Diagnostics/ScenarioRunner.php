<?php
/**
 * Saves and replays bounded Collection and migration QA scenarios.
 */

namespace EIT\Diagnostics;

use EIT\Blueprint\ShadowComparator;
use EIT\Blueprint\Uuid;
use EIT\Collection\CollectionQueryService;
use EIT\Collection\CollectionRequestValidator;
use EIT\Collection\CollectionSurfaceResolver;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\MigrationStore;
use EIT\Infrastructure\ScenarioStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScenarioRunner {

	const KINDS = [ 'collection_query', 'migration_shadow' ];

	private $scenarios;
	private $collections;
	private $validator;
	private $queries;
	private $migrations;
	private $blueprints;
	private $comparator;
	private $flight;

	public function __construct( array $dependencies = [] ) {
		$this->scenarios = $dependencies['scenarios'] ?? new ScenarioStore();
		$this->collections = $dependencies['collections'] ?? new CollectionSurfaceResolver();
		$this->validator = $dependencies['validator'] ?? new CollectionRequestValidator();
		$this->queries = $dependencies['queries'] ?? new CollectionQueryService();
		$this->migrations = $dependencies['migrations'] ?? new MigrationStore();
		$this->blueprints = $dependencies['blueprints'] ?? new BlueprintStore();
		$this->comparator = $dependencies['comparator'] ?? new ShadowComparator();
		$this->flight = $dependencies['flight'] ?? new FlightRecorder();
	}

	public function all() {
		return $this->scenarios->all();
	}

	public function save( array $scenario, $user_id ) {
		$kind = sanitize_key( $scenario['kind'] ?? '' );
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_Error( 'eit_scenario_kind_invalid', __( 'QA Scenario kind is not supported.', 'elementor-implementation-toolkit' ) );
		}
		if ( strlen( wp_json_encode( $scenario ) ) > CollectionRequestValidator::MAX_BODY_BYTES ) {
			return new \WP_Error( 'eit_scenario_too_large', __( 'QA Scenario exceeds the 32 KB limit.', 'elementor-implementation-toolkit' ) );
		}
		$prepared = $this->prepare_request( $kind, $scenario['request'] ?? [] );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$scenario['kind'] = $kind;
		$scenario['request'] = $prepared['request'];
		$scenario['blueprint_id'] = $prepared['blueprint_id'];
		$scenario['created_by'] = absint( $user_id );
		$scenario['expected'] = $this->normalize_expected( $scenario['expected'] ?? [] );
		return $this->scenarios->save( $scenario );
	}

	public function run( $scenario_id ) {
		$scenario = $this->scenarios->get( $scenario_id );
		if ( ! $scenario ) {
			return new \WP_Error( 'eit_scenario_not_found', __( 'QA Scenario was not found.', 'elementor-implementation-toolkit' ) );
		}
		$run_blueprint_id = $scenario['blueprint_id'] ?: Uuid::v5( Uuid::LEGACY_NAMESPACE, 'diagnostic:qa-scenarios' );
		$run = $this->flight->start( $run_blueprint_id, 'qa_scenario', [ 'scenario_id' => $scenario['id'], 'kind' => $scenario['kind'], 'version' => EIT_VERSION ] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$started = microtime( true );
		$actual = 'collection_query' === $scenario['kind']
			? $this->run_collection( $scenario['request'] )
			: $this->run_migration( $scenario['request'] );
		if ( is_wp_error( $actual ) ) {
			$result = [ 'passed' => false, 'error' => [ 'code' => $actual->get_error_code(), 'message' => $actual->get_error_message() ], 'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ) ];
			$this->scenarios->record_result( $scenario_id, 'error', $result );
			$this->flight->finish( $run['id'], 'failed', $actual );
			return $actual;
		}
		$comparison = $this->compare( $scenario['expected'], $actual );
		$result = [ 'passed' => $comparison['passed'], 'checks' => $comparison['checks'], 'actual' => $actual, 'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ), 'request_id' => $run['request_id'] ];
		$stored = $this->scenarios->record_result( $scenario_id, $result['passed'] ? 'passed' : 'failed', $result );
		if ( is_wp_error( $stored ) ) {
			$this->flight->finish( $run['id'], 'failed', $stored );
			return $stored;
		}
		$this->flight->event( $run['id'], 'scenario_compared', [ 'passed' => $result['passed'], 'checks' => $result['checks'] ], $result['duration_ms'] );
		$this->flight->finish( $run['id'], $result['passed'] ? 'succeeded' : 'failed', null, [ 'scenario_status' => $result['passed'] ? 'passed' : 'failed' ] );
		return $stored;
	}

	private function prepare_request( $kind, $request ) {
		if ( ! is_array( $request ) ) {
			return new \WP_Error( 'eit_scenario_request_invalid', __( 'QA Scenario request must be an object.', 'elementor-implementation-toolkit' ) );
		}
		if ( 'collection_query' === $kind ) {
			$collection_id = strtolower( trim( (string) ( $request['collection_id'] ?? '' ) ) );
			$contract = $this->collections->get( $collection_id );
			if ( ! $contract ) {
				return new \WP_Error( 'eit_scenario_collection_missing', __( 'QA Scenario Collection is not an active contract.', 'elementor-implementation-toolkit' ) );
			}
			$query = is_array( $request['query'] ?? null ) ? $request['query'] : [];
			unset( $query['_cost'] );
			$validated = $this->validator->validate( $contract, $query );
			return is_wp_error( $validated ) ? $validated : [ 'blueprint_id' => $contract['blueprint_id'], 'request' => [ 'collection_id' => $collection_id, 'query' => $validated ] ];
		}
		$source_type = sanitize_key( $request['source_type'] ?? '' );
		$source_key = 'elementor_document' === $source_type ? (string) absint( $request['source_key'] ?? 0 ) : sanitize_key( $request['source_key'] ?? '' );
		$migration = $this->migrations->get_by_source( $source_type, $source_key );
		return $migration
			? [ 'blueprint_id' => $migration['blueprint_id'], 'request' => [ 'source_type' => $source_type, 'source_key' => $source_key ] ]
			: new \WP_Error( 'eit_scenario_migration_missing', __( 'QA Scenario migration source has not been imported.', 'elementor-implementation-toolkit' ) );
	}

	private function run_collection( array $request ) {
		$contract = $this->collections->get( $request['collection_id'] );
		if ( ! $contract ) {
			return new \WP_Error( 'eit_scenario_collection_missing', __( 'QA Scenario Collection is no longer active.', 'elementor-implementation-toolkit' ) );
		}
		$result = $this->queries->execute( $contract, $request['query'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return [
			'total' => (int) ( $result['pagination']['total'] ?? 0 ),
			'pages' => (int) ( $result['pagination']['pages'] ?? 0 ),
			'item_count' => count( $result['items'] ?? [] ),
			'html_checksum' => hash( 'sha256', $this->normalize_html( $result['html'] ?? '' ) ),
			'facets_checksum' => hash( 'sha256', wp_json_encode( $result['facets'] ?? [] ) ),
			'request_cost' => (int) ( $result['request_cost'] ?? 0 ),
		];
	}

	private function run_migration( array $request ) {
		$migration = $this->migrations->get_by_source( $request['source_type'], $request['source_key'] );
		$blueprint = $migration ? $this->blueprints->get( $migration['blueprint_id'] ) : null;
		if ( ! $migration || ! $blueprint || ! is_array( $blueprint['draft_document'] ) ) {
			return new \WP_Error( 'eit_scenario_migration_missing', __( 'Imported migration draft is no longer available.', 'elementor-implementation-toolkit' ) );
		}
		$comparison = $this->comparator->compare( $request['source_type'], $request['source_key'], $blueprint['draft_document'] );
		return [ 'status' => $comparison['status'], 'checks_checksum' => hash( 'sha256', wp_json_encode( $comparison['checks'] ) ), 'query_plan' => $comparison['query_plan'] ];
	}

	private function normalize_expected( $expected ) {
		$allowed = [ 'total', 'pages', 'item_count', 'html_checksum', 'facets_checksum', 'request_cost', 'status', 'checks_checksum' ];
		return is_array( $expected ) ? array_intersect_key( $expected, array_flip( $allowed ) ) : [];
	}

	private function compare( array $expected, array $actual ) {
		$checks = [];
		foreach ( $expected as $key => $value ) {
			$checks[ $key ] = [ 'expected' => $value, 'actual' => $actual[ $key ] ?? null, 'match' => $value === ( $actual[ $key ] ?? null ) ];
		}
		return [ 'passed' => ! empty( $checks ) && ! in_array( false, array_column( $checks, 'match' ), true ), 'checks' => $checks ];
	}

	private function normalize_html( $html ) {
		$html = preg_replace( '/<!--.*?-->/s', '', (string) $html );
		return trim( (string) preg_replace( '/\s+/', ' ', $html ) );
	}
}
