<?php
/**
 * Mutable draft and active-version pointer storage.
 */

namespace EIT\Infrastructure;

use EIT\Blueprint\Uuid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlueprintStore {

	public function save_draft( array $document, $checksum ) {
		global $wpdb;

		$id = strtolower( (string) ( $document['id'] ?? '' ) );
		if ( ! Uuid::is_valid( $id ) ) {
			return new \WP_Error( 'eit_blueprint_invalid_id', __( 'Blueprint ID must be a UUID.', 'elementor-implementation-toolkit' ) );
		}
		$encoded = JsonCodec::encode( $document );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}

		$existing = $this->get( $id );
		$slug = sanitize_title( $document['slug'] ?? $document['name'] ?? '' );
		$name = sanitize_text_field( $document['name'] ?? '' );
		if ( '' === $slug || '' === $name ) {
			return new \WP_Error( 'eit_blueprint_name_required', __( 'Blueprint name and slug are required.', 'elementor-implementation-toolkit' ) );
		}
		if ( $existing && ! empty( $existing['active_version_id'] ) && $slug !== $existing['slug'] ) {
			return new \WP_Error( 'eit_blueprint_slug_locked', __( 'A published Blueprint slug requires a migration plan to change.', 'elementor-implementation-toolkit' ) );
		}

		$now = current_time( 'mysql', true );
		if ( $existing ) {
			$result = $wpdb->update(
				Tables::name( Tables::BLUEPRINTS ),
				[
					'slug' => $slug,
					'name' => $name,
					'draft_revision' => (int) $existing['draft_revision'] + 1,
					'draft_checksum' => (string) $checksum,
					'draft_document' => $encoded,
					'updated_at' => $now,
				],
				[ 'id' => $id ]
			);
		} else {
			$result = $wpdb->insert(
				Tables::name( Tables::BLUEPRINTS ),
				[
					'id' => $id,
					'slug' => $slug,
					'name' => $name,
					'draft_revision' => 1,
					'draft_checksum' => (string) $checksum,
					'draft_document' => $encoded,
					'active_version_id' => null,
					'created_at' => $now,
					'updated_at' => $now,
				]
			);
		}

		return false === $result
			? new \WP_Error( 'eit_blueprint_draft_write_failed', __( 'Blueprint draft could not be saved.', 'elementor-implementation-toolkit' ), [ 'database_error' => sanitize_text_field( $wpdb->last_error ) ] )
			: $this->get( $id );
	}

	public function get( $id ) {
		global $wpdb;

		$table = Tables::name( Tables::BLUEPRINTS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %s", (string) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	public function all() {
		global $wpdb;

		$table = Tables::name( Tables::BLUEPRINTS );
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	public function set_active_version( $blueprint_id, $version_id ) {
		global $wpdb;

		$result = $wpdb->update(
			Tables::name( Tables::BLUEPRINTS ),
			[ 'active_version_id' => absint( $version_id ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => (string) $blueprint_id ]
		);
		return false === $result
			? new \WP_Error( 'eit_blueprint_activation_failed', __( 'Blueprint active version could not be changed.', 'elementor-implementation-toolkit' ) )
			: true;
	}

	public function delete_unpublished( $blueprint_id ) {
		global $wpdb;

		$record = $this->get( $blueprint_id );
		if ( ! $record ) {
			return false;
		}
		if ( ! empty( $record['active_version_id'] ) ) {
			return new \WP_Error( 'eit_blueprint_delete_published', __( 'Published Blueprints remain auditable and cannot be deleted.', 'elementor-implementation-toolkit' ) );
		}

		return ( new Transaction() )->run(
			function () use ( $wpdb, $blueprint_id ) {
				$change_sets = $wpdb->delete( Tables::name( Tables::CHANGE_SETS ), [ 'blueprint_id' => (string) $blueprint_id ] );
				if ( false === $change_sets ) {
					return new \WP_Error( 'eit_blueprint_delete_changes_failed', __( 'Blueprint draft change sets could not be removed.', 'elementor-implementation-toolkit' ) );
				}
				$deleted = $wpdb->delete( Tables::name( Tables::BLUEPRINTS ), [ 'id' => (string) $blueprint_id ] );
				return false === $deleted
					? new \WP_Error( 'eit_blueprint_delete_failed', __( 'Blueprint draft could not be deleted.', 'elementor-implementation-toolkit' ) )
					: 1 === $deleted;
			}
		);
	}

	public function hydrate( array $row ) {
		$row['draft_revision'] = (int) $row['draft_revision'];
		$row['active_version_id'] = null === $row['active_version_id'] ? null : (int) $row['active_version_id'];
		$row['draft_document'] = JsonCodec::decode( $row['draft_document'], null );
		return $row;
	}
}
