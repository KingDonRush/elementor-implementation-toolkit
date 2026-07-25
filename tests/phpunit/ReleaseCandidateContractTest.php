<?php
/**
 * Pure release-candidate contracts for migration evidence and diagnostics.
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\ActiveRuntimeProbe;
use EIT\Blueprint\CandidateArtifactProbe;
use EIT\Blueprint\Compiler;
use EIT\Blueprint\ImpactMapBuilder;
use EIT\Blueprint\LegacyAuthorityProbe;
use EIT\Blueprint\LegacyImporter;
use EIT\Blueprint\LegacySemanticContract;
use EIT\Infrastructure\PayloadRedactor;
use PHPUnit\Framework\TestCase;

class ReleaseCandidateContractTest extends TestCase {

	public function test_diagnostic_payloads_redact_secrets_and_full_content(): void {
		$redacted = ( new PayloadRedactor() )->redact(
			[
				'authorization' => 'Bearer should-never-persist',
				'context' => [ 'request_id' => 'req-123', 'content' => '<article>private body</article>' ],
				'endpoint' => 'https://example.test/hook?token=secret-value',
				'long_fact' => str_repeat( 'x', PayloadRedactor::MAX_STRING_BYTES + 1 ),
			]
		);

		self::assertSame( '[redacted]', $redacted['authorization'] );
		self::assertSame( 'req-123', $redacted['context']['request_id'] );
		self::assertTrue( $redacted['context']['content']['redacted'] );
		self::assertSame( hash( 'sha256', '<article>private body</article>' ), $redacted['context']['content']['sha256'] );
		self::assertSame( '[redacted]', $redacted['endpoint'] );
		self::assertSame( PayloadRedactor::MAX_STRING_BYTES + 1, $redacted['long_fact']['bytes'] );
	}

	public function test_impact_matching_accepts_contract_bindings_without_substring_pollution(): void {
		$matcher = new ReflectionMethod( ImpactMapBuilder::class, 'matching_tokens' );
		$uuid = '3deccf4a-531d-5c36-bd11-eec7a86fbf32';
		$key = 'eit_price';
		$document = [
			'exact' => $uuid,
			'configured' => '{"field_id":"eit_price"}',
			'encoded' => rawurlencode( '{"collection_id":"' . $uuid . '"}' ),
			'noise' => [ 'projects', 'a' . $uuid . 'b', 'eit_price_suffix' ],
		];

		$matches = $matcher->invoke( new ImpactMapBuilder(), $document, [ $uuid, $key, 'project' ] );
		sort( $matches );
		self::assertSame( [ $uuid, $key ], $matches );
	}

	public function test_legacy_import_records_an_incompatible_query_capability_downgrade(): void {
		$blueprint = ( new LegacyImporter() )->import_entity(
			'cct',
			'projects',
			[
				'singular' => 'Project',
				'fields' => [ [ 'key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'active' => true, 'filterable' => true ] ],
			]
		);
		$field = $blueprint['nodes'][1]['config']['fields'][0];

		self::assertFalse( $field['indexing']['filter'] );
		self::assertSame( [ 'filter' ], $field['migration']['capability_downgrades'] );
		self::assertTrue( ( new BlueprintValidator() )->validate( $blueprint )->is_valid() );
	}

	public function test_compatible_legacy_definition_compiles_to_the_same_semantic_contract(): void {
		$definition = [
			'slug' => 'properties',
			'singular' => 'Property',
			'plural' => 'Properties',
			'description' => 'Structured catalog.',
			'menu_icon' => 'dashicons-building',
			'public' => true,
			'show_in_rest' => false,
			'has_archive' => true,
			'hierarchical' => true,
			'rewrite_slug' => 'homes',
			'supports' => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions', 'page-attributes' ],
			'taxonomies' => [ [ 'slug' => 'region', 'singular' => 'Region', 'plural' => 'Regions', 'hierarchical' => true, 'public' => true, 'show_in_rest' => false ] ],
			'meta_fields' => [ [ 'key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'default' => 'house', 'options' => "house | House\nflat | Flat", 'required' => true, 'show_in_rest' => false ] ],
		];
		$blueprint = ( new LegacyImporter() )->import_entity( 'cpt', 'properties', $definition );
		$compiled = ( new Compiler() )->compile( $blueprint );
		self::assertTrue( $compiled->is_valid(), wp_json_encode( $compiled->errors() ) );

		$semantics = new LegacySemanticContract();
		$candidate = ( new CandidateArtifactProbe( $semantics ) )->probe( 'cpt', 'properties', $compiled->artifacts(), $blueprint );
		self::assertSame( $semantics->checksum( $semantics->from_definition( 'cpt', $definition ) ), $candidate['contract_checksum'] );
	}

	public function test_archived_cct_defaults_and_options_survive_candidate_compilation(): void {
		$definition = [
			'slug' => 'projects',
			'singular' => 'Project',
			'plural' => 'Projects',
			'description' => 'Portfolio records.',
			'menu_icon' => 'dashicons-portfolio',
			'public' => true,
			'state' => 'archived',
			'fields' => [
				[ 'key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'default' => 'plugin', 'options' => "plugin | Plugin\nsite | Site", 'required' => true, 'filterable' => true, 'active' => true ],
			],
		];
		$blueprint = ( new LegacyImporter() )->import_entity( 'cct', 'projects', $definition );
		$compiled = ( new Compiler() )->compile( $blueprint );
		self::assertTrue( $compiled->is_valid(), wp_json_encode( $compiled->errors() ) );

		$semantics = new LegacySemanticContract();
		$candidate = ( new CandidateArtifactProbe( $semantics ) )->probe( 'cct', 'projects', $compiled->artifacts(), $blueprint );
		self::assertSame( $semantics->checksum( $semantics->from_definition( 'cct', $definition ) ), $candidate['contract_checksum'] );
		self::assertSame( 'archived', $candidate['contract']['state'] );
	}

	public function test_active_runtime_cannot_mask_raw_legacy_option_drift(): void {
		$baseline = [
			'slug' => 'properties', 'singular' => 'Property', 'plural' => 'Properties', 'description' => 'Candidate contract',
			'menu_icon' => 'dashicons-building', 'public' => true, 'show_in_rest' => true, 'has_archive' => true, 'hierarchical' => false,
			'rewrite_slug' => 'properties', 'supports' => [ 'title' ], 'taxonomies' => [], 'meta_fields' => [],
		];
		$blueprint = ( new LegacyImporter() )->import_entity( 'cpt', 'properties', $baseline );
		$compiled = ( new Compiler() )->compile( $blueprint );
		self::assertTrue( $compiled->is_valid(), wp_json_encode( $compiled->errors() ) );
		$artifact = current( array_filter( $compiled->artifacts(), fn( $item ) => 'entity_definition' === ( $item['kind'] ?? '' ) ) );
		$candidate = ( new CandidateArtifactProbe() )->probe( 'cpt', 'properties', $compiled->artifacts(), $blueprint );

		$drifted = array_replace( $baseline, [ 'description' => 'Raw option drift' ] );
		$legacy = ( new LegacyAuthorityProbe( fn() => [ 'properties' => $drifted ] ) )->probe( 'cpt', 'properties' );
		$runtime = ( new ActiveRuntimeProbe( fn() => [ 'properties' => $artifact['payload']['definition'] ] ) )->probe( 'cpt', 'properties' );

		self::assertSame( $candidate['contract_checksum'], $runtime['contract_checksum'], 'The fixture must reproduce a compiled runtime masking the option.' );
		self::assertNotSame( $legacy['contract_checksum'], $candidate['contract_checksum'], 'Raw option drift must remain visible to the independent legacy probe.' );
	}

	public function test_cct_shadow_records_do_not_reenter_the_combined_definition_manager(): void {
		$root = dirname( __DIR__, 2 );
		$comparator = file_get_contents( $root . '/includes/Blueprint/ShadowComparator.php' );
		$records = file_get_contents( $root . '/includes/Blueprint/CctShadowRecordProbe.php' );

		self::assertStringContainsString( 'CctShadowRecordProbe', $comparator );
		self::assertStringNotContainsString( 'CctRepository', $comparator );
		self::assertStringNotContainsString( 'DefinitionManager', $records );
		self::assertStringContainsString( 'SHOW COLUMNS', $records );
	}

	public function test_elementor_shadow_renderer_uses_only_public_core_managers(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Elementor/ElementorDocumentRenderer.php' );
		self::assertStringContainsString( "->documents", $source );
		self::assertStringContainsString( 'get_builder_content_for_display', $source );
		self::assertStringNotContainsString( 'ElementorPro', $source );
		self::assertStringNotContainsString( '\\Elementor\\Core\\Base\\', $source );
	}

	public function test_release_metadata_and_uninstall_are_consistent_and_conservative(): void {
		$root = dirname( __DIR__, 2 );
		$main = file_get_contents( $root . '/elementor-implementation-toolkit.php' );
		$package = json_decode( file_get_contents( $root . '/package.json' ), true );
		$public_readme = file_get_contents( $root . '/readme.txt' );
		$uninstall = file_get_contents( $root . '/uninstall.php' );

		self::assertStringContainsString( "define( 'EIT_VERSION', '1.0.0-rc.1' );", $main );
		self::assertSame( '1.0.0-rc.1', $package['version'] );
		self::assertStringContainsString( 'Stable tag: 1.0.0-rc.1', $public_readme );
		$gate = strpos( $uninstall, "defined( 'EIT_UNINSTALL_REMOVE_DATA' )" );
		$first_drop = strpos( $uninstall, 'DROP TABLE' );
		self::assertIsInt( $gate );
		self::assertIsInt( $first_drop );
		self::assertLessThan( $first_drop, $gate );
		self::assertStringContainsString( 'return;', substr( $uninstall, $gate, $first_drop - $gate ) );
		self::assertStringContainsString( "'pending_uploads'", $uninstall );
		self::assertStringContainsString( "'storage_claims'", $uninstall );
		self::assertStringContainsString( "'eit_storage_claims'", $uninstall );
		self::assertStringContainsString( "'eit_artifacts'", $uninstall );
		self::assertStringContainsString( "WHERE kind = %s", $uninstall );
		self::assertStringContainsString( "'entity_definition'", $uninstall );
		self::assertLessThan( $first_drop, strpos( $uninstall, '$claims_table' ) );
		self::assertLessThan( $first_drop, strpos( $uninstall, '$artifacts_table' ) );
		self::assertStringContainsString( "wp_clear_scheduled_hook( 'eit_sweep_entry_pending_uploads' )", $uninstall );
		self::assertStringContainsString( 'eit-private-upload-storage-v1', $uninstall );
	}
}
