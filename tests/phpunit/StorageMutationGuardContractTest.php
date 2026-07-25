<?php
/**
 * Contracts for shared storage writers and exclusive migration gates.
 */

use EIT\Blueprint\LifecycleLeaseGuard;
use EIT\Blueprint\MigrationStorageLockCoordinator;
use EIT\Blueprint\MigrationWriteFence;
use EIT\Blueprint\StorageMutationGuard;
use EIT\CCT\Repository as CctRepository;
use EIT\Entry\EntryStorageGateway;
use EIT\Infrastructure\Transaction;
use PHPUnit\Framework\TestCase;

class StorageMutationGuardContractTest extends TestCase {

	public function test_writer_lease_precedes_gate_and_fence_rechecks(): void {
		$events = [];
		$locks = new SharedWriterLeaseStore( $events );
		$fence = new MutationGuardFence( $events );
		$guard = new StorageMutationGuard( $fence, $locks, static fn() => 'request-a' );

		$first = $guard->enter( 'cct', 'projects' );
		$second = $guard->enter( 'cct', 'projects' );

		self::assertSame(
			[
				'acquire:eit-writer-cct-projects-request-a',
				'gate:eit-write-cct-projects',
				'fence:cct:projects',
				'renew:eit-writer-cct-projects-request-a',
				'gate:eit-write-cct-projects',
				'fence:cct:projects',
			],
			$events
		);
		self::assertTrue( $guard->leave( $second ) );
		self::assertSame( [], $locks->released );
		self::assertTrue( $guard->leave( $first ) );
		self::assertSame( [ 'eit-writer-cct-projects-request-a' ], $locks->released );
	}

	public function test_two_normal_requests_hold_distinct_writer_leases(): void {
		$events = [];
		$locks = new SharedWriterLeaseStore( $events );
		$first_guard = new StorageMutationGuard( new MutationGuardFence( $events ), $locks, static fn() => 'request-a' );
		$second_guard = new StorageMutationGuard( new MutationGuardFence( $events ), $locks, static fn() => 'request-b' );

		$first = $first_guard->enter( 'cpt', 'property' );
		$second = $second_guard->enter( 'cpt', 'property' );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertArrayHasKey( 'eit-writer-cpt-property-request-a', $locks->active );
		self::assertArrayHasKey( 'eit-writer-cpt-property-request-b', $locks->active );
		self::assertTrue( $first_guard->leave( $first ) );
		self::assertTrue( $second_guard->leave( $second ) );
	}

	public function test_gate_writer_races_fail_closed_in_both_interleavings(): void {
		$events = [];
		$locks = new SharedWriterLeaseStore( $events );
		$records = [ [ 'operation' => [ 'strategy' => 'cpt', 'storage_slug' => 'property' ] ] ];
		$coordinator = new MigrationStorageLockCoordinator( new MutationOperationReader( $records ), $locks );
		$writer = new StorageMutationGuard( new MutationGuardFence( $events ), $locks, static fn() => 'writer-before-gate' );
		$writer_lease = $writer->enter( 'cpt', 'property' );

		$blocked_migration = $coordinator->acquire( 'blueprint', 'change' );

		self::assertSame( 'eit_migration_storage_locked', $blocked_migration->get_error_code() );
		self::assertFalse( isset( $locks->active['eit-write-cpt-property'] ) );
		self::assertTrue( $writer->leave( $writer_lease ) );

		$migration_leases = $coordinator->acquire( 'blueprint', 'change' );
		self::assertIsArray( $migration_leases );
		$late_writer = new StorageMutationGuard( new MutationGuardFence( $events ), $locks, static fn() => 'writer-after-gate' );
		$blocked_writer = $late_writer->enter( 'cpt', 'property' );

		self::assertSame( 'eit_storage_write_locked', $blocked_writer->get_error_code() );
		self::assertFalse( isset( $locks->active['eit-writer-cpt-property-writer-after-gate'] ) );
		self::assertTrue( $coordinator->release( $migration_leases ) );
	}

	public function test_fenced_writer_releases_its_unique_lease(): void {
		$events = [];
		$locks = new SharedWriterLeaseStore( $events );
		$fence = new MutationGuardFence( $events, new WP_Error( 'eit_migration_write_fenced', 'fenced' ) );
		$guard = new StorageMutationGuard( $fence, $locks, static fn() => 'request-a' );

		$result = $guard->enter( 'cpt', 'property' );

		self::assertSame( 'eit_migration_write_fenced', $result->get_error_code() );
		self::assertSame( [ 'eit-writer-cpt-property-request-a' ], $locks->released );
	}

	public function test_stale_leave_cannot_release_a_new_writer_lease(): void {
		$events = [];
		$ids = [ 'request-a', 'request-b' ];
		$locks = new SharedWriterLeaseStore( $events );
		$guard = new StorageMutationGuard( new MutationGuardFence( $events ), $locks, static function () use ( &$ids ) {
			return array_shift( $ids );
		} );
		$stale = $guard->enter( 'cct', 'projects' );
		self::assertTrue( $guard->leave( $stale ) );
		$current = $guard->enter( 'cct', 'projects' );

		self::assertFalse( $guard->leave( $stale ) );
		self::assertArrayHasKey( 'eit-writer-cct-projects-request-b', $locks->active );
		self::assertTrue( $guard->leave( $current ) );
	}

	public function test_writer_renewal_loses_closed_on_changed_ownership(): void {
		$events = [];
		$locks = new SharedWriterLeaseStore( $events );
		$guard = new StorageMutationGuard( new MutationGuardFence( $events ), $locks, static fn() => 'request-a' );
		$lease = $guard->enter( 'cct', 'projects' );
		$locks->active['eit-writer-cct-projects-request-a'] = 'replacement-token';

		$result = $guard->enter( 'cct', 'projects' );

		self::assertSame( 'eit_storage_writer_lease_lost', $result->get_error_code() );
		self::assertFalse( $guard->leave( $lease ) );
	}

	public function test_migration_closes_every_gate_in_stable_order_then_checks_writers(): void {
		$events = [];
		$locks = new SharedWriterLeaseStore( $events );
		$records = [
			[ 'operation' => [ 'strategy' => 'cpt', 'storage_slug' => 'property' ] ],
			[ 'operation' => [ 'strategy' => 'cct', 'storage_slug' => 'projects' ] ],
			[ 'operation' => [ 'strategy' => 'cpt', 'storage_slug' => 'property' ] ],
		];
		$coordinator = new MigrationStorageLockCoordinator( new MutationOperationReader( $records ), $locks );

		$leases = $coordinator->acquire( 'blueprint', 'change', 7 );

		self::assertSame( [ 'eit-write-cct-projects', 'eit-write-cpt-property' ], array_keys( $leases ) );
		self::assertSame(
			[
				'acquire:eit-write-cct-projects',
				'acquire:eit-write-cpt-property',
				'drain:eit-writer-cct-projects-',
				'drain:eit-writer-cpt-property-',
			],
			array_slice( $events, -4 )
		);
		self::assertTrue( $coordinator->release( $leases ) );
		self::assertSame( [ 'eit-write-cpt-property', 'eit-write-cct-projects' ], array_slice( $locks->released, -2 ) );
	}

	public function test_partial_gate_failure_releases_prior_storage(): void {
		$events = [];
		$locks = new SharedWriterLeaseStore( $events, 'eit-write-cpt-property' );
		$records = [
			[ 'operation' => [ 'strategy' => 'cct', 'storage_slug' => 'projects' ] ],
			[ 'operation' => [ 'strategy' => 'cpt', 'storage_slug' => 'property' ] ],
		];
		$coordinator = new MigrationStorageLockCoordinator( new MutationOperationReader( $records ), $locks );

		$result = $coordinator->acquire( 'blueprint', 'change' );

		self::assertSame( 'eit_migration_storage_locked', $result->get_error_code() );
		self::assertSame( [ 'eit-write-cct-projects' ], $locks->released );
	}

	public function test_migration_renewal_covers_storage_ownership_blueprint_and_gate_locks(): void {
		$events = [];
		$locks = new SharedWriterLeaseStore( $events );
		$records = [ [ 'operation' => [ 'strategy' => 'cpt', 'storage_slug' => 'property' ] ] ];
		$coordinator = new MigrationStorageLockCoordinator( new MutationOperationReader( $records ), $locks );
		$ownership = $locks->acquire( 'storage-ownership' );
		$blueprint = $locks->acquire( 'blueprint-blueprint' );
		$storage = $coordinator->acquire( 'blueprint', 'change' );
		$leases = [ 'storage-ownership' => $ownership, 'blueprint-blueprint' => $blueprint ] + $storage;
		$locks->active['storage-ownership'] = 'replacement-token';

		$result = $coordinator->renew( $leases );

		self::assertSame( 'eit_migration_storage_lease_lost', $result->get_error_code() );
		self::assertTrue( $coordinator->release( $storage ) );
	}

	public function test_common_lifecycle_heartbeat_renews_ownership_blueprint_and_storage_as_one_set(): void {
		$events = [];
		$locks = new SharedWriterLeaseStore( $events );
		$leases = [];
		foreach ( [ 'storage-ownership', 'blueprint-blueprint', 'eit-write-cct-projects' ] as $resource ) {
			$leases[ $resource ] = $locks->acquire( $resource );
		}
		$guard = new LifecycleLeaseGuard( $locks, $leases );

		self::assertTrue( $guard->pulse() );
		self::assertSame( [ 'renew:storage-ownership', 'renew:blueprint-blueprint', 'renew:eit-write-cct-projects' ], array_slice( $events, -3 ) );
		$locks->active['eit-write-cct-projects'] = 'replacement-token';
		$result = $guard->pulse();

		self::assertSame( 'eit_lifecycle_lease_lost', $result->get_error_code() );
		self::assertSame( 'eit-write-cct-projects', $result->get_error_data()['resource'] );
	}

	public function test_write_fence_has_no_request_local_authorization_cache(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Blueprint/MigrationWriteFence.php' );

		self::assertStringNotContainsString( 'private $cache', $source );
		self::assertStringContainsString( 'Deliberately uncached', $source );
	}

	public function test_cct_entry_holds_writer_lease_until_outer_transaction_commits(): void {
		$events = [];
		$guard = new StorageMutationGuard( new MutationGuardFence( $events ), new EntryLeaseLocks( $events ), static fn() => 'request-a' );
		$cct = new ReentrantCctRepository( $guard, $events );
		$transaction = new EntryLeaseTransaction( $events );
		$gateway = new EntryStorageGateway( $cct, null, $transaction, null, null, $guard );
		$contract = [
			'blueprint_id' => 'blueprint',
			'entity' => [ 'strategy' => 'cct', 'definition' => [ 'slug' => 'projects' ] ],
			'fields' => [],
			'title_field_id' => 'title',
		];

		$result = $gateway->save( $contract, [ 'title' => 'Project' ], 0, 'publish', 7 );

		self::assertSame( 42, $result );
		self::assertSame(
			[
				'acquire:eit-writer-cct-projects-request-a',
				'gate:eit-write-cct-projects',
				'fence:cct:projects',
				'transaction:start',
				'renew:eit-writer-cct-projects-request-a',
				'gate:eit-write-cct-projects',
				'fence:cct:projects',
				'repository:write',
				'transaction:commit',
				'release:eit-writer-cct-projects-request-a',
			],
			$events
		);
	}
}

class EntryLeaseTransaction extends Transaction {

	private $events;

	public function __construct( array &$events ) {
		$this->events =& $events;
	}

	public function run( callable $callback ) {
		$this->events[] = 'transaction:start';
		$result = $callback();
		$this->events[] = 'transaction:commit';
		return $result;
	}
}

class ReentrantCctRepository extends CctRepository {

	private $guard;
	private $events;

	public function __construct( StorageMutationGuard $guard, array &$events ) {
		$this->guard = $guard;
		$this->events =& $events;
	}

	public function get( $type, $id ) {
		return null;
	}

	public function save( $type, array $values, $id = 0 ) {
		$lease = $this->guard->enter( 'cct', $type );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		try {
			$this->events[] = 'repository:write';
			return 42;
		} finally {
			$this->guard->leave( $lease );
		}
	}
}

class EntryLeaseLocks {

	private $events;

	public function __construct( array &$events ) {
		$this->events =& $events;
	}

	public function acquire( $resource ) {
		$this->events[] = 'acquire:' . $resource;
		return 'token-' . $resource;
	}

	public function renew( $resource ) {
		$this->events[] = 'renew:' . $resource;
		return true;
	}

	public function is_active( $resource ) {
		$this->events[] = 'gate:' . $resource;
		return false;
	}

	public function release( $resource ) {
		$this->events[] = 'release:' . $resource;
		return true;
	}
}

class MutationGuardFence extends MigrationWriteFence {

	private $events;
	private $result;

	public function __construct( array &$events, $result = true ) {
		$this->events =& $events;
		$this->result = $result;
	}

	public function guard_storage( $strategy, $storage_slug ) {
		$this->events[] = 'fence:' . $strategy . ':' . $storage_slug;
		return $this->result;
	}
}

class SharedWriterLeaseStore {

	public $active = [];
	public $released = [];
	private $events;
	private $fail_resource;

	public function __construct( array &$events, string $fail_resource = '' ) {
		$this->events =& $events;
		$this->fail_resource = $fail_resource;
	}

	public function acquire( $resource ) {
		$this->events[] = 'acquire:' . $resource;
		if ( $resource === $this->fail_resource || isset( $this->active[ $resource ] ) ) {
			return new WP_Error( 'locked', 'locked' );
		}
		$token = 'token-' . $resource;
		$this->active[ $resource ] = $token;
		return $token;
	}

	public function renew( $resource, $token ) {
		$this->events[] = 'renew:' . $resource;
		return isset( $this->active[ $resource ] ) && $token === $this->active[ $resource ]
			? true
			: new WP_Error( 'lease_lost', 'lost' );
	}

	public function is_active( $resource ) {
		$this->events[] = 'gate:' . $resource;
		return isset( $this->active[ $resource ] );
	}

	public function active_with_prefix( $prefix ) {
		$this->events[] = 'drain:' . $prefix;
		return array_values(
			array_filter( array_keys( $this->active ), static fn( $resource ) => str_starts_with( $resource, $prefix ) )
		);
	}

	public function release( $resource, $token = '' ) {
		$this->released[] = $resource;
		$this->events[] = 'release:' . $resource;
		if ( ! isset( $this->active[ $resource ] ) || $token !== $this->active[ $resource ] ) {
			return false;
		}
		unset( $this->active[ $resource ] );
		return true;
	}
}

class MutationOperationReader {

	private $records;

	public function __construct( array $records ) {
		$this->records = $records;
	}

	public function for_change_set() {
		return $this->records;
	}
}
