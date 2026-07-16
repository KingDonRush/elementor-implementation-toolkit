<?php
/**
 * Records bounded, redacted execution facts without storing full content.
 */

namespace EIT\Diagnostics;

use EIT\Infrastructure\RunEventStore;
use EIT\Infrastructure\RunStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlightRecorder {

	private $runs;
	private $events;

	public function __construct( RunStore $runs = null, RunEventStore $events = null ) {
		$this->runs = $runs ?: new RunStore();
		$this->events = $events ?: new RunEventStore();
	}

	public function start( $blueprint_id, $operation, array $context = [], $change_set_id = null ) {
		$run = $this->runs->start( $blueprint_id, $operation, $change_set_id, $context );
		if ( ! is_wp_error( $run ) ) {
			$this->events->append( $run['id'], 'started', [ 'operation' => $operation, 'context' => $context ] );
		}
		return $run;
	}

	public function event( $run_id, $event_type, array $payload = [], $duration_ms = null ) {
		return $this->events->append( $run_id, $event_type, $payload, $duration_ms );
	}

	public function finish( $run_id, $status, $error = null, array $summary = [] ) {
		$event = [ 'status' => sanitize_key( $status ), 'summary' => $summary ];
		if ( is_wp_error( $error ) ) {
			$event['error'] = [ 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ];
		}
		$this->events->append( $run_id, 'finished', $event );
		return $this->runs->finish( $run_id, $status, $error );
	}

	public function measure( $run_id, $event_type, callable $operation, array $context = [] ) {
		$started = microtime( true );
		$result = $operation();
		$duration = ( microtime( true ) - $started ) * 1000;
		$payload = $context;
		$payload['outcome'] = is_wp_error( $result ) ? [ 'error' => $result->get_error_code() ] : [ 'ok' => true ];
		$this->events->append( $run_id, $event_type, $payload, $duration );
		return $result;
	}
}
