<?php
/**
 * Enqueues, executes and retries Entry side effects independently of content.
 */

namespace EIT\Entry;

use EIT\Blueprint\BlueprintModule;
use EIT\Infrastructure\EntryActionStore;
use EIT\Infrastructure\RunStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryActionDispatcher {

	private $jobs;
	private $runs;

	public function __construct( EntryActionStore $jobs = null, RunStore $runs = null ) {
		$this->jobs = $jobs ?: new EntryActionStore();
		$this->runs = $runs ?: new RunStore();
	}

	public function dispatch( array $contract, $submission_id, $event, array $context ) {
		$results = [];
		foreach ( $contract['actions'] ?? [] as $action ) {
			if ( ! in_array( $event, $action['events'] ?? [], true ) || ( ! empty( $context['is_guest'] ) && ! in_array( $action['type'] ?? '', [ 'redirect', 'notification' ], true ) ) ) {
				continue;
			}
			$job = $this->jobs->enqueue(
				[
					'blueprint_id' => $contract['blueprint_id'],
					'surface_id' => $contract['surface_id'],
					'submission_id' => $submission_id,
					'action_id' => $action['id'],
					'action_type' => $action['type'],
					'event' => $event,
					'context' => [ 'action' => $this->redact( $action ), 'execution' => $this->redact( $context ) ],
				]
			);
			if ( is_wp_error( $job ) ) {
				$results[] = [ 'action_id' => $action['id'], 'status' => 'enqueue_failed' ];
				continue;
			}
			$results[] = $this->process( $job['id'] );
		}
		return $results;
	}

	public function process( $job_id ) {
		$job = $this->jobs->claim( $job_id );
		if ( ! $job ) {
			return [ 'job_id' => $job_id, 'status' => 'not_claimed' ];
		}
		$run = $this->runs->start( $job['blueprint_id'], 'entry_action', null, [ 'job_id' => $job['id'], 'action_type' => $job['action_type'], 'surface_id' => $job['surface_id'] ] );
		$action = BlueprintModule::registries()->form_actions()->get( $job['action_type'] );
		$result = $action
			? $action->execute( $job['context']['action'] ?? [], array_merge( $job['context']['execution'] ?? [], [ 'event' => $job['event'] ] ) )
			: new \WP_Error( 'eit_entry_action_missing', __( 'The registered Entry action is unavailable.', 'elementor-implementation-toolkit' ) );
		$finished = $this->jobs->finish( $job['id'], $result );
		if ( ! is_wp_error( $run ) ) {
			$this->runs->finish( $run['id'], is_wp_error( $result ) ? 'failed' : 'succeeded', $result );
		}
		if ( is_wp_error( $result ) && ! is_wp_error( $finished ) && $finished['attempts'] < 5 ) {
			wp_schedule_single_event( strtotime( $finished['available_at'] . ' UTC' ), 'eit_retry_entry_action', [ $job['id'] ] );
		}
		return [
			'job_id' => $job['id'],
			'action_id' => $job['action_id'],
			'status' => is_wp_error( $result ) ? 'retryable_failure' : 'succeeded',
			'redirect' => ! is_wp_error( $result ) ? ( $result['redirect'] ?? null ) : null,
		];
	}

	public function retry( $job_id ) {
		$job = $this->jobs->retry_now( $job_id );
		return $job ? $this->process( $job_id ) : [ 'job_id' => $job_id, 'status' => 'not_retryable' ];
	}

	private function redact( array $value ) {
		foreach ( $value as $key => $child ) {
			if ( preg_match( '/secret|token|password|authorization|cookie/i', (string) $key ) ) {
				$value[ $key ] = '[redacted]';
			} elseif ( is_array( $child ) ) {
				$value[ $key ] = $this->redact( $child );
			}
		}
		return $value;
	}
}
