<?php
/**
 * Contracts for the Blueprint-managed WordPress writer adapter.
 */

use EIT\Blueprint\StorageMutationGuard;
use EIT\Blueprint\WordPressMutationGuard;
use PHPUnit\Framework\TestCase;

class WordPressMutationGuardContractTest extends TestCase {

	public function test_global_wordpress_hooks_boot_after_shared_writer_protocol_is_available(): void {
		$module = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Blueprint/BlueprintModule.php' );

		self::assertStringContainsString( '( new WordPressMutationGuard() )->init_hooks()', $module );
	}

	public function test_unmanaged_post_types_never_enter_a_storage_lock(): void {
		$storage = new WordPressMutationRecordingGuard();
		$guard = new WordPressMutationGuardFixture(
			$storage,
			[ 'property' => [ 'slug' => 'property' ] ],
			[ 1 => 'post', 2 => 'page', 3 => 'attachment', 4 => 'revision', 5 => 'book' ]
		);

		foreach ( [ 'post', 'page', 'attachment', 'revision', 'book' ] as $post_type ) {
			self::assertFalse( $guard->guard_post_write( false, [ 'post_type' => $post_type ] ) );
			self::assertNull( $guard->guard_post_delete( null, (object) [ 'post_type' => $post_type ] ) );
			self::assertNull( $guard->guard_post_status_change( null, (object) [ 'post_type' => $post_type ] ) );
		}
		foreach ( [ 1, 2, 3, 4, 5 ] as $post_id ) {
			self::assertNull( $guard->guard_meta_write( null, $post_id ) );
		}

		self::assertSame( [], $storage->entries );
	}

	public function test_managed_cpt_uses_the_storage_contract_when_adapter_is_exercised(): void {
		$storage = new WordPressMutationRecordingGuard( new WP_Error( 'eit_migration_write_fenced', 'fenced' ) );
		$guard = new WordPressMutationGuardFixture(
			$storage,
			[ 'property' => [ 'slug' => 'property' ] ],
			[ 9 => 'property' ],
			[ 17 => 'property' ]
		);

		self::assertTrue( $guard->guard_post_write( false, [ 'post_type' => 'property' ] ) );
		self::assertFalse( $guard->guard_meta_write( null, 9 ) );
		self::assertFalse( $guard->guard_meta_write_by_mid( null, 17 ) );
		self::assertFalse( $guard->guard_post_delete( null, (object) [ 'post_type' => 'property' ] ) );

		self::assertSame(
			[
				[ 'cpt', 'property' ],
				[ 'cpt', 'property' ],
				[ 'cpt', 'property' ],
				[ 'cpt', 'property' ],
			],
			$storage->entries
		);
	}

	public function test_delete_all_locks_only_active_blueprint_cpt_scopes(): void {
		$storage = new WordPressMutationRecordingGuard();
		$guard = new WordPressMutationGuardFixture(
			$storage,
			[ 'property' => [], 'clinic' => [] ]
		);

		self::assertNull( $guard->guard_meta_delete( null, 0, 'shared_key', null, true ) );
		self::assertSame( [ [ 'cpt', 'property' ], [ 'cpt', 'clinic' ] ], $storage->entries );
	}

	public function test_by_mid_and_delete_all_fail_closed_only_for_managed_scope(): void {
		$storage = new WordPressMutationRecordingGuard( new WP_Error( 'eit_storage_write_locked', 'locked' ) );
		$guard = new WordPressMutationGuardFixture( $storage, [], [], [ 17 => 'property' ] );

		self::assertNull( $guard->guard_meta_write_by_mid( null, 17 ) );
		self::assertNull( $guard->guard_meta_delete( null, 0, 'shared_key', null, true ) );
		self::assertSame( [], $storage->entries );

		$managed = new WordPressMutationGuardFixture( $storage, [ 'property' => [] ], [], [ 17 => 'property' ] );
		self::assertFalse( $managed->guard_meta_write_by_mid( null, 17 ) );
		self::assertFalse( $managed->guard_meta_delete( null, 0, 'shared_key', null, true ) );
	}

	public function test_runtime_authority_failure_enters_the_durable_fence_instead_of_assuming_unmanaged(): void {
		$storage = new WordPressMutationRecordingGuard( new WP_Error( 'eit_migration_write_fenced', 'fenced' ) );
		$guard = new WordPressMutationGuardFixture( $storage, new WP_Error( 'eit_blueprint_runtime_read_failed', 'unavailable' ), [ 9 => 'property' ] );

		self::assertTrue( $guard->guard_post_write( false, [ 'post_type' => 'property' ] ) );
		self::assertFalse( $guard->guard_meta_write( null, 9 ) );
		self::assertFalse( $guard->guard_meta_delete( null, 0, 'shared_key', null, true ) );
		self::assertSame( [ [ 'cpt', 'property' ], [ 'cpt', 'property' ] ], $storage->entries );
	}

	public function test_optional_hooks_run_at_the_final_filter_priority(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Blueprint/WordPressMutationGuard.php' );

		self::assertStringContainsString( "'update_post_metadata_by_mid'", $source );
		self::assertStringContainsString( "'delete_post_metadata_by_mid'", $source );
		self::assertStringNotContainsString( '[ $this, \'guard_post_write\' ], 5', $source );
		self::assertGreaterThanOrEqual( 9, substr_count( $source, 'PHP_INT_MAX' ) );
	}
}

class WordPressMutationRecordingGuard extends StorageMutationGuard {

	public $entries = [];
	private $result;

	public function __construct( $result = true ) {
		$this->result = $result;
	}

	public function enter( $strategy, $storage_slug ) {
		$this->entries[] = [ $strategy, $storage_slug ];
		return $this->result;
	}

	public function release_all() {
		return true;
	}
}

class WordPressMutationGuardFixture extends WordPressMutationGuard {

	private $post_types;
	private $meta_post_types;

	public function __construct( StorageMutationGuard $guard, $definitions, array $post_types = [], array $meta_post_types = [] ) {
		parent::__construct( $guard, static function () use ( $definitions ) {
			return $definitions;
		} );
		$this->post_types = $post_types;
		$this->meta_post_types = $meta_post_types;
	}

	protected function post_type_for_object( $object_id ) {
		return $this->post_types[ absint( $object_id ) ] ?? '';
	}

	protected function post_type_for_meta_id( $meta_id ) {
		return $this->meta_post_types[ absint( $meta_id ) ] ?? '';
	}
}
