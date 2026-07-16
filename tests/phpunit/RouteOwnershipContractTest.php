<?php
/**
 * Route renderability and public-path ownership contracts.
 */

use EIT\Blueprint\RouteContractValidator;
use EIT\Blueprint\StorageOwnershipValidator;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use PHPUnit\Framework\TestCase;

class RouteOwnershipContractTest extends TestCase {

	public function test_route_accepts_exactly_one_runtime_source(): void {
		$document = $this->route_errors( [ 'template_id' => 41 ] );
		$entry = $this->route_errors( [], [ [ 'type' => 'presents_entry', 'from' => 'entry', 'to' => 'presentation' ] ] );

		self::assertNotContains( 'route_presentation_unrenderable', array_column( $document, 'code' ) );
		self::assertNotContains( 'route_presentation_unrenderable', array_column( $entry, 'code' ) );
	}

	public function test_route_rejects_unrenderable_multiple_and_mixed_sources(): void {
		$cases = [
			'empty' => [ [], [], 1 ],
			'two documents' => [ [ 'template_id' => 41, 'document_id' => 42 ], [], 1 ],
			'document and entry' => [ [ 'template_id' => 41 ], [ [ 'type' => 'presents_entry', 'from' => 'entry', 'to' => 'presentation' ] ], 1 ],
			'two connector sources' => [ [], [ [ 'type' => 'presents_entry', 'from' => 'entry', 'to' => 'presentation' ], [ 'type' => 'presents_collection', 'from' => 'collection', 'to' => 'presentation' ] ], 1 ],
			'entity source' => [ [], [ [ 'type' => 'presents_entity', 'from' => 'entity', 'to' => 'presentation' ] ], 1 ],
			'composed source' => [ [], [ [ 'type' => 'composes', 'from' => 'presentation', 'to' => 'other-presentation' ] ], 1 ],
			'multiple routed presentations' => [ [ 'template_id' => 41 ], [], 2 ],
		];

		foreach ( $cases as $label => [ $config, $sources, $route_edges ] ) {
			$codes = array_column( $this->route_errors( $config, $sources, $route_edges ), 'code' );
			self::assertContains( 'route_presentation_unrenderable', $codes, $label );
		}
	}

	public function test_public_virtual_path_is_unique_across_active_blueprints(): void {
		$owner = 'blueprint-owner';
		$contender = 'blueprint-contender';
		$blueprints = new RouteOwnershipBlueprintStore( [ [ 'id' => $owner, 'active_version_id' => 7 ] ] );
		$artifacts = new RouteOwnershipArtifactStore( [ 7 => [ $this->route_artifact( 'owner-route', 'Clinic/Team' ) ] ] );

		$blockers = ( new StorageOwnershipValidator( $blueprints, $artifacts ) )->blockers(
			$contender,
			[ $this->route_artifact( 'contender-route', '/clinic/team/' ) ]
		);

		self::assertContains( 'route_path_owned', array_column( $blockers, 'code' ) );
		self::assertSame( $owner, $blockers[0]['owner']['blueprint_id'] );
	}

	public function test_reserved_wordpress_route_namespaces_are_blocked(): void {
		$artifacts = [];
		foreach ( [ 'wp-admin/toolkit', 'wp-json/eit/v1', 'feed/rss2', 'author/editor', 'search/toolkit' ] as $offset => $path ) {
			$artifacts[] = $this->route_artifact( 'reserved-' . $offset, $path );
		}

		$blockers = ( new StorageOwnershipValidator( new RouteOwnershipBlueprintStore( [] ), new RouteOwnershipArtifactStore( [] ) ) )->blockers( 'contender', $artifacts );

		self::assertCount( 5, array_filter( $blockers, fn( $blocker ) => 'route_path_reserved' === $blocker['code'] ) );
	}

	public function test_wordpress_pages_post_types_and_taxonomies_own_exact_public_paths(): void {
		$validator = $this->wordpress_validator(
			[ 'about/team' => (object) [ 'ID' => 42, 'post_status' => 'publish' ] ],
			[ (object) [ 'name' => 'property', 'rewrite' => [ 'slug' => 'properties' ], 'has_archive' => 'property-archive' ] ],
			[ (object) [ 'name' => 'specialty', 'rewrite' => [ 'slug' => 'specialties' ] ] ]
		);
		$artifacts = [
			$this->route_artifact( 'page-route', 'about/team' ),
			$this->route_artifact( 'rewrite-route', 'properties' ),
			$this->route_artifact( 'archive-route', 'property-archive' ),
			$this->route_artifact( 'taxonomy-route', 'specialties' ),
		];

		$blockers = $validator->blockers( 'contender', $artifacts );
		$by_path = array_column( $blockers, 'code', 'route_path' );

		self::assertSame( 'route_path_wordpress_page', $by_path['about/team'] );
		self::assertSame( 'route_path_wordpress_post_type', $by_path['properties'] );
		self::assertSame( 'route_path_wordpress_post_type', $by_path['property-archive'] );
		self::assertSame( 'route_path_wordpress_taxonomy', $by_path['specialties'] );
	}

	public function test_wordpress_route_ownership_is_exact_and_public_virtual_only(): void {
		$validator = $this->wordpress_validator(
			[ 'about/team' => (object) [ 'ID' => 42, 'post_status' => 'publish' ] ],
			[ (object) [ 'name' => 'property', 'rewrite' => [ 'slug' => 'properties' ], 'has_archive' => true ] ],
			[ (object) [ 'name' => 'specialty', 'rewrite' => [ 'slug' => 'specialties' ] ] ]
		);
		$artifacts = [
			$this->route_artifact( 'page-prefix', 'about/team-members' ),
			$this->route_artifact( 'post-type-child', 'properties/map' ),
			$this->route_artifact( 'taxonomy-neighbor', 'specialty' ),
			$this->route_artifact( 'internal-route', 'about/team', 'internal' ),
			$this->route_artifact( 'existing-document', 'https://example.test/about/team', 'public', 'existing_document' ),
		];

		self::assertSame( [], $validator->blockers( 'contender', $artifacts ) );
	}

	private function route_errors( array $presentation_config, array $sources = [], int $route_edges = 1 ): array {
		$nodes = [
			'route' => [ 'type' => 'route', 'config' => [ 'path' => 'system/view', 'exposure' => 'public' ] ],
			'presentation' => [ 'type' => 'presentation', 'config' => $presentation_config ],
			'other-presentation' => [ 'type' => 'presentation', 'config' => [] ],
		];
		$connections = $sources;
		for ( $index = 0; $index < $route_edges; ++$index ) {
			$connections[] = [ 'type' => 'routes', 'from' => 'presentation', 'to' => 'route' ];
		}
		return ( new RouteContractValidator() )->validate( $nodes, $connections );
	}

	private function wordpress_validator( array $pages, array $post_types, array $taxonomies ): RouteOwnershipWordPressValidator {
		return new RouteOwnershipWordPressValidator(
			new RouteOwnershipBlueprintStore( [] ),
			new RouteOwnershipArtifactStore( [] ),
			$pages,
			$post_types,
			$taxonomies
		);
	}

	private function route_artifact( string $node_id, string $path, string $exposure = 'public', string $kind = 'virtual' ): array {
		return [
			'kind' => 'route_contract',
			'node_id' => $node_id,
			'payload' => [
				'route_id' => $node_id,
				'path' => $path,
				'kind' => $kind,
				'exposure' => $exposure,
			],
		];
	}
}

class RouteOwnershipBlueprintStore extends BlueprintStore {
	private $records;

	public function __construct( array $records ) {
		$this->records = $records;
	}

	public function all() {
		return $this->records;
	}
}

class RouteOwnershipArtifactStore extends ArtifactStore {
	private $records;

	public function __construct( array $records ) {
		$this->records = $records;
	}

	public function for_version( $version_id, $kind = null ) {
		$records = $this->records[ $version_id ] ?? [];
		return null === $kind ? $records : array_values( array_filter( $records, fn( $artifact ) => $kind === ( $artifact['kind'] ?? '' ) ) );
	}
}

class RouteOwnershipWordPressValidator extends StorageOwnershipValidator {
	private $pages;
	private $post_types;
	private $taxonomies;

	public function __construct( BlueprintStore $blueprints, ArtifactStore $artifacts, array $pages, array $post_types, array $taxonomies ) {
		parent::__construct( $blueprints, $artifacts );
		$this->pages = $pages;
		$this->post_types = $post_types;
		$this->taxonomies = $taxonomies;
	}

	protected function wordpress_page( $path ) {
		return $this->pages[ $path ] ?? null;
	}

	protected function public_post_types() {
		return $this->post_types;
	}

	protected function public_taxonomies() {
		return $this->taxonomies;
	}
}
