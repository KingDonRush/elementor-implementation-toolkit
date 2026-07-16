<?php
/**
 * Prepare-confirm-apply service for legacy drafts and shadow evidence.
 */

namespace EIT\Blueprint;

use EIT\CCT\Repository as CctRepository;
use EIT\Diagnostics\FlightRecorder;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\MigrationStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationService {

	const TOKEN_TTL = 900;
	const MAX_SELECTION = 50;
	const SOURCE_TYPES = [ 'cpt', 'cct', 'filter_preset', 'elementor_document' ];

	private $importer;
	private $elementor;
	private $comparator;
	private $migrations;
	private $blueprints;
	private $flight;

	public function __construct( array $dependencies = [] ) {
		$this->importer = $dependencies['importer'] ?? new LegacyImporter();
		$this->elementor = $dependencies['elementor'] ?? new ElementorDocumentImporter();
		$this->comparator = $dependencies['comparator'] ?? new ShadowComparator();
		$this->migrations = $dependencies['migrations'] ?? new MigrationStore();
		$this->blueprints = $dependencies['blueprints'] ?? new BlueprintStore();
		$this->flight = $dependencies['flight'] ?? new FlightRecorder();
	}

	public function inventory() {
		$inspection = $this->importer->inspect_all();
		$records = $this->migration_index();
		$documents = $this->elementor->inventory();
		$document_index = array_column( $documents, null, 'id' );
		$items = [];
		foreach ( self::SOURCE_TYPES as $source_type ) {
			foreach ( $inspection['blueprints'][ $source_type ] ?? [] as $source_key => $blueprint ) {
				if ( ! is_array( $blueprint ) ) {
					continue;
				}
				$candidate = $this->importer->candidate( $source_type, $source_key );
				if ( ! $candidate ) {
					continue;
				}
				$record = $records[ $source_type . '|' . $source_key ] ?? null;
				$item = [
					'source_type' => $source_type,
					'source_key' => (string) $source_key,
					'name' => $blueprint['name'],
					'blueprint_id' => $blueprint['id'],
					'source_checksum' => $candidate['source_checksum'],
					'draft_checksum' => $blueprint['checksum'],
					'record_count' => $this->record_count( $source_type, $source_key ),
					'migration_status' => $record['status'] ?? 'not_imported',
					'updated_at' => $record['updated_at'] ?? null,
				];
				if ( 'elementor_document' === $source_type ) {
					$document = $document_index[ (int) $source_key ] ?? [];
					$item['active_toolkit_usage'] = ! empty( $document['active_toolkit_usage'] );
					$item['revision_count'] = (int) ( $document['revision_count'] ?? 0 );
					$item['toolkit_widgets'] = $document['toolkit_widgets'] ?? [];
				}
				$items[] = $item;
			}
		}
		$active_documents = array_values( array_filter( $documents, fn( $document ) => ! empty( $document['active_toolkit_usage'] ) ) );
		return [
			'mode' => 'shadow_read_only',
			'read_only' => ! empty( $inspection['read_only'] ),
			'source_checksum' => $inspection['source_checksum'],
			'items' => $items,
			'elementor_documents' => $documents,
			'pilot' => [
				'cpt' => [ 'source_key' => '__imoveis', 'expected_records' => 1, 'observed_records' => $this->record_count( 'cpt', '__imoveis' ) ],
				'cct' => [ 'source_key' => 'projects', 'expected_records' => 6, 'observed_records' => $this->record_count( 'cct', 'projects' ) ],
				'elementor' => [ 'expected_active_documents' => 3, 'observed_active_documents' => count( $active_documents ), 'source_keys' => array_map( fn( $document ) => (string) $document['id'], $active_documents ) ],
			],
		];
	}

	public function prepare( array $selection, $user_id ) {
		$selection = $this->normalize_selection( $selection );
		if ( is_wp_error( $selection ) ) {
			return $selection;
		}
		$prepared = [];
		foreach ( $selection as $source ) {
			$candidate = $this->importer->candidate( $source['source_type'], $source['source_key'] );
			if ( ! $candidate ) {
				return new \WP_Error( 'eit_migration_source_missing', __( 'A selected legacy source no longer exists.', 'elementor-implementation-toolkit' ) );
			}
			$prepared[] = [
				'source_type' => $source['source_type'],
				'source_key' => $source['source_key'],
				'source_checksum' => $candidate['source_checksum'],
				'blueprint_id' => $candidate['blueprint']['id'],
				'name' => $candidate['blueprint']['name'],
			];
		}
		$payload = [ 'user_id' => absint( $user_id ), 'expires' => time() + self::TOKEN_TTL, 'selection' => $prepared ];
		$encoded = $this->base64url_encode( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return [ 'expires_at' => gmdate( 'c', $payload['expires'] ), 'items' => $prepared, 'confirmation_token' => $encoded . '.' . $this->signature( $encoded ) ];
	}

	public function apply( $token, $user_id ) {
		$payload = $this->decode_token( $token, $user_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$results = [];
		foreach ( $payload['selection'] as $source ) {
			$result = $this->import_one( $source, $user_id );
			if ( is_wp_error( $result ) ) {
				$results[] = [ 'source_type' => $source['source_type'], 'source_key' => $source['source_key'], 'status' => 'failed', 'error' => [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ] ];
			} else {
				$results[] = $result;
			}
		}
		return [ 'items' => $results, 'imported' => count( array_filter( $results, fn( $result ) => 'verified' === ( $result['status'] ?? '' ) ) ), 'failed' => count( array_filter( $results, fn( $result ) => 'failed' === ( $result['status'] ?? '' ) ) ) ];
	}

	private function import_one( array $source, $user_id ) {
		$candidate = $this->importer->candidate( $source['source_type'], $source['source_key'] );
		if ( ! $candidate || ! hash_equals( (string) $source['source_checksum'], (string) $candidate['source_checksum'] ) ) {
			return new \WP_Error( 'eit_migration_source_changed', __( 'Legacy source changed after the migration plan was prepared.', 'elementor-implementation-toolkit' ) );
		}
		$blueprint = $candidate['blueprint'];
		$conflict = $this->draft_conflict( $blueprint['id'], $source['source_type'], $source['source_key'] );
		if ( $conflict ) {
			return $conflict;
		}
		$run = $this->flight->start( $blueprint['id'], 'legacy_shadow_import', [ 'source_type' => $source['source_type'], 'source_key' => $source['source_key'], 'source_checksum' => $candidate['source_checksum'], 'version' => $blueprint['version'] ] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$saved = BlueprintModule::lifecycle()->save_draft( $blueprint );
		if ( is_wp_error( $saved ) ) {
			$this->flight->finish( $run['id'], 'failed', $saved );
			return $saved;
		}
		$this->flight->event( $run['id'], 'draft_saved', [ 'draft_checksum' => $saved['draft_checksum'], 'draft_revision' => $saved['draft_revision'] ] );
		$comparison = $this->comparator->compare( $source['source_type'], $source['source_key'], $blueprint );
		$this->flight->event( $run['id'], 'shadow_compared', [ 'status' => $comparison['status'], 'query_plan' => $comparison['query_plan'], 'checks' => $comparison['checks'] ], $comparison['duration_ms'] );
		$record = $this->migrations->save(
			[
				'source_type' => $source['source_type'],
				'source_key' => $source['source_key'],
				'blueprint_id' => $blueprint['id'],
				'source_checksum' => $candidate['source_checksum'],
				'draft_checksum' => $saved['draft_checksum'],
				'status' => $comparison['status'],
				'comparison' => $comparison,
				'created_by' => $user_id,
			]
		);
		if ( is_wp_error( $record ) ) {
			$this->flight->finish( $run['id'], 'failed', $record );
			return $record;
		}
		$this->flight->finish( $run['id'], 'verified' === $comparison['status'] ? 'succeeded' : 'blocked', null, [ 'comparison_status' => $comparison['status'] ] );
		return [ 'source_type' => $source['source_type'], 'source_key' => $source['source_key'], 'blueprint_id' => $blueprint['id'], 'status' => $comparison['status'], 'comparison' => $comparison ];
	}

	private function draft_conflict( $blueprint_id, $source_type, $source_key ) {
		$existing = $this->blueprints->get( $blueprint_id );
		if ( ! $existing ) {
			return null;
		}
		$previous = $this->migrations->get_by_source( $source_type, $source_key );
		if ( ! $previous || ! hash_equals( (string) $previous['draft_checksum'], (string) $existing['draft_checksum'] ) ) {
			return new \WP_Error( 'eit_migration_draft_conflict', __( 'The imported draft has local changes and will not be overwritten.', 'elementor-implementation-toolkit' ) );
		}
		return null;
	}

	private function normalize_selection( array $selection ) {
		if ( empty( $selection ) || count( $selection ) > self::MAX_SELECTION ) {
			return new \WP_Error( 'eit_migration_selection_invalid', __( 'Choose between one and fifty legacy sources.', 'elementor-implementation-toolkit' ) );
		}
		$normalized = [];
		foreach ( $selection as $source ) {
			$type = sanitize_key( $source['source_type'] ?? '' );
			$key = 'elementor_document' === $type ? (string) absint( $source['source_key'] ?? 0 ) : sanitize_key( $source['source_key'] ?? '' );
			if ( ! in_array( $type, self::SOURCE_TYPES, true ) || '' === $key || '0' === $key ) {
				return new \WP_Error( 'eit_migration_source_invalid', __( 'Migration selection contains an invalid source.', 'elementor-implementation-toolkit' ) );
			}
			$normalized[ $type . '|' . $key ] = [ 'source_type' => $type, 'source_key' => $key ];
		}
		ksort( $normalized );
		return array_values( $normalized );
	}

	private function decode_token( $token, $user_id ) {
		$parts = explode( '.', (string) $token, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( $this->signature( $parts[0] ), $parts[1] ) ) {
			return new \WP_Error( 'eit_migration_confirmation_invalid', __( 'Migration confirmation is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$payload = json_decode( $this->base64url_decode( $parts[0] ), true );
		if ( ! is_array( $payload ) || absint( $payload['user_id'] ?? 0 ) !== absint( $user_id ) || time() > absint( $payload['expires'] ?? 0 ) ) {
			return new \WP_Error( 'eit_migration_confirmation_expired', __( 'Migration confirmation expired or belongs to another user.', 'elementor-implementation-toolkit' ) );
		}
		return $payload;
	}

	private function record_count( $source_type, $source_key ) {
		if ( 'cpt' === $source_type ) {
			$counts = wp_count_posts( sanitize_key( $source_key ) );
			return array_sum( array_map( 'intval', array_intersect_key( (array) $counts, array_flip( [ 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'eit_archived' ] ) ) ) );
		}
		if ( 'cct' === $source_type ) {
			$result = ( new CctRepository() )->query( sanitize_key( $source_key ), [ 'status' => [ 'publish', 'draft', 'review', 'archived' ], 'per_page' => 1 ] );
			return (int) ( $result['total'] ?? 0 );
		}
		return 'elementor_document' === $source_type ? ( get_post( absint( $source_key ) ) ? 1 : 0 ) : 1;
	}

	private function migration_index() {
		$index = [];
		foreach ( $this->migrations->all() as $record ) {
			$index[ $record['source_type'] . '|' . $record['source_key'] ] = $record;
		}
		return $index;
	}

	private function signature( $value ) {
		return hash_hmac( 'sha256', $value, wp_salt( 'nonce' ) );
	}

	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private function base64url_decode( $value ) {
		return (string) base64_decode( strtr( $value, '-_', '+/' ), true );
	}
}
