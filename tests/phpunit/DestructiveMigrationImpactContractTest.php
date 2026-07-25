<?php
/**
 * Impact-plan proof that only executable field migrations unlock publication.
 */

use EIT\Blueprint\ImpactPlanner;
use PHPUnit\Framework\TestCase;

class DestructiveMigrationImpactContractTest extends TestCase {

	public function test_safe_numeric_migration_replaces_generic_lock_with_operation(): void {
		$impact = ( new ImpactPlanner() )->plan(
			[ $this->artifact( 'decimal', 'before' ) ],
			[ $this->binding( 'budget' ) ],
			[ $this->artifact( 'integer', 'after' ) ],
			[ $this->binding( 'budget_integer', [ 'budget' ] ) ]
		);

		self::assertFalse( $impact['blocked'] );
		self::assertSame( [], $impact['blockers'] );
		self::assertCount( 1, $impact['migration_plan']['operations'] );
		self::assertSame( 1, $impact['summary']['migration_operations'] );
	}

	public function test_unsupported_transform_remains_blocked_with_specific_reason(): void {
		$impact = ( new ImpactPlanner() )->plan(
			[ $this->artifact( 'decimal', 'before' ) ],
			[ $this->binding( 'budget' ) ],
			[ $this->artifact( 'short_text', 'after' ) ],
			[ $this->binding( 'budget_text', [ 'budget' ] ) ]
		);

		self::assertTrue( $impact['blocked'] );
		self::assertSame( 'eit_migration_transform_unsupported', $impact['blockers'][0]['code'] );
		self::assertSame( [], $impact['migration_plan']['operations'] );
	}

	private function artifact( string $type, string $checksum ): array {
		return [
			'kind' => 'entity_definition',
			'node_id' => '11111111-1111-4111-8111-111111111111',
			'checksum' => hash( 'sha256', $checksum ),
			'payload' => [
				'entity_id' => '11111111-1111-4111-8111-111111111111',
				'strategy' => 'cct',
				'adapter' => [ 'id' => 'cct' ],
				'definition' => [ 'slug' => 'projects' ],
				'fields' => [ [ 'id' => '22222222-2222-4222-8222-222222222222', 'type' => $type, 'shape' => 'scalar' ] ],
			],
		];
	}

	private function binding( string $key, array $aliases = [] ): array {
		return [
			'field_id' => '22222222-2222-4222-8222-222222222222',
			'entity_id' => '11111111-1111-4111-8111-111111111111',
			'adapter' => 'cct',
			'storage_key' => $key,
			'aliases' => $aliases,
		];
	}
}
