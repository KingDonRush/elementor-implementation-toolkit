<?php
/**
 * Persists reproducible, bounded QA scenarios and their last result.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScenarioStore {

	public function save( array $scenario ) {
		global $wpdb;

		$id = (string) ( $scenario['id'] ?? Uuid::v4() );
		if ( ! Uuid::is_valid( $id ) ) {
			return new \WP_Error( 'eit_scenario_id_invalid', __( 'QA Scenario ID must be a UUID.', 'elementor-implementation-toolkit' ) );
		}
		$request = JsonCodec::encode( $scenario['request'] ?? [] );
		$expected = JsonCodec::encode( $scenario['expected'] ?? [] );
		if ( is_wp_error( $request ) || is_wp_error( $expected ) ) {
			return is_wp_error( $request ) ? $request : $expected;
		}
		$existing = $this->get( $id );
		$now = current_time( 'mysql', true );
		$blueprint_id = empty( $scenario['blueprint_id'] ) ? null : (string) $scenario['blueprint_id'];
		$definition_changed = $existing && (
			$blueprint_id !== $existing['blueprint_id']
			|| sanitize_key( $scenario['kind'] ?? '' ) !== $existing['kind']
			|| ( $scenario['request'] ?? [] ) !== $existing['request']
			|| ( $scenario['expected'] ?? [] ) !== $existing['expected']
		);
		$data = [
			'blueprint_id' => $blueprint_id,
			'name' => sanitize_text_field( $scenario['name'] ?? '' ),
			'kind' => sanitize_key( $scenario['kind'] ?? '' ),
			'request' => $request,
			'expected' => $expected,
			'status' => $existing && ! $definition_changed ? $existing['status'] : 'never_run',
			'created_by' => $existing ? $existing['created_by'] : absint( $scenario['created_by'] ?? 0 ),
			'updated_at' => $now,
		];
		if ( '' === $data['name'] || '' === $data['kind'] ) {
			return new \WP_Error( 'eit_scenario_required_fields', __( 'QA Scenario name and kind are required.', 'elementor-implementation-toolkit' ) );
		}
		if ( $existing ) {
			if ( $definition_changed ) {
				$data['last_result'] = null;
			}
			$result = $wpdb->update( Tables::name( Tables::QA_SCENARIOS ), $data, [ 'id' => $id ] );
		} else {
			$data['id'] = $id;
			$data['last_result'] = null;
			$data['created_at'] = $now;
			$result = $wpdb->insert( Tables::name( Tables::QA_SCENARIOS ), $data );
		}
		return false === $result ? new \WP_Error( 'eit_scenario_write_failed', __( 'QA Scenario could not be saved.', 'elementor-implementation-toolkit' ) ) : $this->get( $id );
	}

	public function record_result( $id, $status, array $result ) {
		global $wpdb;

		$encoded = JsonCodec::encode( $result );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		$updated = $wpdb->update(
			Tables::name( Tables::QA_SCENARIOS ),
			[ 'status' => sanitize_key( $status ), 'last_result' => $encoded, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => (string) $id ]
		);
		return false === $updated ? new \WP_Error( 'eit_scenario_result_failed', __( 'QA Scenario result could not be recorded.', 'elementor-implementation-toolkit' ) ) : $this->get( $id );
	}

	public function get( $id ) {
		global $wpdb;

		$table = Tables::name( Tables::QA_SCENARIOS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %s", (string) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	public function all() {
		global $wpdb;

		$table = Tables::name( Tables::QA_SCENARIOS );
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY updated_at DESC,name", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	private function hydrate( array $row ) {
		$row['created_by'] = (int) $row['created_by'];
		$row['request'] = JsonCodec::decode( $row['request'], [] );
		$row['expected'] = JsonCodec::decode( $row['expected'], [] );
		$row['last_result'] = JsonCodec::decode( $row['last_result'], null );
		return $row;
	}
}
