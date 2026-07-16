<?php
/**
 * Domain-neutral sufficiency fixtures built exclusively from public Blueprint primitives.
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\Uuid;

class EitSufficiencyBlueprints {

	private $fields;

	public function __construct() {
		$this->fields = new FieldContractFactory( new FieldPrimitiveRegistry() );
	}

	public function all(): array {
		return [
			'real_estate' => $this->real_estate(),
			'clinic' => $this->clinic(),
			'delivery' => $this->delivery(),
			'ecommerce' => $this->ecommerce(),
		];
	}

	private function real_estate(): array {
		$property = $this->node( 'real-estate:property', 'entity', 'Property', [ 'mode' => 'structured', 'public' => true, 'routed' => true, 'versioned' => true, 'slug' => 'property' ] );
		$agent = $this->node( 'real-estate:agent', 'entity', 'Agent', [ 'mode' => 'structured', 'public' => true, 'routed' => true, 'slug' => 'agent' ] );
		$property_fields = [
			$this->field( 'real-estate:property:title', 'Public title', 'short_text', [ 'validation' => [ 'required' => true ], 'exposure' => [ 'public' => true ], 'indexing' => [ 'search' => true, 'sort' => true ] ] ),
			$this->field( 'real-estate:property:price', 'Price', 'money', [ 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true, 'sort' => true ] ] ),
			$this->field( 'real-estate:property:address', 'Address', 'address', [ 'exposure' => [ 'public' => true ], 'indexing' => [ 'search' => true, 'filter' => true ] ] ),
			$this->field( 'real-estate:property:gallery', 'Gallery', 'gallery', [ 'exposure' => [ 'public' => true ] ] ),
			$this->field( 'real-estate:property:agent', 'Responsible agent', 'relation', [ 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true ] ] ),
		];
		$agent_fields = [
			$this->field( 'real-estate:agent:name', 'Name', 'short_text', [ 'validation' => [ 'required' => true ], 'exposure' => [ 'public' => true ], 'indexing' => [ 'search' => true, 'sort' => true ] ] ),
			$this->field( 'real-estate:agent:phone', 'Phone', 'phone', [ 'exposure' => [ 'public' => true ] ] ),
		];
		$property_group = $this->node( 'real-estate:property-fields', 'field_group', 'Property details', [ 'fields' => $property_fields ] );
		$agent_group = $this->node( 'real-estate:agent-fields', 'field_group', 'Agent details', [ 'fields' => $agent_fields ] );
		$relation = $this->node( 'real-estate:assignment', 'relation', 'Agent assignment', [ 'cardinality' => 'many_to_one', 'field_id' => $property_fields[4]['id'] ] );
		$collection = $this->node( 'real-estate:collection', 'collection', 'Available properties', [ 'access' => 'public', 'page_size' => 24, 'projection_field_ids' => array_column( $property_fields, 'id' ), 'sort_field_ids' => [ $property_fields[1]['id'] ] ] );
		$filters = $this->node( 'real-estate:filters', 'filter_surface', 'Property filters', [ 'fields' => [ $property_fields[1]['id'], $property_fields[2]['id'], $property_fields[4]['id'] ], 'facet_fields' => [ $property_fields[4]['id'] ], 'url_state' => true ] );
		return $this->blueprint(
			'real-estate',
			'Real estate sufficiency',
			[ $property, $agent, $property_group, $agent_group, $relation, $collection, $filters ],
			[
				$this->edge( 'real-estate:property-fields', 'entity_fields', $property, $property_group ),
				$this->edge( 'real-estate:agent-fields', 'entity_fields', $agent, $agent_group ),
				$this->edge( 'real-estate:relation-source', 'relation_source', $property, $relation ),
				$this->edge( 'real-estate:relation-target', 'relation_target', $relation, $agent ),
				$this->edge( 'real-estate:collection', 'collection_for', $property, $collection ),
				$this->edge( 'real-estate:filters', 'filters', $collection, $filters ),
			]
		);
	}

	private function clinic(): array {
		$professional = $this->node( 'clinic:professional', 'entity', 'Professional', [ 'mode' => 'structured', 'public' => true, 'routed' => true, 'slug' => 'professional' ] );
		$fields = [
			$this->field( 'clinic:name', 'Name', 'short_text', [ 'validation' => [ 'required' => true ], 'exposure' => [ 'public' => true ], 'indexing' => [ 'search' => true, 'sort' => true ] ] ),
			$this->field( 'clinic:specialty', 'Specialty', 'taxonomy', [ 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true, 'sort' => true ], 'taxonomy' => [ 'slug' => 'specialty', 'singular' => 'Specialty', 'plural' => 'Specialties' ] ] ),
			$this->field( 'clinic:schedule', 'Working schedule', 'schedule', [ 'exposure' => [ 'public' => true ] ] ),
			$this->field( 'clinic:availability', 'Availability', 'availability', [ 'exposure' => [ 'public' => true ], 'indexing' => [ 'filter' => true ] ] ),
		];
		$group = $this->node( 'clinic:fields', 'field_group', 'Professional profile', [ 'fields' => $fields ] );
		$entry = $this->entry_node( 'clinic:entry', 'Professional workspace', array_column( $fields, 'id' ), $fields[0]['id'] );
		$policy = $this->node( 'clinic:policy', 'policy', 'Professional editors', [ 'capability' => 'edit_posts', 'publish_capability' => 'publish_posts', 'ownership' => 'own', 'object_scope' => 'entity' ] );
		$collection = $this->node( 'clinic:collection', 'collection', 'Professional directory', [ 'access' => 'public', 'sort_field_ids' => [ $fields[0]['id'] ] ] );
		$filters = $this->node( 'clinic:filters', 'filter_surface', 'Directory filters', [ 'fields' => [ $fields[1]['id'], $fields[3]['id'] ], 'facet_fields' => [ $fields[1]['id'] ] ] );
		return $this->blueprint(
			'clinic',
			'Clinic sufficiency',
			[ $professional, $group, $entry, $policy, $collection, $filters ],
			[
				$this->edge( 'clinic:fields', 'entity_fields', $professional, $group ),
				$this->edge( 'clinic:entry', 'entry_for', $professional, $entry ),
				$this->edge( 'clinic:policy', 'governs_entry', $policy, $entry ),
				$this->edge( 'clinic:collection', 'collection_for', $professional, $collection ),
				$this->edge( 'clinic:filters', 'filters', $collection, $filters ),
			]
		);
	}

	private function delivery(): array {
		$item = $this->node( 'delivery:item', 'entity', 'Menu item', [ 'mode' => 'structured', 'high_volume' => true, 'slug' => 'menu_items' ] );
		$product = $this->node( 'delivery:product', 'entity', 'Checkout product', [ 'owner' => 'woocommerce', 'public' => true ] );
		$fields = [
			$this->field( 'delivery:name', 'Name', 'short_text', [ 'validation' => [ 'required' => true ], 'indexing' => [ 'search' => true, 'sort' => true ] ] ),
			$this->field( 'delivery:addons', 'Additional groups', 'repeatable_group', [ 'validation' => [ 'children' => [ [ 'id' => $this->id( 'delivery:addon-name' ), 'name' => 'Option', 'type' => 'short_text' ], [ 'id' => $this->id( 'delivery:addon-price' ), 'name' => 'Price delta', 'type' => 'decimal' ] ] ] ] ),
			$this->field( 'delivery:availability', 'Availability', 'availability', [ 'indexing' => [ 'filter' => true, 'sort' => true ] ] ),
			$this->field( 'delivery:product-relation', 'Woo product', 'relation', [ 'indexing' => [ 'filter' => true ] ] ),
		];
		$group = $this->node( 'delivery:fields', 'field_group', 'Menu item structure', [ 'fields' => $fields ] );
		$adapter = $this->node( 'delivery:woo-adapter', 'adapter', 'WooCommerce checkout bridge', [ 'adapter_id' => 'woocommerce' ] );
		$relation = $this->node( 'delivery:product-link', 'relation', 'Checkout product link', [ 'cardinality' => 'many_to_one', 'field_id' => $fields[3]['id'] ] );
		$entry = $this->entry_node( 'delivery:entry', 'Menu workspace', array_column( $fields, 'id' ), $fields[0]['id'] );
		$policy = $this->node( 'delivery:policy', 'policy', 'Menu operators', [ 'capability' => 'edit_posts', 'ownership' => 'any', 'object_scope' => 'entity' ] );
		return $this->blueprint(
			'delivery',
			'Delivery sufficiency',
			[ $item, $product, $group, $adapter, $relation, $entry, $policy ],
			[
				$this->edge( 'delivery:fields', 'entity_fields', $item, $group ),
				$this->edge( 'delivery:adapter', 'adapts', $adapter, $product ),
				$this->edge( 'delivery:relation-source', 'relation_source', $item, $relation ),
				$this->edge( 'delivery:relation-target', 'relation_target', $relation, $product ),
				$this->edge( 'delivery:entry', 'entry_for', $item, $entry ),
				$this->edge( 'delivery:policy', 'governs_entry', $policy, $entry ),
			]
		);
	}

	private function ecommerce(): array {
		$catalog = new EIT\Woo\WooFieldContractCatalog();
		$by_key = [];
		foreach ( $catalog->all() as $field ) {
			$by_key[ $field['storage']['key'] ] = $field;
		}
		$product = $this->node( 'ecommerce:product', 'entity', 'Product', [ 'owner' => 'woocommerce', 'public' => true ] );
		$adapter = $this->node( 'ecommerce:adapter', 'adapter', 'WooCommerce catalog', [ 'adapter_id' => 'woocommerce' ] );
		$collection = $this->node( 'ecommerce:collection', 'collection', 'Product catalog', [ 'access' => 'public', 'page_size' => 24, 'sort_field_ids' => [ $by_key['name']['id'], $by_key['price']['id'] ] ] );
		$filters = $this->node( 'ecommerce:filters', 'filter_surface', 'Catalog facets', [ 'fields' => [ $by_key['category']['id'], $by_key['stock_status']['id'] ], 'facet_fields' => [ $by_key['category']['id'], $by_key['stock_status']['id'] ], 'url_state' => true ] );
		return $this->blueprint(
			'ecommerce',
			'Ecommerce sufficiency',
			[ $product, $adapter, $collection, $filters ],
			[
				$this->edge( 'ecommerce:adapter', 'adapts', $adapter, $product ),
				$this->edge( 'ecommerce:collection', 'collection_for', $product, $collection ),
				$this->edge( 'ecommerce:filters', 'filters', $collection, $filters ),
			]
		);
	}

	private function blueprint( $seed, $name, array $nodes, array $connections ): array {
		return [ 'api_version' => BlueprintValidator::API_VERSION, 'kind' => BlueprintValidator::KIND, 'id' => $this->id( $seed . ':blueprint' ), 'name' => $name, 'version' => 1, 'nodes' => $nodes, 'connections' => $connections ];
	}

	private function field( $seed, $name, $type, array $overrides = [] ): array {
		return $this->fields->make( $this->id( $seed ), $name, $type, $overrides );
	}

	private function node( $seed, $type, $name, array $config ): array {
		$lanes = [ 'entity' => 'data', 'field_group' => 'data', 'relation' => 'data', 'entry_surface' => 'experience', 'collection' => 'experience', 'filter_surface' => 'experience', 'policy' => 'governance', 'adapter' => 'governance' ];
		return [ 'id' => $this->id( $seed ), 'type' => $type, 'lane' => $lanes[ $type ], 'name' => $name, 'config' => $config ];
	}

	private function entry_node( $seed, $name, array $field_ids, $title_field_id ): array {
		return $this->node( $seed, 'entry_surface', $name, [ 'operations' => [ 'create', 'update', 'archive', 'restore' ], 'initial_status' => 'draft', 'field_ids' => $field_ids, 'title_field_id' => $title_field_id, 'steps' => [], 'conditions' => [], 'actions' => [], 'guest' => [ 'enabled' => false ], 'autosave' => [ 'enabled' => true, 'interval_seconds' => 60 ] ] );
	}

	private function edge( $seed, $type, array $from, array $to ): array {
		return [ 'id' => $this->id( $seed . ':edge' ), 'type' => $type, 'from' => $from['id'], 'to' => $to['id'] ];
	}

	private function id( $seed ): string {
		return Uuid::v5( Uuid::LEGACY_NAMESPACE, 'sufficiency:' . $seed );
	}
}
