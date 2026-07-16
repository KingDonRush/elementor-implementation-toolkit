<?php
/**
 * Proves representative implementations compile from one closed primitive grammar.
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\Compiler;
use EIT\Blueprint\CoreStorageAdapter;
use EIT\Blueprint\Uuid;
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

	public function test_entry_relation_compiles_its_exact_target_entity_contract(): void {
		$fixtures = ( new EitSufficiencyBlueprints() )->all();
		$result = ( new Compiler( null, null, null, $this->healthy_registries() ) )->compile( $fixtures['delivery'] );
		$entry = current( array_filter( $result->artifacts(), fn( $artifact ) => 'entry_contract' === $artifact['kind'] ) );
		$relation = current( array_filter( $entry['payload']['fields'], fn( $field ) => 'relation' === $field['type'] ) );

		self::assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		self::assertNotEmpty( $relation['relation']['target_entity_id'] );
		self::assertSame( 'woocommerce', $relation['relation']['target']['adapter']['id'] );
		self::assertSame( 'any', $relation['relation']['ownership'] );
		self::assertNotEmpty( $relation['relation']['options_collection_id'] );
		self::assertTrue( $relation['relation']['search_enabled'] );
	}

	public function test_relation_option_collection_rejects_a_provider_without_required_search(): void {
		$blueprint = ( new EitSufficiencyBlueprints() )->all()['delivery'];
		foreach ( $blueprint['nodes'] as &$node ) {
			if ( 'adapter' === $node['type'] ) {
				$node['config']['collection_provider'] = [ 'id' => 'pagination_only', 'required_capabilities' => [] ];
			}
		}
		unset( $node );
		$registries = $this->healthy_registries();
		$registries->collection_providers()->register(
			new class() extends WooCollectionProvider {
				public function get_id() {
					return 'pagination_only';
				}

				public function get_capabilities() {
					return [ 'pagination' ];
				}

				public function health_check() {
					return [ 'ok' => true, 'version' => $this->get_version(), 'fixture' => true ];
				}
			}
		);
		$result = ( new Compiler( null, null, null, $registries ) )->compile( $blueprint );
		self::assertFalse( $result->is_valid() );
		self::assertContains( 'collection_provider_incompatible', array_column( $result->errors(), 'code' ) );
	}

	public function test_relation_option_collection_is_required_and_must_query_the_target_entity(): void {
		$blueprint = ( new EitSufficiencyBlueprints() )->all()['real_estate'];
		$without_options = $blueprint;
		$without_options['connections'] = array_values( array_filter( $without_options['connections'], fn( $edge ) => 'relation_options' !== $edge['type'] ) );
		$mismatched = $blueprint;
		$property_collection = current( array_filter( $mismatched['connections'], fn( $edge ) => 'collection_for' === $edge['type'] ) );
		foreach ( $mismatched['connections'] as &$edge ) {
			if ( 'relation_options' === $edge['type'] ) {
				$edge['to'] = $property_collection['to'];
			}
		}
		unset( $edge );
		$validator = new BlueprintValidator();

		self::assertContains( 'relation_options_required', array_column( $validator->validate( $without_options )->errors(), 'code' ) );
		self::assertContains( 'relation_options_target_mismatch', array_column( $validator->validate( $mismatched )->errors(), 'code' ) );
	}

	public function test_relation_option_collection_must_enforce_the_same_policy_scope(): void {
		$blueprint = ( new EitSufficiencyBlueprints() )->all()['real_estate'];
		$relation_index = array_search( 'relation', array_column( $blueprint['nodes'], 'type' ), true );
		$blueprint['nodes'][ $relation_index ]['config']['ownership'] = 'own';
		$codes = array_column( ( new BlueprintValidator() )->validate( $blueprint )->errors(), 'code' );

		self::assertContains( 'relation_options_policy_mismatch', $codes );

		$option_edges = array_values( array_filter( $blueprint['connections'], fn( $edge ) => 'relation_options' === $edge['type'] ) );
		$collection_id = $option_edges[0]['to'];
		$policy_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'real-estate:agent-options-policy' );
		$blueprint['nodes'][] = [ 'id' => $policy_id, 'type' => 'policy', 'lane' => 'governance', 'name' => 'Agent ownership', 'config' => [ 'ownership' => 'own', 'object_scope' => 'entity' ] ];
		foreach ( $blueprint['nodes'] as &$node ) {
			if ( $collection_id === $node['id'] ) {
				$node['config']['access'] = 'authenticated';
			}
		}
		unset( $node );
		$blueprint['connections'][] = [
			'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'real-estate:agent-options-policy-edge' ),
			'type' => 'governs_collection',
			'from' => $policy_id,
			'to' => $collection_id,
		];
		$aligned = ( new BlueprintValidator() )->validate( $blueprint );

		self::assertTrue( $aligned->is_valid(), wp_json_encode( $aligned->errors() ) );
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
