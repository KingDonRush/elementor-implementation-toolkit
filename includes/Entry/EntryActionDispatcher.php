<?php
/**
 * Enqueues, executes and retries Entry side effects independently of content.
 */

namespace EIT\Entry;

use EIT\Blueprint\BlueprintModule;
use EIT\Infrastructure\EntryActionStore;
use EIT\Infrastructure\PayloadRedactor;
use EIT\Infrastructure\RunStore;
use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryActionDispatcher {

	private $jobs;
	private $runs;
	private $redactor;
	private $registries;

	public function __construct( ?EntryActionStore $jobs = null, ?RunStore $runs = null, ?PayloadRedactor $redactor = null, ?RegistryHub $registries = null ) {
		$this->jobs = $jobs ?: new EntryActionStore();
		$this->runs = $runs ?: new RunStore();
		$this->redactor = $redactor ?: new PayloadRedactor();
		$this->registries = $registries ?: BlueprintModule::registries();
	}

	public function dispatch( array $contract, $submission_id, $event, array $context ) {
		$results = [];
		foreach ( $contract['actions'] ?? [] as $action ) {
			if ( ! in_array( $event, $action['events'] ?? [], true ) || ( ! empty( $context['is_guest'] ) && 'redirect' !== ( $action['type'] ?? '' ) ) ) {
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
					'context' => [
						'action' => $action,
						'execution' => $context,
						'diagnostic' => [ 'action' => $this->redact( $action ), 'execution' => $this->redact( $context ) ],
					],
				]
			);
			if ( is_wp_error( $job ) ) {
				$results[] = [ 'action_id' => $action['id'], 'status' => 'enqueue_failed' ];
				continue;
			}
			$results[] = 'succeeded' === ( $job['status'] ?? '' ) ? $this->stored_result( $job ) : $this->process( $job['id'] );
		}
		return $results;
	}

	public function process( $job_id ) {
		$job = $this->jobs->claim( $job_id );
		if ( ! $job ) {
			$existing = $this->jobs->get( $job_id );
			$status = 'running' === ( $existing['status'] ?? '' ) ? 'processing' : ( 'failed' === ( $existing['status'] ?? '' ) ? 'retryable_failure' : 'not_claimed' );
			return [ 'job_id' => $job_id, 'action_id' => $existing['action_id'] ?? '', 'status' => $status ];
		}
		$run = $this->runs->start( $job['blueprint_id'], 'entry_action', null, [ 'job_id' => $job['id'], 'action_type' => $job['action_type'], 'surface_id' => $job['surface_id'] ] );
		$action_contract = is_array( $job['context']['action'] ?? null ) ? $job['context']['action'] : [];
		$action = $this->registries->form_actions()->get( $job['action_type'] );
		$result = $this->action_can_execute( $action, $action_contract, $job['action_type'] );
		if ( true === $result ) {
			try {
				$execution = is_array( $job['context']['execution'] ?? null ) ? $job['context']['execution'] : [];
				$result = $action->execute( $action_contract, array_merge( $execution, [ 'event' => $job['event'], 'submission_id' => $job['submission_id'], 'action_id' => $job['action_id'], 'action_job_id' => $job['id'] ] ) );
			} catch ( \Throwable $error ) {
				$result = new \WP_Error( 'eit_entry_action_execution_failed', __( 'The registered Entry action could not be executed.', 'elementor-implementation-toolkit' ) );
			}
			if ( ! is_wp_error( $result ) && ! is_array( $result ) ) {
				$result = new \WP_Error( 'eit_entry_action_result_invalid', __( 'The registered Entry action returned an invalid result.', 'elementor-implementation-toolkit' ) );
			}
		}
		$finished = $this->jobs->finish( $job['id'], $result );
		$completion = is_wp_error( $finished ) ? $finished : $result;
		if ( ! is_wp_error( $run ) ) {
			$this->runs->finish( $run['id'], is_wp_error( $completion ) ? 'failed' : 'succeeded', $completion );
		}
		try {
			$capabilities = $action ? $this->capabilities( $action->get_capabilities() ) : [];
		} catch ( \Throwable $error ) {
			$capabilities = [];
		}
		if ( is_wp_error( $result ) && ! is_wp_error( $finished ) && in_array( 'retryable', $capabilities ?: [], true ) && $finished['attempts'] < 5 ) {
			wp_schedule_single_event( strtotime( $finished['available_at'] . ' UTC' ), 'eit_retry_entry_action', [ $job['id'] ] );
		}
		return [
			'job_id' => $job['id'],
			'action_id' => $job['action_id'],
			'status' => is_wp_error( $finished ) ? 'completion_unknown' : ( is_wp_error( $result ) ? 'retryable_failure' : 'succeeded' ),
			'redirect' => ! is_wp_error( $completion ) ? ( $result['redirect'] ?? null ) : null,
		];
	}

	public function retry( $job_id ) {
		$job = $this->jobs->retry_now( $job_id );
		return $job ? $this->process( $job_id ) : [ 'job_id' => $job_id, 'status' => 'not_retryable' ];
	}

	private function redact( array $value ) {
		return $this->redactor->redact( $value );
	}

	private function action_can_execute( $action, array $contract, $action_type ) {
		if ( ! $action ) {
			return new \WP_Error( 'eit_entry_action_missing', __( 'The registered Entry action is unavailable.', 'elementor-implementation-toolkit' ) );
		}
		try {
			$health = $action->health_check();
			$current_id = strtolower( trim( (string) $action->get_id() ) );
			$current_version = (string) $action->get_version();
			$current_capabilities = $this->capabilities( $action->get_capabilities() );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'eit_entry_action_unhealthy', __( 'The registered Entry action failed its runtime canary.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! is_array( $health ) || empty( $health['ok'] ) || $current_id !== $action_type || null === $current_capabilities ) {
			return new \WP_Error( 'eit_entry_action_unhealthy', __( 'The registered Entry action failed its runtime canary.', 'elementor-implementation-toolkit' ) );
		}
		$compiled = is_array( $contract['extension'] ?? null ) ? $contract['extension'] : [];
		if ( ! $compiled ) {
			return true;
		}
		$compiled_capabilities = $this->capabilities( $compiled['capabilities'] ?? null );
		if ( ( $compiled['id'] ?? '' ) !== $action_type || ( $compiled['version'] ?? '' ) !== $current_version ) {
			return new \WP_Error( 'eit_entry_action_version_mismatch', __( 'The Entry action version differs from the published contract.', 'elementor-implementation-toolkit' ) );
		}
		if ( null === $compiled_capabilities || $compiled_capabilities !== $current_capabilities || ! in_array( 'durable_job', $current_capabilities, true ) ) {
			return new \WP_Error( 'eit_entry_action_capability_mismatch', __( 'The Entry action capabilities differ from the published contract.', 'elementor-implementation-toolkit' ) );
		}
		return true;
	}

	private function capabilities( $capabilities ) {
		if ( ! is_array( $capabilities ) || ! array_is_list( $capabilities ) ) {
			return null;
		}
		$result = [];
		foreach ( $capabilities as $capability ) {
			$capability = (string) $capability;
			if ( ! preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $capability ) || isset( $result[ $capability ] ) ) {
				return null;
			}
			$result[ $capability ] = true;
		}
		$result = array_keys( $result );
		sort( $result, SORT_STRING );
		return $result;
	}

	private function stored_result( array $job ) {
		return [
			'job_id' => $job['id'],
			'action_id' => $job['action_id'],
			'status' => 'succeeded',
			'redirect' => $job['result']['redirect'] ?? null,
		];
	}
}
