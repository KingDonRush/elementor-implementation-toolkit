<?php
/**
 * Revalidates a prepared change set while its publication locks are held.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PublicationAuthority {

	private $blueprints;
	private $change_sets;
	private $migrations;
	private $source_commit_guard;

	public function __construct( $blueprints, $change_sets, $migrations, $source_commit_guard = null ) {
		$this->blueprints = $blueprints;
		$this->change_sets = $change_sets;
		$this->migrations = $migrations;
		$this->source_commit_guard = $source_commit_guard ?: new LegacySourceCommitGuard();
	}

	public function authorize_locked( $change_set_id, $confirmation_token, $expected_blueprint_id ) {
		$change_set = $this->change_sets->get( $change_set_id );
		$status = (string) ( $change_set['status'] ?? '' );
		if ( ! $change_set || ! in_array( $status, [ 'prepared', 'applying' ], true ) ) {
			return new \WP_Error( 'eit_change_set_not_prepared', __( 'Blueprint change set is neither prepared nor recoverable.', 'elementor-implementation-toolkit' ) );
		}
		if ( ! hash_equals( (string) $change_set['confirmation_hash'], hash( 'sha256', (string) $confirmation_token ) ) ) {
			return new \WP_Error( 'eit_change_set_confirmation_invalid', __( 'Blueprint publication confirmation is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$authority = $this->current_authority( $change_set, $expected_blueprint_id );
		if ( is_wp_error( $authority ) ) {
			return $this->invalidate( $change_set_id, $status, $authority );
		}

		$migration = $this->validate_migration_authority( $authority['change_set'], $authority['blueprint'] );
		if ( is_wp_error( $migration ) ) {
			return $this->invalidate( $change_set_id, $status, $migration );
		}
		return $authority;
	}

	public function revalidate_source_locked( $change_set_id, $expected_blueprint_id ) {
		$change_set = $this->change_sets->get( $change_set_id );
		if ( ! $change_set || 'applying' !== ( $change_set['status'] ?? '' ) ) {
			return $this->recovery_conflict( new \WP_Error( 'eit_change_set_not_applying', __( 'Blueprint source authority can only be finalized for an applying change set.', 'elementor-implementation-toolkit' ), [ 'status' => 409 ] ) );
		}
		$authority = $this->current_authority( $change_set, $expected_blueprint_id );
		if ( is_wp_error( $authority ) ) {
			return $this->recovery_conflict( $authority );
		}
		$migration = $this->validate_migration_authority( $authority['change_set'], $authority['blueprint'] );
		if ( is_wp_error( $migration ) ) {
			return $this->recovery_conflict( $migration );
		}
		$source = $this->source_commit_guard->validate_and_lock( $authority['change_set'], $authority['blueprint'] );
		return is_wp_error( $source ) ? $this->recovery_conflict( $source ) : $authority;
	}

	public function blockers( array $blueprint ) {
		return $this->migrations->blockers( $blueprint );
	}

	private function invalidate( $change_set_id, $status, $error ) {
		if ( 'applying' === $status ) {
			return $this->recovery_conflict( $error );
		}
		$transition = $this->change_sets->transition( $change_set_id, $status, 'failed' );
		if ( is_wp_error( $transition ) ) {
			return $transition;
		}
		return $error;
	}

	private function current_authority( array $change_set, $expected_blueprint_id ) {
		if ( (string) $expected_blueprint_id !== (string) ( $change_set['blueprint_id'] ?? '' ) ) {
			return new \WP_Error( 'eit_change_set_stale', __( 'Blueprint change set authority changed while its publication lock was held.', 'elementor-implementation-toolkit' ), [ 'status' => 409 ] );
		}
		$blueprint = $this->blueprints->get( $change_set['blueprint_id'] );
		$pointer_changed = ! $blueprint || $this->version_id( $change_set['from_version_id'] ?? null ) !== $this->version_id( $blueprint['active_version_id'] ?? null );
		$draft_changed = ! $blueprint || ! hash_equals( (string) ( $change_set['draft_checksum'] ?? '' ), (string) ( $blueprint['draft_checksum'] ?? '' ) );
		if ( $pointer_changed || $draft_changed ) {
			return new \WP_Error(
				'eit_change_set_stale',
				__( 'Blueprint authority changed after this impact plan was prepared.', 'elementor-implementation-toolkit' ),
				[ 'status' => 409, 'active_version_changed' => $pointer_changed, 'draft_changed' => $draft_changed ]
			);
		}
		return [ 'change_set' => $change_set, 'blueprint' => $blueprint ];
	}

	private function recovery_conflict( $error ) {
		$data = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
		$error->add_data( array_merge( $data, [ 'status' => 409, 'recovery_required' => true ] ) );
		return $error;
	}

	private function validate_migration_authority( array $change_set, array $blueprint ) {
		if ( method_exists( $this->migrations, 'validate_snapshot' ) ) {
			$snapshot = $change_set['compiled_artifacts']['legacy_authority'] ?? null;
			return $this->migrations->validate_snapshot( $blueprint, $snapshot );
		}
		return $this->migrations->validate( $blueprint );
	}

	private function version_id( $value ) {
		return null === $value ? null : absint( $value );
	}
}
