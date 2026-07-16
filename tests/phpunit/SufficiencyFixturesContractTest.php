<?php
/**
 * Proves representative implementations compile from one closed primitive grammar.
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\Compiler;
use EIT\Blueprint\CoreStorageAdapter;
use EIT\Collection\CctCollectionProvider;
use EIT\Collection\CptCollectionProvider;
use EIT\Collection\WooCollectionProvider;
use EIT\Registry\RegistryHub;
use EIT\Woo\WooStorageAdapter;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/SufficiencyBlueprints.php';

class SufficiencyFixturesContractTest extends TestCase {

	public function test_all_domain_fixtures_validate_and_compile_with_the_same_kernel(): void {
		$registries = $this->healthy_registries();
		$validator = new BlueprintValidator( null, $registries->field_primitives(), null, $registries );
		$compiler = new Compiler( $validator, null, null, $registries );

		foreach ( ( new EitSufficiencyBlueprints() )->all() as $domain => $blueprint ) {
			$validation = $validator->validate( $blueprint );
			$compiled = $compiler->compile( $blueprint );
			self::assertTrue( $validation->is_valid(), $domain . ': ' . wp_json_encode( $validation->errors() ) );
			self::assertTrue( $compiled->is_valid(), $domain . ': ' . wp_json_encode( $compiled->errors() ) );
			self::assertNotEmpty( $compiled->artifacts(), $domain );
		}
	}

	public function test_fixtures_use_stable_field_ids_and_never_expose_raw_implementation_inputs(): void {
		$forbidden = [ 'meta_key', 'raw_key', 'selector', 'sql', 'php', 'query_args' ];
		foreach ( ( new EitSufficiencyBlueprints() )->all() as $blueprint ) {
			$encoded = strtolower( wp_json_encode( $blueprint ) );
			foreach ( $forbidden as $key ) {
				self::assertStringNotContainsString( '"' . $key . '"', $encoded );
			}
			foreach ( $blueprint['nodes'] as $node ) {
				if ( 'field_group' !== $node['type'] ) {
					continue;
				}
				foreach ( $node['config']['fields'] ?? [] as $field ) {
					self::assertTrue( EIT\Blueprint\Uuid::is_valid( $field['id'] ) );
					self::assertStringStartsWith( 'eit_', $field['storage']['key'] );
				}
			}
		}
	}

	public function test_each_fixture_proves_its_distinct_sufficiency_contract(): void {
		$fixtures = ( new EitSufficiencyBlueprints() )->all();
		self::assertSame( [ 'address', 'gallery', 'money', 'phone', 'relation', 'short_text' ], $this->field_types( $fixtures['real_estate'] ) );
		self::assertSame( [ 'availability', 'schedule', 'short_text', 'taxonomy' ], $this->field_types( $fixtures['clinic'] ) );
		self::assertSame( [ 'availability', 'relation', 'repeatable_group', 'short_text' ], $this->field_types( $fixtures['delivery'] ) );
		self::assertSame( [], $this->field_types( $fixtures['ecommerce'] ) );
		self::assertContains( 'woocommerce', $this->adapter_ids( $fixtures['delivery'] ) );
		self::assertContains( 'woocommerce', $this->adapter_ids( $fixtures['ecommerce'] ) );
	}

	private function healthy_registries(): RegistryHub {
		$hub = new RegistryHub();
		$hub->storage_adapters()->register( new CoreStorageAdapter( 'cpt' ) );
		$hub->storage_adapters()->register( new CoreStorageAdapter( 'cct' ) );
		$hub->storage_adapters()->register(
			new class() extends WooStorageAdapter {
				public function health_check() {
					return [ 'ok' => true, 'version' => $this->get_version(), 'fixture' => true ];
				}
			}
		);
		$hub->collection_providers()->register( new CptCollectionProvider() );
		$hub->collection_providers()->register( new CctCollectionProvider() );
		$hub->collection_providers()->register(
			new class() extends WooCollectionProvider {
				public function health_check() {
					return [ 'ok' => true, 'version' => $this->get_version(), 'fixture' => true ];
				}
			}
		);
		return $hub;
	}

	private function field_types( array $blueprint ): array {
		$types = [];
		foreach ( $blueprint['nodes'] as $node ) {
			$types = array_merge( $types, array_column( $node['config']['fields'] ?? [], 'type' ) );
		}
		$types = array_values( array_unique( $types ) );
		sort( $types );
		return $types;
	}

	private function adapter_ids( array $blueprint ): array {
		return array_values(
			array_filter(
				array_map( fn( $node ) => 'adapter' === $node['type'] ? $node['config']['adapter_id'] : null, $blueprint['nodes'] )
			)
		);
	}
}
