<?php
/**
 * Entity-context and connector-pairing contracts for Elementor.
 */

use EIT\Elementor\Contracts\ElementorContextResolver;
use EIT\Elementor\Contracts\PublishedContractCatalog;
use EIT\Elementor\FilterController\CollectionSurfacePairing;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use PHPUnit\Framework\TestCase;

class ElementorContextPairingContractTest extends TestCase {

	public function test_theme_builder_preview_resolves_public_document_settings(): void {
		$post_types = [ 10 => 'elementor_library', 22 => 'book' ];
		$resolver = new ElementorContextResolver(
			fn() => '',
			fn() => 10,
			fn( $post_id ) => $post_types[ $post_id ] ?? '',
			fn() => [ 'preview_id' => 22, 'preview_type' => 'single/clinic' ]
		);

		self::assertSame( 'book', $resolver->type() );
	}

	public function test_theme_builder_single_type_is_used_without_a_preview_item(): void {
		$resolver = new ElementorContextResolver(
			fn() => '',
			fn() => 10,
			fn() => 'elementor_library',
			fn() => [ 'preview_type' => 'single/clinic' ]
		);
		self::assertSame( 'clinic', $resolver->type() );

		$archive = new ElementorContextResolver(
			fn() => '',
			fn() => 10,
			fn() => 'elementor_library',
			fn() => [ 'preview_type' => 'archive/clinic' ]
		);
		self::assertSame( '', $archive->type() );
	}

	public function test_published_fields_are_limited_to_the_resolved_entity(): void {
		$catalog = $this->catalog( 'book' );
		$options = $catalog->field_options( [ 'text' ] );

		self::assertArrayHasKey( 'book-title', $options );
		self::assertArrayNotHasKey( 'clinic-name', $options );
		self::assertNotNull( $catalog->field( 'book-title' ) );
		self::assertNull( $catalog->field( 'clinic-name' ) );
	}

	public function test_ambiguous_context_requires_an_explicit_entity(): void {
		$catalog = $this->catalog( '' );

		self::assertSame( [ '' => 'Select a published Field' ], $catalog->field_options( [ 'text' ] ) );
		self::assertArrayHasKey( 'book-title', $catalog->field_options( [ 'text' ], 'book-entity' ) );
		self::assertArrayNotHasKey( 'clinic-name', $catalog->field_options( [ 'text' ], 'book-entity' ) );
		self::assertNull( $catalog->field( 'book-title' ) );
		self::assertNotNull( $catalog->field( 'book-title', 'book-entity' ) );

		$editor_options = $catalog->editor_field_options( [ 'text' ] );
		self::assertStringContainsString( 'Books', $editor_options['book-title'] );
		self::assertStringContainsString( 'Clinics', $editor_options['clinic-name'] );
	}

	public function test_duplicate_entity_slugs_remain_ambiguous(): void {
		$catalog = $this->catalog( 'book', true );

		self::assertSame( '', $catalog->context_entity_id() );
		self::assertSame( [ '' => 'Select a published Field' ], $catalog->field_options( [ 'text' ] ) );
	}

	public function test_collection_surface_is_the_authoritative_single_page_binding(): void {
		$pairing = new CollectionSurfacePairing();
		$elements = $this->elements( [ [ 'widget-a', 'collection-a' ] ] );

		self::assertSame(
			[ 'collection_id' => 'collection-a', 'widget_id' => 'widget-a', 'mode' => 'document' ],
			$pairing->resolve( $elements )
		);
		self::assertSame( 'eit_collection_pair_mismatch', $pairing->resolve( $elements, 'collection-b' )->get_error_code() );
	}

	public function test_multiple_collection_surfaces_require_exact_disambiguation(): void {
		$pairing = new CollectionSurfacePairing();
		$elements = $this->elements( [ [ 'widget-a', 'collection-a' ], [ 'widget-b', 'collection-b' ] ] );

		self::assertSame( 'eit_collection_pair_ambiguous', $pairing->resolve( $elements )->get_error_code() );
		self::assertSame( 'widget-b', $pairing->resolve( $elements, 'collection-b' )['widget_id'] );
		self::assertSame( 'eit_collection_pair_mismatch', $pairing->resolve( $elements, 'collection-c' )->get_error_code() );
	}

	public function test_legacy_binding_survives_when_document_data_is_unavailable(): void {
		$pair = ( new CollectionSurfacePairing() )->resolve( [], 'legacy-collection' );

		self::assertSame( 'legacy-collection', $pair['collection_id'] );
		self::assertSame( 'compatibility', $pair['mode'] );
	}

	private function catalog( $context_type, $duplicate_slug = false ): PublishedContractCatalog {
		$blueprints = new class() extends BlueprintStore {
			public function all() {
				return [
					[ 'id' => 'blueprint-a', 'active_version_id' => 1 ],
					[ 'id' => 'blueprint-b', 'active_version_id' => 2 ],
					[ 'id' => 'blueprint-c', 'active_version_id' => 3 ],
				];
			}
		};
		$artifacts = new class( $duplicate_slug ) extends ArtifactStore {
			private $duplicate_slug;

			public function __construct( $duplicate_slug ) {
				$this->duplicate_slug = $duplicate_slug;
			}

			public function for_version( $version_id, $kind = null ) {
				if ( 'field_contracts' === $kind ) {
					return [];
				}
				$definitions = [
					1 => $this->artifact( 'book-entity', 'Books', 'book', 'book-title', 'Title' ),
					2 => $this->artifact( 'clinic-entity', 'Clinics', 'clinic', 'clinic-name', 'Name' ),
					3 => $this->artifact( 'book-copy', 'Book copies', $this->duplicate_slug ? 'book' : 'copy', 'copy-title', 'Copy title' ),
				];
				return 'entity_definition' === $kind ? [ $definitions[ $version_id ] ] : [];
			}

			private function artifact( $entity_id, $name, $slug, $field_id, $field_name ) {
				return [
					'node_id' => $entity_id,
					'payload' => [
						'entity_id' => $entity_id,
						'name' => $name,
						'strategy' => 'cpt',
						'definition' => [ 'slug' => $slug ],
						'adapter' => [ 'id' => 'core' ],
						'fields' => [ [ 'id' => $field_id, 'name' => $field_name, 'elementor' => [ 'text' ] ] ],
					],
				];
			}
		};
		$context = new ElementorContextResolver( fn() => $context_type, fn() => 0, fn() => '', fn() => null );
		return new PublishedContractCatalog( $blueprints, $artifacts, $context );
	}

	private function elements( array $collections ): array {
		$widgets = array_map(
			fn( $item ) => [
				'id' => $item[0],
				'elType' => 'widget',
				'widgetType' => 'eit-toolkit-collection-surface',
				'settings' => [ 'collection_id' => $item[1] ],
				'elements' => [],
			],
			$collections
		);
		return [ [ 'id' => 'container', 'elType' => 'container', 'elements' => $widgets ] ];
	}
}
