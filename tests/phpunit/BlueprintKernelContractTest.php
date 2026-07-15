<?php
/**
 * Pure tests for the canonical Blueprint grammar.
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\Canonicalizer;
use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\ImpactPlanner;
use EIT\Blueprint\StorageRecommendation;
use EIT\Blueprint\Uuid;
use PHPUnit\Framework\TestCase;

class BlueprintKernelContractTest extends TestCase {

	public function test_uuid_v5_is_stable_and_valid(): void {
		$first = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'cpt:property' );
		$second = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'cpt:property' );

		self::assertSame( $first, $second );
		self::assertTrue( Uuid::is_valid( $first ) );
		self::assertNotSame( $first, Uuid::v5( Uuid::LEGACY_NAMESPACE, 'cpt:agent' ) );
	}

	public function test_checksum_ignores_canvas_position_and_record_order(): void {
		$blueprint = $this->valid_blueprint();
		$reordered = $blueprint;
		$reordered['nodes'] = array_reverse( $reordered['nodes'] );
		$reordered['nodes'][0]['position'] = [ 'x' => 900, 'y' => 40 ];
		$reordered['nodes'][0]['ui'] = [ 'collapsed' => true ];

		$canonicalizer = new Canonicalizer();
		self::assertSame( $canonicalizer->checksum( $blueprint ), $canonicalizer->checksum( $reordered ) );
	}

	public function test_builtin_registry_exposes_the_complete_initial_primitive_set(): void {
		$registry = new FieldPrimitiveRegistry();

		self::assertCount( 27, $registry->all() );
		self::assertTrue( $registry->has( 'money' ) );
		self::assertSame( 'list', $registry->get( 'repeatable_group' )->get_definition()['shape'] );
		self::assertFalse( $registry->get( 'gallery' )->get_definition()['capabilities']['filter'] );
	}

	public function test_valid_blueprint_passes_typed_contract_validation(): void {
		$result = ( new BlueprintValidator() )->validate( $this->valid_blueprint() );

		self::assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		self::assertSame( [], $result->errors() );
	}

	public function test_orphan_reference_and_invalid_connection_are_blocked(): void {
		$blueprint = $this->valid_blueprint();
		$blueprint['connections'][0]['to'] = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'missing-node' );

		$codes = $this->error_codes( ( new BlueprintValidator() )->validate( $blueprint )->errors() );
		self::assertContains( 'orphan_reference', $codes );
		self::assertContains( 'orphan_node', $codes );
	}

	public function test_dependency_cycles_are_blocked(): void {
		$first = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'presentation:first' );
		$second = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'presentation:second' );
		$blueprint = [
			'api_version' => BlueprintValidator::API_VERSION,
			'kind' => BlueprintValidator::KIND,
			'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'blueprint:cycle' ),
			'name' => 'Cycle fixture',
			'version' => 1,
			'nodes' => [
				[ 'id' => $first, 'type' => 'presentation', 'lane' => 'presentation', 'name' => 'First', 'config' => [] ],
				[ 'id' => $second, 'type' => 'presentation', 'lane' => 'presentation', 'name' => 'Second', 'config' => [] ],
			],
			'connections' => [
				[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'edge:first-second' ), 'type' => 'composes', 'from' => $first, 'to' => $second ],
				[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'edge:second-first' ), 'type' => 'composes', 'from' => $second, 'to' => $first ],
			],
		];

		$codes = $this->error_codes( ( new BlueprintValidator() )->validate( $blueprint )->errors() );
		self::assertContains( 'dependency_cycle', $codes );
	}

	public function test_unsupported_field_indexing_is_blocked(): void {
		$blueprint = $this->valid_blueprint();
		$blueprint['nodes'][1]['config']['fields'][0]['type'] = 'gallery';
		$blueprint['nodes'][1]['config']['fields'][0]['shape'] = 'list';
		$blueprint['nodes'][1]['config']['fields'][0]['indexing']['filter'] = true;

		$codes = $this->error_codes( ( new BlueprintValidator() )->validate( $blueprint )->errors() );
		self::assertContains( 'unsupported_capability', $codes );
	}

	public function test_declared_checksum_must_match_semantic_document(): void {
		$blueprint = $this->valid_blueprint();
		$blueprint['checksum'] = str_repeat( '0', 64 );

		$codes = $this->error_codes( ( new BlueprintValidator() )->validate( $blueprint )->errors() );
		self::assertContains( 'checksum_mismatch', $codes );
	}

	public function test_storage_recommendation_is_explainable_and_override_requires_reason(): void {
		$service = new StorageRecommendation();
		$cpt = $service->recommend( [ 'config' => [ 'mode' => 'editorial', 'public' => true ] ] );
		$cct = $service->recommend( [ 'config' => [ 'mode' => 'structured', 'high_volume' => true ] ] );
		$adapter = $service->recommend( [ 'config' => [ 'owner' => 'woocommerce' ] ] );
		$invalid_override = $service->recommend( [ 'config' => [ 'public' => true, 'storage' => [ 'strategy' => 'cct' ] ] ] );

		self::assertSame( 'cpt', $cpt['recommended'] );
		self::assertSame( 'cct', $cct['recommended'] );
		self::assertSame( 'adapter', $adapter['recommended'] );
		self::assertTrue( $invalid_override['override'] );
		self::assertFalse( $invalid_override['valid'] );
		self::assertNotEmpty( $invalid_override['impact'] );
	}

	public function test_impact_planner_blocks_silent_published_storage_key_changes(): void {
		$field_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'field:locked-storage' );
		$active = [ [ 'field_id' => $field_id, 'storage_key' => 'published_key' ] ];
		$next = [ [ 'field_id' => $field_id, 'storage_key' => 'renamed_key' ] ];
		$impact = ( new ImpactPlanner() )->plan( [], $active, [], $next );

		self::assertTrue( $impact['blocked'] );
		self::assertSame( 'published_storage_key_locked', $impact['blockers'][0]['code'] );
		self::assertSame( 'storage_key_changed', $impact['bindings'][0]['change'] );
	}

	public function test_validator_rejects_ambiguous_storage_bindings(): void {
		$blueprint = $this->valid_blueprint();
		$first = $blueprint['nodes'][1]['config']['fields'][0];
		$second = $first;
		$second['id'] = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'field:collision' );
		$first['storage']['key'] = 'listing-price';
		$second['storage']['key'] = 'listing_price';
		$blueprint['nodes'][1]['config']['fields'] = [ $first, $second ];

		$errors = ( new BlueprintValidator() )->validate( $blueprint )->errors();
		self::assertContains( 'storage_column_collision', array_column( $errors, 'code' ) );
	}

	private function valid_blueprint(): array {
		$entity_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entity:property' );
		$group_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'field-group:property' );
		$field_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'field:property:price' );
		$field = ( new FieldContractFactory( new FieldPrimitiveRegistry() ) )->make(
			$field_id,
			'Price',
			'money',
			[ 'indexing' => [ 'filter' => true, 'sort' => true ] ]
		);

		return [
			'api_version' => BlueprintValidator::API_VERSION,
			'kind' => BlueprintValidator::KIND,
			'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'blueprint:property' ),
			'name' => 'Property system',
			'version' => 1,
			'nodes' => [
				[ 'id' => $entity_id, 'type' => 'entity', 'lane' => 'data', 'name' => 'Property', 'config' => [ 'mode' => 'structured', 'public' => true ] ],
				[ 'id' => $group_id, 'type' => 'field_group', 'lane' => 'data', 'name' => 'Details', 'config' => [ 'fields' => [ $field ] ] ],
			],
			'connections' => [
				[ 'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'edge:property-fields' ), 'type' => 'entity_fields', 'from' => $entity_id, 'to' => $group_id ],
			],
		];
	}

	private function error_codes( array $errors ): array {
		return array_column( $errors, 'code' );
	}
}
