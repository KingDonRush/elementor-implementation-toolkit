<?php
/**
 * Pure contracts for source-preserving CPT and CCT migration drivers.
 */

use EIT\Blueprint\CctFieldMigrationDriver;
use EIT\Blueprint\CctPhysicalColumnContract;
use EIT\Blueprint\CptFieldMigrationDriver;
use PHPUnit\Framework\TestCase;

class StorageMigrationDriverContractTest extends TestCase {

	public function test_cpt_requires_a_new_target_and_recovery_proves_existing_values(): void {
		$operation = $this->operation( 'cpt' );
		$driver = new InMemoryCptMigrationDriver(
			[ 1 => [ 'budget' => [ '12.0' ], 'budget_v2' => [ '12' ] ] ]
		);

		self::assertErrorCode( 'eit_migration_target_exists', $driver->assert_target_available( $operation, false ) );
		self::assertTrue( $driver->assert_target_available( $operation, true ) );
		$driver->set_meta( 1, 'budget_v2', [ '13' ] );
		self::assertErrorCode( 'eit_migration_target_diverged', $driver->assert_target_available( $operation, true ) );
	}

	public function test_cpt_blocks_duplicate_meta_values(): void {
		$driver = new InMemoryCptMigrationDriver( [ 7 => [ 'budget' => [ '4', '4' ] ] ] );
		$error = $driver->assert_target_available( $this->operation( 'cpt' ), false );

		self::assertErrorCode( 'eit_migration_meta_duplicate', $error );
		self::assertSame( 7, $error->get_error_data()['record_id'] );
	}

	public function test_cpt_copy_is_bounded_idempotent_and_source_preserving(): void {
		$operation = $this->operation( 'cpt' );
		$driver = new InMemoryCptMigrationDriver(
			[
				1 => [ 'budget' => [ '12.0' ], 'budget_v2' => [ '12' ] ],
				2 => [ 'budget' => [ '0' ] ],
				3 => [ 'budget' => [ '8.000' ] ],
			]
		);
		$source_before = $driver->values_for( 'budget' );

		$first = $driver->copy_batch( $operation, 0, 2 );
		self::assertSame( [ 'cursor' => 2, 'processed' => 2, 'copied' => 1, 'complete' => false ], $first );
		$second = $driver->copy_batch( $operation, 2, 2 );
		self::assertSame( [ 'cursor' => 3, 'processed' => 1, 'copied' => 1, 'complete' => true ], $second );
		self::assertSame( $source_before, $driver->values_for( 'budget' ) );
		self::assertSame( [ 1, 2, 3 ], $driver->invalidated_ids() );

		$transformed = $driver->fingerprint( $operation, 'transformed' );
		$target = $driver->fingerprint( $operation, 'target' );
		self::assertSame( 3, $transformed['record_count'] );
		self::assertSame( 3, $transformed['value_count'] );
		self::assertSame( 0, $transformed['rejected_count'] );
		self::assertSame( $transformed['checksum'], $target['checksum'] );
	}

	public function test_cct_preparation_distinguishes_first_run_from_ledger_recovery(): void {
		$operation = $this->operation( 'cct', 'decimal', 'integer', 'strict_integer' );
		$driver = new InMemoryCctMigrationDriver( [ 1 => [ 'f_budget' => '9' ] ] );

		self::assertTrue( $driver->assert_target_available( $operation, false ) );
		self::assertErrorCode( 'eit_migration_target_not_ready', $driver->assert_target_ready( $operation ) );
		$driver->prepare_target( $operation, null );
		self::assertErrorCode( 'eit_migration_target_exists', $driver->assert_target_available( $operation, false ) );
		self::assertTrue( $driver->assert_target_available( $operation, true ) );
		self::assertTrue( $driver->assert_target_ready( $operation ) );

		$driver->remove_column( 'f_budget' );
		self::assertErrorCode( 'eit_migration_source_missing', $driver->assert_target_ready( $operation ) );
	}

	public function test_cct_copy_validates_the_durable_prefix_and_preserves_source(): void {
		$operation = $this->operation( 'cct', 'decimal', 'boolean', 'strict_boolean' );
		$driver = new InMemoryCctMigrationDriver(
			[
				1 => [ 'f_budget' => '1' ],
				2 => [ 'f_budget' => '0' ],
			]
		);
		$driver->prepare_target( $operation, 0 );
		$source_before = $driver->column_values( 'f_budget' );

		$first = $driver->copy_batch( $operation, 0, 1 );
		self::assertSame( [ 'cursor' => 1, 'processed' => 1, 'copied' => 1, 'complete' => false ], $first );
		$driver->set_value( 1, 'f_budget_v2', 0 );
		self::assertErrorCode( 'eit_migration_target_diverged', $driver->copy_batch( $operation, 1, 2 ) );

		$driver->set_value( 1, 'f_budget_v2', 1 );
		$second = $driver->copy_batch( $operation, 1, 2 );
		self::assertSame( [ 'cursor' => 2, 'processed' => 1, 'copied' => 0, 'complete' => true ], $second );
		self::assertSame( $source_before, $driver->column_values( 'f_budget' ) );
		self::assertSame(
			$driver->fingerprint( $operation, 'transformed' )['checksum'],
			$driver->fingerprint( $operation, 'target' )['checksum']
		);
	}

	public function test_cct_external_non_default_write_blocks_until_rehearsal_can_freeze_writers(): void {
		$operation = $this->operation( 'cct', 'decimal', 'boolean', 'strict_boolean' );
		$driver = new InMemoryCctMigrationDriver( [ 1 => [ 'f_budget' => '0' ] ] );
		$driver->prepare_target( $operation, 0 );
		$driver->set_value( 1, 'f_budget_v2', 1 );

		self::assertErrorCode( 'eit_migration_target_diverged', $driver->copy_batch( $operation, 0, 10 ) );
	}

	public function test_cct_fingerprint_counts_rejections_and_copy_refuses_lossy_data(): void {
		$operation = $this->operation( 'cct', 'decimal', 'boolean', 'strict_boolean' );
		$driver = new InMemoryCctMigrationDriver( [ 1 => [ 'f_budget' => '2' ] ] );
		$driver->prepare_target( $operation, 0 );

		$fingerprint = $driver->fingerprint( $operation, 'transformed' );
		self::assertSame( 1, $fingerprint['record_count'] );
		self::assertSame( 1, $fingerprint['value_count'] );
		self::assertSame( 1, $fingerprint['rejected_count'] );
		self::assertErrorCode( 'eit_migration_value_invalid', $driver->copy_batch( $operation, 0, 10 ) );
	}

	public function test_cct_preflight_rejects_null_source_values_for_a_not_null_target(): void {
		$operation = $this->operation( 'cct', 'decimal', 'boolean', 'strict_boolean' );
		$driver = new InMemoryCctMigrationDriver( [ 1 => [ 'f_budget' => null ] ] );

		self::assertErrorCode( 'eit_migration_source_null_incompatible', $driver->assert_target_available( $operation, false ) );

		$driver = new InMemoryCctMigrationDriver( [ 1 => [ 'f_budget' => '1' ] ] );
		$driver->prepare_target( $operation, 0 );
		$driver->set_value( 1, 'f_budget', null );
		self::assertErrorCode( 'eit_migration_source_null_incompatible', $driver->copy_batch( $operation, 0, 10 ) );
	}

	public function test_cct_validates_source_and_prepared_target_physical_signatures(): void {
		$operation = $this->operation( 'cct' );
		$driver = new InMemoryCctMigrationDriver( [ 1 => [ 'f_budget' => '12' ] ] );
		$driver->set_column_type( 'f_budget', 'varchar(255)' );
		self::assertErrorCode( 'eit_migration_column_signature_mismatch', $driver->assert_target_available( $operation, false ) );

		$driver = new InMemoryCctMigrationDriver( [ 1 => [ 'f_budget' => '12' ] ] );
		$driver->prepare_target( $operation, null );
		$driver->set_column_type( 'f_budget_v2', 'tinyint' );
		self::assertErrorCode( 'eit_migration_column_signature_mismatch', $driver->assert_target_ready( $operation ) );
	}

	public function test_cct_rejects_a_tampered_or_missing_planned_physical_signature(): void {
		$operation = $this->operation( 'cct' );
		unset( $operation['target']['physical'] );
		self::assertFalse( ( new InMemoryCctMigrationDriver( [] ) )->supports( $operation ) );

		$operation = $this->operation( 'cct' );
		$operation['target']['physical']['nullable'] = false;
		self::assertFalse( ( new InMemoryCctMigrationDriver( [] ) )->supports( $operation ) );
	}

	public function test_drivers_reject_untrusted_and_colliding_physical_identifiers(): void {
		$cpt = $this->operation( 'cpt' );
		$cpt['source']['key'] = 'budget`';
		self::assertSame( false, ( new InMemoryCptMigrationDriver( [] ) )->supports( $cpt ) );

		$cct = $this->operation( 'cct' );
		$cct['source'] = $this->cct_side( str_repeat( 'a', 49 ), 'decimal' );
		$cct['target'] = $this->cct_side( str_repeat( 'a', 48 ) . 'b', 'integer' );
		self::assertSame( false, ( new InMemoryCctMigrationDriver( [] ) )->supports( $cct ) );
	}

	public function test_fingerprint_aborts_between_pages_when_lock_ownership_is_lost(): void {
		$cpt_records = [];
		$cct_records = [];
		for ( $id = 1; $id <= 501; ++$id ) {
			$cpt_records[ $id ] = [ 'budget' => [ (string) $id ] ];
			$cct_records[ $id ] = [ 'f_budget' => (string) $id ];
		}
		$cases = [
			[ new InMemoryCptMigrationDriver( $cpt_records ), $this->operation( 'cpt' ) ],
			[ new InMemoryCctMigrationDriver( $cct_records ), $this->operation( 'cct' ) ],
		];
		foreach ( $cases as [ $driver, $operation ] ) {
			$pulses = 0;
			$driver->set_heartbeat( static function () use ( &$pulses ) {
				return 2 === ++$pulses ? new WP_Error( 'eit_migration_storage_lease_lost', 'lost' ) : true;
			} );
			self::assertErrorCode( 'eit_migration_storage_lease_lost', $driver->fingerprint( $operation, 'source' ) );
			self::assertSame( 2, $pulses );
		}
	}

	private function operation( string $adapter, string $source_type = 'decimal', string $target_type = 'integer', string $transform = 'strict_integer' ): array {
		$operation = [
			'id' => 'operation-' . $adapter,
			'adapter' => $adapter,
			'strategy' => $adapter,
			'storage_slug' => 'projects',
			'source' => [ 'key' => 'budget', 'type' => $source_type, 'shape' => 'scalar' ],
			'target' => [ 'key' => 'budget_v2', 'type' => $target_type, 'shape' => 'scalar' ],
			'transform' => $transform,
			'preserve_source' => true,
		];
		if ( 'cct' === $adapter ) {
			$operation['source'] = $this->cct_side( 'budget', $source_type );
			$operation['target'] = $this->cct_side( 'budget_v2', $target_type );
		}
		return $operation;
	}

	private function cct_side( string $key, string $type ): array {
		$semantics = [ 'type' => $type, 'shape' => 'scalar' ];
		return array_merge( [ 'key' => $key ], $semantics, [ 'physical' => CctPhysicalColumnContract::expected( $key, $semantics ) ] );
	}

	private static function assertErrorCode( string $code, $actual ): void {
		self::assertInstanceOf( WP_Error::class, $actual );
		self::assertSame( $code, $actual->get_error_code() );
	}
}

class InMemoryCptMigrationDriver extends CptFieldMigrationDriver {

	private $records;
	private $invalidated = [];

	public function __construct( array $records ) {
		parent::__construct();
		$this->records = $records;
		ksort( $this->records, SORT_NUMERIC );
	}

	public function set_meta( int $id, string $key, array $values ): void {
		$this->records[ $id ][ $key ] = $values;
	}

	public function values_for( string $key ): array {
		$result = [];
		foreach ( $this->records as $id => $record ) {
			$result[ $id ] = $record[ $key ] ?? [];
		}
		return $result;
	}

	public function invalidated_ids(): array {
		return $this->invalidated;
	}

	protected function entity_ids( $post_type, $cursor, $limit ) {
		return array_slice( array_values( array_filter( array_keys( $this->records ), fn( $id ) => $id > $cursor ) ), 0, $limit );
	}

	protected function meta_values( array $ids, $key ) {
		$result = [];
		foreach ( $ids as $id ) {
			if ( ! empty( $this->records[ $id ][ $key ] ) ) {
				$result[ $id ] = $this->records[ $id ][ $key ];
			}
		}
		return $result;
	}

	protected function invalidate_meta_cache( $post_id ) {
		$this->invalidated[] = (int) $post_id;
	}

	protected function insert_target_value( $post_id, $key, $value ) {
		if ( ! empty( $this->records[ $post_id ][ $key ] ) ) {
			return 0;
		}
		$this->records[ $post_id ][ $key ] = [ $value ];
		return 1;
	}
}

class InMemoryCctMigrationDriver extends CctFieldMigrationDriver {

	private $columns;
	private $records;

	public function __construct( array $records ) {
		parent::__construct();
		$signature = CctPhysicalColumnContract::expected( 'budget', [ 'type' => 'decimal', 'shape' => 'scalar' ] );
		$this->columns = [ 'f_budget' => $this->column( $signature ) ];
		$this->records = $records;
		ksort( $this->records, SORT_NUMERIC );
	}

	public function prepare_target( array $operation, $default ): void {
		$signature = $operation['target']['physical'];
		$this->columns[ $signature['column'] ] = $this->column( $signature );
		foreach ( $this->records as &$record ) {
			$record[ $signature['column'] ] = $default;
		}
		unset( $record );
	}

	public function remove_column( string $column ): void {
		unset( $this->columns[ $column ] );
	}

	public function set_column_type( string $column, string $type ): void {
		$this->columns[ $column ]['Type'] = $type;
	}

	public function set_value( int $id, string $column, $value ): void {
		$this->records[ $id ][ $column ] = $value;
	}

	public function column_values( string $column ): array {
		return array_map( fn( $record ) => $record[ $column ] ?? null, $this->records );
	}

	protected function storage_exists( $slug ) {
		return true;
	}

	protected function table_name( $slug ) {
		return 'memory_projects';
	}

	protected function storage_columns( $table ) {
		return $this->columns;
	}

	protected function source_null_count( $table, $column ) {
		return count( array_filter( $this->records, fn( $record ) => ! array_key_exists( $column, $record ) || null === $record[ $column ] ) );
	}

	protected function entity_ids( $table, $cursor, $limit, $maximum = 0 ) {
		$ids = array_filter(
			array_keys( $this->records ),
			fn( $id ) => $id > $cursor && ( 0 === $maximum || $id <= $maximum )
		);
		return array_slice( array_values( $ids ), 0, $limit );
	}

	protected function row_values( $table, array $ids, array $columns ) {
		$result = [];
		foreach ( $ids as $id ) {
			if ( ! isset( $this->records[ $id ] ) ) {
				continue;
			}
			$result[ $id ] = [ 'id' => $id ];
			foreach ( $columns as $column ) {
				$result[ $id ][ $column ] = $this->records[ $id ][ $column ] ?? null;
			}
		}
		return $result;
	}

	protected function write_target_value( $table, $column, $id, $expected, $value ) {
		$current = $this->records[ $id ][ $column ] ?? null;
		if ( $current !== $expected ) {
			return 0;
		}
		$this->records[ $id ][ $column ] = $value;
		return 1;
	}

	private function column( array $signature ): array {
		return [
			'Field' => $signature['column'],
			'Type' => $signature['type'],
			'Null' => $signature['nullable'] ? 'YES' : 'NO',
			'Default' => $signature['default'],
			'Extra' => $signature['extra'],
		];
	}
}
