<?php
/**
 * Pure contracts for staged destructive field migration planning.
 */

use EIT\Blueprint\FieldMigrationPlanner;
use EIT\Blueprint\MigrationValueTransformer;
use PHPUnit\Framework\TestCase;

class DestructiveMigrationPlannerContractTest extends TestCase {

	public function test_storage_rename_compiles_to_identity_copy_when_alias_is_preserved(): void {
		$result = $this->planner()->plan(
			[ $this->artifact( 'cct', 'decimal', 'scalar' ) ],
			[ $this->binding( 'budget' ) ],
			[ $this->artifact( 'cct', 'decimal', 'scalar' ) ],
			[ $this->binding( 'budget_v2', [ 'budget' ] ) ]
		);

		self::assertSame( [], $result['blockers'] );
		self::assertCount( 1, $result['operations'] );
		self::assertSame( 'identity', $result['operations'][0]['transform'] );
		self::assertSame( 'budget', $result['operations'][0]['source']['key'] );
		self::assertSame( 'budget_v2', $result['operations'][0]['target']['key'] );
		self::assertSame( 'f_budget', $result['operations'][0]['source']['physical']['column'] );
		self::assertSame( 'decimal(20,6)', $result['operations'][0]['source']['physical']['type'] );
		self::assertTrue( $result['operations'][0]['target']['physical']['nullable'] );
		self::assertTrue( $result['operations'][0]['preserve_source'] );
	}

	public function test_numeric_type_change_requires_new_storage_and_compiles_strict_transform(): void {
		$result = $this->planner()->plan(
			[ $this->artifact( 'cpt', 'decimal', 'scalar' ) ],
			[ $this->binding( 'budget' ) ],
			[ $this->artifact( 'cpt', 'integer', 'scalar' ) ],
			[ $this->binding( 'budget_integer', [ 'budget' ] ) ]
		);

		self::assertSame( [], $result['blockers'] );
		self::assertSame( 'strict_integer', $result['operations'][0]['transform'] );
		self::assertSame( 64, strlen( $result['operations'][0]['id'] ) );
	}

	public function test_cct_plan_carries_the_not_null_boolean_target_signature(): void {
		$result = $this->planner()->plan(
			[ $this->artifact( 'cct', 'decimal', 'scalar' ) ],
			[ $this->binding( 'budget' ) ],
			[ $this->artifact( 'cct', 'boolean', 'scalar' ) ],
			[ $this->binding( 'budget_boolean', [ 'budget' ] ) ]
		);

		self::assertSame(
			[ 'column' => 'f_budget_boolean', 'type' => 'tinyint', 'nullable' => false, 'default' => '0', 'extra' => '' ],
			$result['operations'][0]['target']['physical']
		);
	}

	public function test_cct_logical_keys_that_truncate_to_one_physical_column_are_blocked_by_the_planner(): void {
		$source = str_repeat( 'a', 49 );
		$target = str_repeat( 'a', 48 ) . 'b';
		$result = $this->planner()->plan(
			[ $this->artifact( 'cct', 'decimal', 'scalar' ) ],
			[ $this->binding( $source ) ],
			[ $this->artifact( 'cct', 'integer', 'scalar' ) ],
			[ $this->binding( $target, [ $source ] ) ]
		);

		self::assertSame( [], $result['operations'] );
		self::assertSame( 'eit_migration_identifier_collision', $result['blockers'][0]['code'] );
	}

	/**
	 * @dataProvider blocked_changes
	 */
	public function test_unsafe_or_ambiguous_changes_remain_blocked( array $next_artifact, array $next_binding, string $code ): void {
		$result = $this->planner()->plan(
			[ $this->artifact( 'cct', 'decimal', 'scalar' ) ],
			[ $this->binding( 'budget' ) ],
			[ $next_artifact ],
			[ $next_binding ]
		);

		self::assertSame( [], $result['operations'] );
		self::assertSame( $code, $result['blockers'][0]['code'] );
	}

	public static function blocked_changes(): array {
		$test = new self( 'test_unsafe_or_ambiguous_changes_remain_blocked' );
		return [
			'same physical storage for changed semantics' => [ $test->artifact( 'cct', 'integer', 'scalar' ), $test->binding( 'budget' ), 'eit_migration_new_storage_required' ],
			'missing source alias' => [ $test->artifact( 'cct', 'decimal', 'scalar' ), $test->binding( 'budget_v2' ), 'eit_migration_source_alias_required' ],
			'unsupported semantic transform' => [ $test->artifact( 'cct', 'short_text', 'scalar' ), $test->binding( 'budget_text', [ 'budget' ] ), 'eit_migration_transform_unsupported' ],
			'external adapter without migration contract' => [ $test->artifact( 'cct', 'decimal', 'scalar', 'sdk_records' ), $test->binding( 'budget_v2', [ 'budget' ] ), 'eit_migration_adapter_unsupported' ],
		];
	}

	public function test_transformer_rejects_lossy_values_and_canonicalizes_equivalent_decimals(): void {
		$transformer = new MigrationValueTransformer();

		self::assertSame( 12, $transformer->transform( '12.000', 'strict_integer' ) );
		self::assertSame( '12.5', $transformer->transform( '0012.5000', 'strict_decimal' ) );
		self::assertSame( '12.5', $transformer->canonical( '12.500000', [ 'type' => 'decimal' ] ) );
		self::assertSame( 'eit_migration_value_invalid', $transformer->transform( '12.4', 'strict_integer' )->get_error_code() );
		self::assertSame( 'eit_migration_value_invalid', $transformer->transform( '2', 'strict_boolean' )->get_error_code() );
		self::assertSame( 'eit_migration_value_invalid', $transformer->transform( '123456789012345.1', 'strict_decimal' )->get_error_code() );
		self::assertSame( 'eit_migration_value_invalid', $transformer->transform( '1.1234567', 'strict_decimal' )->get_error_code() );
	}

	private function planner(): FieldMigrationPlanner {
		return new FieldMigrationPlanner();
	}

	private function artifact( string $strategy, string $type, string $shape, string $adapter = '' ): array {
		return [
			'kind' => 'entity_definition',
			'node_id' => 'entity-one',
			'payload' => [
				'entity_id' => 'entity-one',
				'strategy' => $strategy,
				'adapter' => [ 'id' => $adapter ?: $strategy ],
				'definition' => [ 'slug' => 'projects' ],
				'fields' => [ [ 'id' => 'field-budget', 'type' => $type, 'shape' => $shape ] ],
			],
		];
	}

	private function binding( string $key, array $aliases = [] ): array {
		return [ 'field_id' => 'field-budget', 'storage_key' => $key, 'aliases' => $aliases ];
	}
}
