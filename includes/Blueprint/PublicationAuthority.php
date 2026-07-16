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

	public function __construct( $blueprints, $change_sets, $migrations ) {
		$this->blueprints = $blueprints;
		$this->change_sets = $change_sets;
		$this->migrations = $migrations;
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
		if ( (string) $expected_blueprint_id !== (string) ( $change_set['blueprint_id'] ?? '' ) ) {
			return $this->invalidate( $change_set_id, $status, new \WP_Error( 'eit_change_set_stale', __( 'Blueprint change set authority changed while acquiring its lock.', 'elementor-implementation-toolkit' ), [ 'status' => 409 ] ) );
		}

		$blueprint = $this->blueprints->get( $change_set['blueprint_id'] );
		$pointer_changed = ! $blueprint || $this->version_id( $change_set['from_version_id'] ?? null ) !== $this->version_id( $blueprint['active_version_id'] ?? null );
		$draft_changed = ! $blueprint || ! hash_equals( (string) $change_set['draft_checksum'], (string) ( $blueprint['draft_checksum'] ?? '' ) );
		if ( $pointer_changed || $draft_changed ) {
			return $this->invalidate(
				$change_set_id,
				$status,
				new \WP_Error(
					'eit_change_set_stale',
					__( 'Blueprint authority changed after this impact plan was prepared.', 'elementor-implementation-toolkit' ),
					[ 'status' => 409, 'active_version_changed' => $pointer_changed, 'draft_changed' => $draft_changed ]
				)
			);
		}

		$migration = $this->migrations->validate( $blueprint );
		if ( is_wp_error( $migration ) ) {
			return $this->invalidate( $change_set_id, $status, $migration );
		}
		return [ 'change_set' => $change_set, 'blueprint' => $blueprint ];
	}

	public function blockers( array $blueprint ) {
		return $this->migrations->blockers( $blueprint );
	}

	private function invalidate( $change_set_id, $status, $error ) {
		$transition = $this->change_sets->transition( $change_set_id, $status, 'failed' );
		if ( is_wp_error( $transition ) ) {
			return $transition;
		}
		return $error;
	}

	private function version_id( $value ) {
		return null === $value ? null : absint( $value );
	}
}
