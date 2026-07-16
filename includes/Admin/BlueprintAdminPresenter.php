<?php
/**
 * Read-only administrative projection for Systems, Runs and Diagnostics.
 */

namespace EIT\Admin;

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\BlueprintModule;
use EIT\Blueprint\NodeTypeRegistry;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\RunStore;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\Tables;
use EIT\Infrastructure\VersionStore;
use EIT\Contracts\FieldContractSourceInterface;
use EIT\Elementor\Contracts\ElementorTemplateCatalog;
use EIT\Blueprint\MigrationService;
use EIT\Diagnostics\HandoffNotesGenerator;
use EIT\Diagnostics\ScenarioRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlueprintAdminPresenter {

	private $blueprints;
	private $versions;
	private $runs;
	private $validator;

	public function __construct() {
		$this->blueprints = new BlueprintStore();
		$this->versions = new VersionStore();
		$this->runs = new RunStore();
		$this->validator = new BlueprintValidator();
	}

	public function systems() {
		return array_map(
			function ( $record ) {
				return $this->system_summary( $record );
			},
			$this->blueprints->all()
		);
	}

	public function system( $id ) {
		$record = $this->blueprints->get( $id );
		if ( ! $record ) {
			return null;
		}
		$versions = $this->versions->for_blueprint( $id );
		$active = $this->active_version( $record, $versions );
		$document = is_array( $record['draft_document'] ) ? $record['draft_document'] : null;
		if ( $document && $active && (int) $document['version'] <= (int) $active['version'] ) {
			$document['version'] = (int) $active['version'] + 1;
			unset( $document['checksum'] );
		}
		$validation = $document ? $this->validator->validate( $document )->to_array() : [ 'valid' => false, 'errors' => [] ];

		return array_merge(
			$this->system_summary( $record, $active ),
			[
				'document' => $document,
				'validation' => $validation,
				'active_version' => $active ? $this->version_summary( $active ) : null,
				'versions' => array_map( [ $this, 'version_summary' ], $versions ),
			]
		);
	}

	public function schema() {
		$registries = BlueprintModule::registries();
		$primitives = [];
		foreach ( $registries->field_primitives()->all() as $id => $primitive ) {
			$primitives[ $id ] = [
				'id' => $id,
				'version' => $primitive->get_version(),
				'definition' => $primitive->get_definition(),
				'health' => $primitive->health_check(),
			];
		}

		return [
			'api_version' => BlueprintValidator::API_VERSION,
			'kind' => BlueprintValidator::KIND,
			'node_types' => ( new NodeTypeRegistry() )->nodes(),
			'connections' => ( new NodeTypeRegistry() )->connections(),
			'primitives' => $primitives,
			'adapters' => $this->extension_metadata( $registries->storage_adapters()->all() ),
			'elementor_templates' => ( new ElementorTemplateCatalog() )->all(),
		];
	}

	public function recent_runs( $limit = 50 ) {
		return $this->runs->recent( $limit );
	}

	public function migration_inventory() {
		return ( new MigrationService() )->inventory();
	}

	public function scenarios() {
		return ( new ScenarioRunner() )->all();
	}

	public function handoff_notes() {
		return ( new HandoffNotesGenerator() )->generate();
	}

	public function diagnostics() {
		$schema = SchemaManager::verify();
		$registries = BlueprintModule::registries();
		$recent_runs = $this->runs->recent( 100 );
		return [
			'infrastructure' => [
				'ok' => ! is_wp_error( $schema ),
				'message' => is_wp_error( $schema ) ? $schema->get_error_message() : __( 'All Blueprint infrastructure tables expose their required columns.', 'elementor-implementation-toolkit' ),
				'tables' => count( Tables::keys() ),
			],
			'blueprints' => count( $this->blueprints->all() ),
			'field_primitives' => $registries->field_primitives()->health(),
			'storage_adapters' => $registries->storage_adapters()->health(),
			'collection_providers' => $registries->collection_providers()->health(),
			'form_actions' => $registries->form_actions()->health(),
			'presentation_adapters' => $registries->presentation_adapters()->health(),
			'elementor' => [
				'available' => did_action( 'elementor/loaded' ) > 0,
				'version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			],
			'woocommerce' => [
				'available' => defined( 'WC_VERSION' ) && class_exists( '\WooCommerce' ),
				'version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			],
			'flight_recorder' => [
				'runs' => count( $recent_runs ),
				'events' => array_sum( array_column( $recent_runs, 'event_count' ) ),
			],
		];
	}

	private function system_summary( array $record, array $active = null ) {
		if ( null === $active && ! empty( $record['active_version_id'] ) ) {
			$active = $this->versions->get( $record['active_version_id'] );
		}
		$status = ! $active ? 'draft' : ( hash_equals( (string) $record['draft_checksum'], (string) $active['checksum'] ) ? 'published' : 'changes_pending' );
		return [
			'id' => $record['id'],
			'slug' => $record['slug'],
			'name' => $record['name'],
			'status' => $status,
			'draft_revision' => (int) $record['draft_revision'],
			'draft_checksum' => $record['draft_checksum'],
			'active_version_id' => $record['active_version_id'],
			'updated_at' => $record['updated_at'],
		];
	}

	private function active_version( array $record, array $versions ) {
		foreach ( $versions as $version ) {
			if ( (int) $record['active_version_id'] === (int) $version['id'] ) {
				return $version;
			}
		}
		return null;
	}

	private function version_summary( array $version ) {
		return [
			'id' => (int) $version['id'],
			'version' => (int) $version['version'],
			'checksum' => $version['checksum'],
			'published_at' => $version['published_at'],
		];
	}

	private function extension_metadata( array $extensions ) {
		$metadata = [];
		foreach ( $extensions as $id => $extension ) {
			$metadata[ $id ] = [
				'id' => $id,
				'version' => $extension->get_version(),
				'capabilities' => $extension->get_capabilities(),
				'health' => $extension->health_check(),
			];
			if ( $extension instanceof FieldContractSourceInterface ) {
				$metadata[ $id ]['fields'] = $extension->get_field_contracts();
			}
		}
		return $metadata;
	}
}
