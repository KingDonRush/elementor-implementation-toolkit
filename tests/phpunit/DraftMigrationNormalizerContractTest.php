<?php
/**
 * Backend proof for opaque, update-safe migration target allocation.
 */

use EIT\Blueprint\DraftMigrationNormalizer;
use PHPUnit\Framework\TestCase;

class DraftMigrationNormalizerContractTest extends TestCase {

	public function test_published_type_change_gets_stable_new_storage_and_source_alias(): void {
		$active = $this->document( 'decimal', 'scalar', 'budget' );
		$draft = $this->document( 'integer', 'scalar', 'budget' );
		$normalizer = new DraftMigrationNormalizer();

		$first = $normalizer->normalize( $draft, $active );
		$second = $normalizer->normalize( $first, $active );
		$field = $first['nodes'][0]['config']['fields'][0];

		self::assertNotSame( 'budget', $field['storage']['key'] );
		self::assertSame( [ 'budget' ], $field['storage']['aliases'] );
		self::assertTrue( $field['storage']['migration']['generated'] );
		self::assertSame( $first, $second );
	}

	public function test_reverting_draft_semantics_restores_published_storage(): void {
		$active = $this->document( 'decimal', 'scalar', 'budget' );
		$changed = ( new DraftMigrationNormalizer() )->normalize( $this->document( 'integer', 'scalar', 'budget' ), $active );
		$changed['nodes'][0]['config']['fields'][0]['type'] = 'decimal';

		$reverted = ( new DraftMigrationNormalizer() )->normalize( $changed, $active );
		$field = $reverted['nodes'][0]['config']['fields'][0];

		self::assertSame( 'budget', $field['storage']['key'] );
		self::assertSame( [], $field['storage']['aliases'] );
		self::assertArrayNotHasKey( 'migration', $field['storage'] );
	}

	public function test_normalized_fields_do_not_receive_fake_physical_targets(): void {
		$active = $this->document( 'short_text', 'scalar', 'notes' );
		$draft = $this->document( 'repeatable_group', 'list', 'notes' );

		$result = ( new DraftMigrationNormalizer() )->normalize( $draft, $active );

		self::assertSame( 'notes', $result['nodes'][0]['config']['fields'][0]['storage']['key'] );
	}

	private function document( string $type, string $shape, string $key ): array {
		return [
			'nodes' => [
				[
					'type' => 'field_group',
					'config' => [
						'fields' => [
							[
								'id' => '22222222-2222-4222-8222-222222222222',
								'type' => $type,
								'shape' => $shape,
								'storage' => [ 'key' => $key, 'aliases' => [] ],
							],
						],
					],
				],
			],
		];
	}
}
