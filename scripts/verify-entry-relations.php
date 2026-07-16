<?php
/**
 * WordPress integration proof for Collection-backed Entry relation pickers.
 *
 * Run with:
 * wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-entry-relations.php
 */

use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\Uuid;
use EIT\Collection\CollectionAccessPolicy;
use EIT\Collection\CollectionQueryService;
use EIT\Collection\CollectionRequestValidator;
use EIT\Collection\CptCollectionProvider;
use EIT\Entry\EntryContractPresenter;
use EIT\Entry\EntryFieldRenderer;
use EIT\Entry\EntryReferenceValidator;
use EIT\Entry\RelationLabelResolver;
use EIT\Infrastructure\NormalizedValueStore;
use EIT\Infrastructure\SchemaManager;
use EIT\Infrastructure\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$assertions = 0;
$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$ids = [
	'blueprint' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry-relation:blueprint' ),
	'collection' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry-relation:collection' ),
	'entity' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry-relation:entity' ),
	'surface' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry-relation:surface' ),
	'field' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry-relation:field' ),
	'label' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry-relation:label' ),
];
$posts = [];
$cleanup = function () use ( $ids, &$posts ) {
	global $wpdb;
	$wpdb->delete( Tables::name( Tables::RELATIONS ), [ 'blueprint_id' => $ids['blueprint'] ] );
	$stale = get_posts(
		[
			'post_type' => 'post',
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_key' => '_eit_verify_entry_relation',
			'meta_value' => $ids['blueprint'],
		]
	);
	foreach ( array_unique( array_merge( $posts, $stale ) ) as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	$posts = [];
};

try {
	$cleanup();
	$assert( true === SchemaManager::install(), 'Toolkit infrastructure must install for relation verification.' );
	$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
	$assert( ! empty( $admin[0] ), 'An administrator is required for relation verification.' );
	$prefix = 'EIT relation ' . substr( $ids['blueprint'], 0, 8 );
	foreach ( [ 'Ana', 'Bruno' ] as $name ) {
		$posts[] = wp_insert_post(
			[
				'post_type' => 'post',
				'post_status' => 'publish',
				'post_title' => $prefix . ' ' . $name,
				'post_author' => (int) $admin[0],
				'meta_input' => [ '_eit_verify_entry_relation' => $ids['blueprint'] ],
			]
		);
	}
	$posts[] = wp_insert_post(
		[
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => $prefix . ' Draft leak',
			'post_author' => (int) $admin[0],
			'meta_input' => [ '_eit_verify_entry_relation' => $ids['blueprint'] ],
		]
	);
	$assert( ! array_filter( $posts, fn( $post_id ) => ! is_int( $post_id ) || $post_id < 1 ), 'Relation target fixtures must be created.' );

	$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
	$label_field = $factory->make(
		$ids['label'],
		'Public label',
		'short_text',
		[ 'exposure' => [ 'public' => true ], 'indexing' => [ 'search' => true ] ]
	);
	$provider = new CptCollectionProvider();
	$collection = [
		'blueprint_id' => $ids['blueprint'],
		'version_id' => 1,
		'artifact_checksum' => hash( 'sha256', $ids['collection'] ),
		'collection_id' => $ids['collection'],
		'entity_id' => $ids['entity'],
		'entity' => [ 'strategy' => 'cpt', 'definition' => [ 'slug' => 'post', 'public' => true ], 'adapter' => [] ],
		'provider' => [ 'id' => $provider->get_id(), 'version' => $provider->get_version(), 'capabilities' => $provider->get_capabilities(), 'required_capabilities' => [ 'pagination', 'search' ] ],
		'fields' => [ $label_field ],
		'projection_field_ids' => [ $ids['label'] ],
		'filter_field_ids' => [],
		'sort_field_ids' => [],
		'search_field_ids' => [ $ids['label'] ],
		'default_sort' => [],
		'page_size' => 1,
		'access' => 'authenticated',
		'policy' => [ 'capability' => 'edit_posts', 'ownership' => 'any', 'object_scope' => 'entity' ],
		'cache' => [ 'enabled' => false ],
		'filter_surface' => [ 'facet_field_ids' => [] ],
		'explain' => false,
	];
	wp_set_current_user( 0 );
	$denied = ( new CollectionAccessPolicy() )->authorize( $collection );
	$assert( is_wp_error( $denied ) && 401 === $denied->get_error_data()['status'], 'Anonymous relation options bypassed Collection authentication.' );
	wp_set_current_user( (int) $admin[0] );
	$assert( true === ( new CollectionAccessPolicy() )->authorize( $collection ), 'Authorized implementer could not read relation options.' );
	$request = ( new CollectionRequestValidator() )->validate(
		$collection,
		[ 'page' => 1, 'per_page' => 1, 'search' => $prefix, 'filters' => [], 'facets' => [] ]
	);
	$assert( ! is_wp_error( $request ), 'Closed Collection relation query was rejected.' );
	$page_one = ( new CollectionQueryService() )->execute( $collection, $request );
	$request['page'] = 2;
	$page_two = ( new CollectionQueryService() )->execute( $collection, $request );
	$assert( 2 === $page_one['pagination']['total'] && 2 === $page_one['pagination']['pages'], 'Relation options did not paginate the authorized Collection.' );
	$assert( 2 === count( array_unique( [ $page_one['items'][0]['id'], $page_two['items'][0]['id'] ] ) ), 'Relation option pages repeated the same target.' );
	$assert( false === strpos( wp_json_encode( [ $page_one, $page_two ] ), 'Draft leak' ), 'A draft target leaked into relation options.' );

	$relation_field = $factory->make( $ids['field'], 'Responsible agent', 'relation' );
	$relation_field['relation'] = [
		'id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:entry-relation:node' ),
		'cardinality' => 'many_to_one',
		'target_entity_id' => $ids['entity'],
		'options_collection_id' => $ids['collection'],
		'search_enabled' => true,
		'target' => [ 'strategy' => 'cpt', 'definition' => [ 'slug' => 'post', 'public' => true ], 'adapter' => [] ],
		'ownership' => 'any',
		'object_scope' => 'entity',
	];
	$loaded_value = [ [ 'target_id' => (string) $posts[0], 'position' => 0, 'payload' => [ 'private' => 'must-not-project' ] ] ];
	$entry = [
		'blueprint_id' => $ids['blueprint'],
		'surface_id' => $ids['surface'],
		'name' => 'Relation workspace',
		'version_id' => 1,
		'entity' => [ 'strategy' => 'cpt', 'mode' => 'structured', 'definition' => [ 'singular' => 'Item' ], 'adapter' => [] ],
		'fields' => [ $relation_field ],
		'title_field_id' => '',
		'groups' => [],
		'steps' => [],
		'conditions' => [],
		'workflow' => [ 'operations' => [] ],
		'autosave' => [ 'enabled' => false ],
		'guest' => [ 'enabled' => false ],
	];
	$public = ( new EntryContractPresenter() )->present( $entry, [ 'item' => [ 'id' => 0 ], 'values' => [ $ids['field'] => $loaded_value ] ] );
	$encoded = wp_json_encode( $public );
	$assert( (string) $posts[0] === $public['values'][ $ids['field'] ], 'Loaded target identity did not project into the to-one control.' );
	$assert( $prefix . ' Ana' === $public['fields'][0]['validation']['options'][0]['label'], 'Selected relation label did not resolve through the authorized target contract.' );
	$assert( false === strpos( $encoded, 'target_id' ) && false === strpos( $encoded, 'must-not-project' ), 'Relation persistence internals leaked into the browser contract.' );

	ob_start();
	( new EntryFieldRenderer() )->render( $public['fields'][0], $public['values'][ $ids['field'] ], 'integration' );
	$to_one_html = ob_get_clean();
	$relation_field['relation']['cardinality'] = 'one_to_many';
	$relation_field['validation']['options'] = $public['fields'][0]['validation']['options'];
	ob_start();
	( new EntryFieldRenderer() )->render( $relation_field, [ (string) $posts[0] ], 'integration-many' );
	$to_many_html = ob_get_clean();
	$assert( false === strpos( preg_replace( '/<option.*?<\/option>/s', '', $to_one_html ), ' multiple' ) && false !== strpos( $to_one_html, 'selected=' ), 'Many-to-one relation did not render one preserved selection.' );
	$assert( false !== strpos( $to_many_html, ' multiple' ), 'One-to-many relation did not render a multi-value control.' );

	$normalized = new NormalizedValueStore();
	$assert( true === $normalized->replace_relation_targets( $ids['blueprint'], $ids['field'], '101', [ [ 'id' => $posts[0] ] ], true ), 'Unique target fixture did not persist.' );
	$relation_field['relation']['cardinality'] = 'one_to_many';
	$references = new EntryReferenceValidator();
	$conflict = $references->validate( [ 'blueprint_id' => $ids['blueprint'], 'fields' => [ $relation_field ] ], [ $ids['field'] => [ [ 'id' => $posts[0] ] ] ], false, 202 );
	$same_source = $references->validate( [ 'blueprint_id' => $ids['blueprint'], 'fields' => [ $relation_field ] ], [ $ids['field'] => [ [ 'id' => $posts[0] ] ] ], false, 101 );
	$assert( is_wp_error( $conflict ) && 'eit_entry_reference_forbidden' === $conflict->get_error_code(), 'One-to-many target uniqueness was not enforced before persistence.' );
	$assert( true === $same_source, 'Editing the existing source incorrectly rejected its preserved unique target.' );

	echo 'Entry relation verification passed: ' . $assertions . " assertions.\n";
} finally {
	$cleanup();
}
