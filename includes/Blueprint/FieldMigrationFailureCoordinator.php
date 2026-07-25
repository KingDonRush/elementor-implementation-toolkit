<?php
/**
 * Persists recoverable and terminal migration failures across an operation group.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FieldMigrationFailureCoordinator {

	private $operations;

	public function __construct( $operations ) {
		$this->operations = $operations;
	}

	public function has_operations( $blueprint_id, $change_set_id ) {
		$records = $this->operations->for_change_set( $blueprint_id, $change_set_id );
		return is_wp_error( $records ) ? $records : ! empty( $records );
	}

	public function can_retry( $blueprint_id, $change_set_id ) {
		$records = $this->operations->for_change_set( $blueprint_id, $change_set_id );
		if ( is_wp_error( $records ) || ! $records ) {
			return is_wp_error( $records ) ? $records : false;
		}
		foreach ( $records as $record ) {
			if ( in_array( $record['status'] ?? '', [ 'terminal_failure', 'aborted', 'rolled_back' ], true ) ) {
				return false;
			}
		}
		return true;
	}

	public function record_retryable_phase( $blueprint_id, $change_set_id, $expected_status, \WP_Error $error ) {
		if ( method_exists( $this->operations, 'record_retryable_group_failure' ) ) {
			return $this->operations->record_retryable_group_failure( $blueprint_id, $change_set_id, $expected_status, $error->get_error_code() );
		}
		$records = $this->operations->for_change_set( $blueprint_id, $change_set_id );
		if ( is_wp_error( $records ) ) {
			return $records;
		}
		foreach ( $records as $record ) {
			if ( $expected_status !== ( $record['status'] ?? '' ) ) {
				continue;
			}
			$result = $this->operations->record_failure( $record['id'], $expected_status, $record['state_revision'], 'retryable_failure', $error->get_error_code() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	public function record_switch_failure( $blueprint_id, $change_set_id, \WP_Error $error ) {
		$retryable = $this->retryable( $error );
		if ( $retryable ) {
			$records = $this->record_retryable_phase( $blueprint_id, $change_set_id, 'switching', $error );
			return is_wp_error( $records ) ? $records : [ 'retryable' => true, 'records' => $records ];
		}
		$records = $this->operations->for_change_set( $blueprint_id, $change_set_id );
		if ( is_wp_error( $records ) || ! $records ) {
			return is_wp_error( $records ) ? $records : $this->state_error();
		}
		$leader = reset( $records );
		if ( 'switching' !== ( $leader['status'] ?? '' ) || ! method_exists( $this->operations, 'record_terminal_group_failure' ) ) {
			return $this->state_error();
		}
		$failed = $this->operations->record_terminal_group_failure(
			$blueprint_id,
			$change_set_id,
			$leader['id'],
			'switching',
			$leader['state_revision'],
			$error->get_error_code()
		);
		return is_wp_error( $failed ) ? $failed : [ 'retryable' => false, 'records' => $failed ];
	}

	public function record_operation_failure( array $record, \WP_Error $error, $retryable ) {
		if ( ! $retryable && ! empty( $record['blueprint_id'] ) && ! empty( $record['change_set_id'] ) && method_exists( $this->operations, 'record_terminal_group_failure' ) ) {
			$failed = $this->operations->record_terminal_group_failure(
				$record['blueprint_id'],
				$record['change_set_id'],
				$record['id'],
				$record['status'],
				$record['state_revision'],
				$error->get_error_code()
			);
		} else {
			$failed = $this->operations->record_failure(
				$record['id'],
				$record['status'],
				$record['state_revision'],
				$retryable ? 'retryable_failure' : 'terminal_failure',
				$error->get_error_code()
			);
		}
		return is_wp_error( $failed ) ? $failed : $error;
	}

	public function retryable( \WP_Error $error ) {
		$terminal = [
			'eit_migration_value_invalid',
			'eit_migration_target_collision',
			'eit_migration_source_ambiguous',
			'eit_migration_driver_progress_invalid',
			'eit_migration_target_exists',
			'eit_migration_target_diverged',
			'eit_migration_identifier_invalid',
			'eit_migration_identifier_collision',
			'eit_migration_meta_duplicate',
			'eit_migration_source_missing',
			'eit_migration_storage_missing',
			'eit_migration_transform_mismatch',
			'eit_migration_transform_invalid',
			'eit_migration_transform_unsupported',
			'eit_migration_semantics_missing',
			'eit_migration_driver_missing',
			'eit_migration_driver_unsupported',
			'eit_migration_batch_invalid',
			'eit_migration_fingerprint_side_invalid',
			'eit_migration_record_missing',
			'eit_blueprint_activation_stale',
			'eit_blueprint_version_sequence',
			'eit_change_set_state_conflict',
		];
		return ! in_array( $error->get_error_code(), $terminal, true );
	}

	private function state_error() {
		return new \WP_Error( 'eit_migration_switch_failure_state_invalid', __( 'Migration switch failure could not be recorded in a safe pre-switch state.', 'elementor-implementation-toolkit' ) );
	}
}
