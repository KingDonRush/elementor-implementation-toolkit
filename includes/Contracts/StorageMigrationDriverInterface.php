<?php
/**
 * Public contract for bounded, idempotent physical field migrations.
 */

namespace EIT\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface StorageMigrationDriverInterface {

	public function supports( array $operation );

	public function assert_target_available( array $operation, bool $recovering );

	public function assert_target_ready( array $operation );

	public function copy_batch( array $operation, int $cursor, int $limit );

	public function fingerprint( array $operation, string $side );
}
