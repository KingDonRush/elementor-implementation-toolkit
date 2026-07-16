<?php
/**
 * Runtime fencing for code-registered Collection providers.
 */

use EIT\Collection\CollectionQueryService;
use EIT\Contracts\CollectionProviderInterface;
use EIT\Registry\RegistryHub;
use PHPUnit\Framework\TestCase;

class CollectionProviderRuntimeContractTest extends TestCase {

	public function test_capability_drift_is_rejected_before_cached_output_can_be_served(): void {
		$cache = $this->cache( [ 'html' => 'stale' ] );
		$service = $this->service( $this->provider( [ 'pagination' ] ), $cache );
		$result = $service->execute( $this->contract( [ 'facets', 'pagination' ] ), [] );

		self::assertSame( 'eit_collection_provider_capabilities_mismatch', $result->get_error_code() );
		self::assertSame( 0, $cache->get_calls );
	}

	public function test_health_exception_is_redacted_and_rejected_before_cache(): void {
		$cache = $this->cache( [ 'html' => 'stale' ] );
		$service = $this->service( $this->provider( [ 'pagination' ], true ), $cache );
		$result = $service->execute( $this->contract( [ 'pagination' ] ), [] );

		self::assertSame( 'eit_collection_provider_unhealthy', $result->get_error_code() );
		self::assertStringNotContainsString( 'provider-secret', $result->get_error_message() );
		self::assertSame( 0, $cache->get_calls );
	}

	public function test_published_requirements_are_enforced_before_cache(): void {
		$cache = $this->cache( [ 'html' => 'stale' ] );
		$service = $this->service( $this->provider( [ 'pagination' ] ), $cache );
		$contract = $this->contract( [ 'pagination' ] );
		$contract['provider']['required_capabilities'] = [ 'facets', 'pagination' ];

		$result = $service->execute( $contract, [] );

		self::assertSame( 'eit_collection_provider_requirements_invalid', $result->get_error_code() );
		self::assertSame( 0, $cache->get_calls );
	}

	public function test_query_exception_becomes_a_bounded_operational_error(): void {
		$cache = $this->cache();
		$service = $this->service( $this->provider( [ 'pagination' ], false, true ), $cache );
		$result = $service->execute( $this->contract( [ 'pagination' ] ), [] );

		self::assertSame( 'eit_collection_provider_failed', $result->get_error_code() );
		self::assertSame( 502, $result->get_error_data()['status'] );
		self::assertStringNotContainsString( 'provider-secret', $result->get_error_message() );
		self::assertSame( 1, $cache->get_calls );
	}

	private function service( CollectionProviderInterface $provider, $cache ): CollectionQueryService {
		$registries = new RegistryHub();
		$registries->collection_providers()->register( $provider );
		$scope = new class() {
			public function apply( array $contract, array $request, array $context = [] ) {
				return $request;
			}
		};
		return new CollectionQueryService( [ 'registries' => $registries, 'cache' => $cache, 'scope' => $scope ] );
	}

	private function cache( $value = null ) {
		return new class( $value ) {
			public $get_calls = 0;
			private $value;

			public function __construct( $value ) {
				$this->value = $value;
			}

			public function get( array $contract, array $request ) {
				++$this->get_calls;
				return $this->value;
			}

			public function set( array $contract, array $request, array $response ) {
				return true;
			}
		};
	}

	private function provider( array $capabilities, $throw_health = false, $throw_query = false ): CollectionProviderInterface {
		return new class( $capabilities, $throw_health, $throw_query ) implements CollectionProviderInterface {
			private $capabilities;
			private $throw_health;
			private $throw_query;

			public function __construct( array $capabilities, $throw_health, $throw_query ) {
				$this->capabilities = $capabilities;
				$this->throw_health = $throw_health;
				$this->throw_query = $throw_query;
			}

			public function get_id() {
				return 'runtime_records';
			}

			public function get_version() {
				return '1.2.3';
			}

			public function get_capabilities() {
				return $this->capabilities;
			}

			public function query( array $contract, array $request, array $context = [] ) {
				if ( $this->throw_query ) {
					throw new RuntimeException( 'provider-secret' );
				}
				return [];
			}

			public function health_check() {
				if ( $this->throw_health ) {
					throw new RuntimeException( 'provider-secret' );
				}
				return [ 'ok' => true ];
			}
		};
	}

	private function contract( array $capabilities ): array {
		sort( $capabilities, SORT_STRING );
		return [
			'collection_id' => '86c8588f-407d-4abc-8b75-774aa226b99a',
			'provider' => [ 'id' => 'runtime_records', 'version' => '1.2.3', 'capabilities' => $capabilities, 'required_capabilities' => $capabilities ],
			'fields' => [],
			'cache' => [ 'enabled' => true ],
		];
	}
}
