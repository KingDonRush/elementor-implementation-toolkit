<?php
/**
 * Runtime contract boundaries for code-registered storage adapters.
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\Compiler;
use EIT\Blueprint\Uuid;
use EIT\Contracts\StorageAdapterInterface;
use EIT\Registry\RegistryHub;
use PHPUnit\Framework\TestCase;

class StorageAdapterContractTest extends TestCase {

	public function test_adapter_failures_become_compile_errors_without_leaking_details(): void {
		$unhealthy_registry = new RegistryHub();
		$unhealthy_registry->storage_adapters()->register( $this->adapter( 'health' ) );
		$unhealthy = ( new Compiler( null, null, null, $unhealthy_registry ) )->compile( $this->blueprint() );

		$compile_registry = new RegistryHub();
		$compile_registry->storage_adapters()->register( $this->adapter( 'compile' ) );
		$compile = ( new Compiler( null, null, null, $compile_registry ) )->compile( $this->blueprint() );

		self::assertContains( 'storage_adapter_unhealthy', array_column( $unhealthy->errors(), 'code' ) );
		self::assertContains( 'storage_adapter_compile_failed', array_column( $compile->errors(), 'code' ) );
		self::assertStringNotContainsString( 'adapter-secret', wp_json_encode( [ $unhealthy->errors(), $compile->errors() ] ) );
	}

	private function blueprint(): array {
		$entity_id = $this->id( 'entity' );
		$adapter_id = $this->id( 'adapter' );
		return [
			'api_version' => BlueprintValidator::API_VERSION,
			'kind' => BlueprintValidator::KIND,
			'id' => $this->id( 'blueprint' ),
			'name' => 'SDK storage boundary',
			'version' => 1,
			'nodes' => [
				[ 'id' => $entity_id, 'type' => 'entity', 'lane' => 'data', 'name' => 'Records', 'config' => [ 'mode' => 'structured' ] ],
				[ 'id' => $adapter_id, 'type' => 'adapter', 'lane' => 'governance', 'name' => 'SDK records', 'config' => [ 'adapter_id' => 'sdk_records' ] ],
			],
			'connections' => [
				[ 'id' => $this->id( 'edge:adapter' ), 'type' => 'adapts', 'from' => $adapter_id, 'to' => $entity_id ],
			],
		];
	}

	private function adapter( string $failure ): StorageAdapterInterface {
		return new class( $failure ) implements StorageAdapterInterface {
			private $failure;

			public function __construct( $failure ) {
				$this->failure = $failure;
			}

			public function get_id() {
				return 'sdk_records';
			}

			public function get_version() {
				return '1.0.0';
			}

			public function get_capabilities() {
				return [ 'structured_fields' ];
			}

			public function compile( array $entity, array $fields, array $context = [] ) {
				if ( 'compile' === $this->failure ) {
					throw new RuntimeException( 'adapter-secret' );
				}
				return [ 'strategy' => 'adapter', 'definition' => [ 'slug' => 'sdk_records' ] ];
			}

			public function prepare( array $artifact, array $context = [] ) {
				return true;
			}

			public function health_check() {
				if ( 'health' === $this->failure ) {
					throw new RuntimeException( 'adapter-secret' );
				}
				return [ 'ok' => true ];
			}
		};
	}

	private function id( string $seed ): string {
		return Uuid::v5( Uuid::LEGACY_NAMESPACE, 'storage-adapter-contract:' . $seed );
	}
}
