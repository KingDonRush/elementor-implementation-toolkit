<?php
/**
 * Queryable normalized relations and repeatable-group child rows.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NormalizedValueStore {

	private $transaction;

	public function __construct( Transaction $transaction = null ) {
		$this->transaction = $transaction ?: new Transaction();
	}

	public function replace_relation_targets( $blueprint_id, $relation_id, $source_id, array $targets ) {
		global $wpdb;

		if ( ! Uuid::is_valid( $blueprint_id ) || ! Uuid::is_valid( $relation_id ) || '' === trim( (string) $source_id ) ) {
			return new \WP_Error( 'eit_relation_identity_invalid', __( 'Relation identities are invalid.', 'elementor-implementation-toolkit' ) );
		}
		return $this->transaction->run(
			function () use ( $wpdb, $blueprint_id, $relation_id, $source_id, $targets ) {
				$table = Tables::name( Tables::RELATIONS );
				$deleted = $wpdb->delete( $table, [ 'blueprint_id' => $blueprint_id, 'relation_id' => $relation_id, 'source_id' => (string) $source_id ] );
				if ( false === $deleted ) {
					return new \WP_Error( 'eit_relation_delete_failed', __( 'Existing relation values could not be replaced.', 'elementor-implementation-toolkit' ) );
				}
				foreach ( array_values( $targets ) as $position => $target ) {
					$target = is_array( $target ) ? $target : [ 'id' => $target ];
					$target_id = trim( (string) ( $target['id'] ?? '' ) );
					if ( '' === $target_id ) {
						return new \WP_Error( 'eit_relation_target_invalid', __( 'Relation target identity is required.', 'elementor-implementation-toolkit' ) );
					}
					$payload = JsonCodec::encode( $target['payload'] ?? [] );
					if ( is_wp_error( $payload ) ) {
						return $payload;
					}
					$inserted = $wpdb->insert(
						$table,
						[
							'blueprint_id' => $blueprint_id,
							'relation_id' => $relation_id,
							'source_id' => sanitize_text_field( $source_id ),
							'target_id' => sanitize_text_field( $target_id ),
							'position' => $position,
							'payload' => $payload,
							'created_at' => current_time( 'mysql', true ),
						]
					);
					if ( false === $inserted ) {
						return new \WP_Error( 'eit_relation_insert_failed', __( 'Relation value could not be saved.', 'elementor-implementation-toolkit' ) );
					}
				}
				return true;
			}
		);
	}

	public function relation_targets( $blueprint_id, $relation_id, $source_id ) {
		global $wpdb;

		$table = Tables::name( Tables::RELATIONS );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name resolves from the internal whitelist.
				"SELECT target_id,position,payload FROM `{$table}` WHERE blueprint_id = %s AND relation_id = %s AND source_id = %s ORDER BY position,id",
				$blueprint_id,
				$relation_id,
				$source_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			function ( $row ) {
				$row['position'] = (int) $row['position'];
				$row['payload'] = JsonCodec::decode( $row['payload'], [] );
				return $row;
			},
			$rows ?: []
		);
	}

	public function replace_multivalue_rows( $blueprint_id, $field_id, $owner_id, array $rows ) {
		global $wpdb;

		if ( ! Uuid::is_valid( $blueprint_id ) || ! Uuid::is_valid( $field_id ) || '' === trim( (string) $owner_id ) ) {
			return new \WP_Error( 'eit_multivalue_identity_invalid', __( 'Repeatable value identities are invalid.', 'elementor-implementation-toolkit' ) );
		}
		return $this->transaction->run(
			function () use ( $wpdb, $blueprint_id, $field_id, $owner_id, $rows ) {
				$table = Tables::name( Tables::MULTIVALUES );
				$deleted = $wpdb->delete( $table, [ 'blueprint_id' => $blueprint_id, 'field_id' => $field_id, 'owner_id' => (string) $owner_id ] );
				if ( false === $deleted ) {
					return new \WP_Error( 'eit_multivalue_delete_failed', __( 'Existing repeatable values could not be replaced.', 'elementor-implementation-toolkit' ) );
				}
				foreach ( array_values( $rows ) as $position => $row ) {
					$row = is_array( $row ) && isset( $row['value'] ) ? $row : [ 'value' => $row ];
					$row_id = isset( $row['id'] ) && Uuid::is_valid( $row['id'] ) ? strtolower( $row['id'] ) : Uuid::v4();
					$value = JsonCodec::encode( $row['value'] );
					if ( is_wp_error( $value ) ) {
						return $value;
					}
					$inserted = $wpdb->insert(
						$table,
						[
							'blueprint_id' => $blueprint_id,
							'field_id' => $field_id,
							'owner_id' => sanitize_text_field( $owner_id ),
							'row_id' => $row_id,
							'position' => $position,
							'value' => $value,
							'created_at' => current_time( 'mysql', true ),
						]
					);
					if ( false === $inserted ) {
						return new \WP_Error( 'eit_multivalue_insert_failed', __( 'Repeatable value could not be saved.', 'elementor-implementation-toolkit' ) );
					}
				}
				return true;
			}
		);
	}

	public function multivalue_rows( $blueprint_id, $field_id, $owner_id ) {
		global $wpdb;

		$table = Tables::name( Tables::MULTIVALUES );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name resolves from the internal whitelist.
				"SELECT row_id,position,value FROM `{$table}` WHERE blueprint_id = %s AND field_id = %s AND owner_id = %s ORDER BY position,id",
				$blueprint_id,
				$field_id,
				$owner_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			function ( $row ) {
				$row['position'] = (int) $row['position'];
				$row['value'] = JsonCodec::decode( $row['value'], null );
				return $row;
			},
			$rows ?: []
		);
	}
}
