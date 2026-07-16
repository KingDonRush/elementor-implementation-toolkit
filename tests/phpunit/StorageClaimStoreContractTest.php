<?php
/**
 * Pure contracts for durable storage identity claims.
 */

use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\StorageClaimStore;
use EIT\Infrastructure\Tables;
use PHPUnit\Framework\TestCase;

class StorageClaimStoreContractTest extends TestCase {

	public function test_identity_hash_is_stable_and_strategy_scoped(): void {
		$cct = StorageClaimStore::identity_hash( 'CCT', 'Projects' );
		$cpt = StorageClaimStore::identity_hash( 'cpt', 'projects' );

		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $cct );
		self::assertSame( $cct, StorageClaimStore::identity_hash( 'cct', 'projects' ) );
		self::assertNotSame( $cct, $cpt );
		self::assertSame( '', StorageClaimStore::identity_hash( 'adapter', 'projects' ) );
		self::assertSame( '', StorageClaimStore::identity_hash( 'cct', '' ) );
		self::assertSame( '', StorageClaimStore::identity_hash( 'cpt', str_repeat( 'x', 21 ) ) );
	}

	public function test_schema_registers_the_durable_claim_ledger(): void {
		self::assertSame( '6', SchemaManager::VERSION );
		self::assertCount( 18, Tables::keys() );
		self::assertContains( Tables::STORAGE_CLAIMS, Tables::keys() );
	}

	public function test_store_never_drops_or_mutates_external_storage(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Infrastructure/StorageClaimStore.php' );

		self::assertStringNotContainsString( 'DROP TABLE', $source );
		self::assertStringNotContainsString( 'drop_table(', $source );
		self::assertStringNotContainsString( 'unregister_post_type', $source );
	}
}
