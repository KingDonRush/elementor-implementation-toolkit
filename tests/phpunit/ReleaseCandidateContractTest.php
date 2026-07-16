<?php
/**
 * Pure release-candidate contracts for migration evidence and diagnostics.
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\ImpactMapBuilder;
use EIT\Blueprint\LegacyImporter;
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
	}
}
