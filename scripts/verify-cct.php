<?php
/**
 * Destructive verification against a disposable CCT definition.
 *
 * Run with:
 * wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-cct.php
 */

use EIT\CCT\CurrentItemContext;
use EIT\CCT\DefinitionManager;
use EIT\CCT\Repository;
use EIT\CCT\SchemaManager;
use EIT\Blueprint\MigrationStorageLockCoordinator;
use EIT\Blueprint\StorageMutationGuard;
use EIT\Entry\EntryStorageGateway;
use EIT\Infrastructure\LockStore;
use EIT\Infrastructure\Tables;
use EIT\Rest\FilterRequestPolicy;
use EIT\Support\FilterPresets;
use EIT\Support\FilterResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eit_cct_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function eit_filter_policy_request( $payload ) {
	$request = new WP_REST_Request( 'POST', '/eit/v1/filter' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( is_string( $payload ) ? $payload : wp_json_encode( $payload ) );
	return $request;
}

$type = 'eit_cct_qa';
$repository = new Repository();

if ( DefinitionManager::get( $type ) ) {
	DefinitionManager::archive( $type );
	DefinitionManager::delete_permanently( $type );
} elseif ( SchemaManager::table_exists( $type ) ) {
	SchemaManager::drop_table( $type );
}

$created = DefinitionManager::save(
	[
		'slug'     => $type,
		'singular' => 'QA Item',
		'plural'   => 'QA Items',
		'public'   => true,
		'fields'   => [
			[ 'key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'filterable' => true, 'options' => "plugin | Plugin\nwebsite | Website" ],
			[ 'key' => 'score', 'label' => 'Score', 'type' => 'number', 'filterable' => true ],
			[ 'key' => 'notes', 'label' => 'Notes', 'type' => 'textarea' ],
		],
	]
);
eit_cct_assert( ! is_wp_error( $created ), 'Could not publish the disposable definition.' );

$table = SchemaManager::table_name( $type );
$indexes = $GLOBALS['wpdb']->get_col( "SHOW INDEX FROM `{$table}`", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
eit_cct_assert( in_array( 'eit_f_score', $indexes, true ), 'Filterable score index was not created.' );

$first_id = $repository->save( $type, [ 'title' => 'Alpha', 'kind' => 'plugin', 'score' => 8, 'notes' => 'retained' ] );
$second_id = $repository->save( $type, [ 'title' => 'Beta', 'kind' => 'website', 'score' => 4 ] );
eit_cct_assert( ! is_wp_error( $first_id ) && ! is_wp_error( $second_id ), 'Could not insert disposable rows.' );

$resource = StorageMutationGuard::resource_key( 'cct', $type );
StorageMutationGuard::shared()->release_all();
$GLOBALS['wpdb']->delete( Tables::name( Tables::LOCKS ), [ 'resource_key' => $resource ] );
$writer_prefix = $GLOBALS['wpdb']->esc_like( StorageMutationGuard::writer_prefix( 'cct', $type ) ) . '%';
$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DELETE FROM `' . Tables::name( Tables::LOCKS ) . '` WHERE resource_key LIKE %s', $writer_prefix ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$secondary = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$secondary->set_prefix( $GLOBALS['wpdb']->prefix );
$secondary->suppress_errors( true );
$with_secondary = function ( callable $callback ) use ( $secondary ) {
	global $wpdb;
	$primary = $wpdb;
	$wpdb = $secondary;
	try {
		return $callback();
	} finally {
		$wpdb = $primary;
	}
};
$barrier_result = null;
$barrier = function () use ( &$barrier_result, $type, $with_secondary ) {
	$barrier_result = $with_secondary( fn() => ( new MigrationStorageLockCoordinator( null, new LockStore() ) )->acquire_storage( 'cct', $type, 0 ) );
};
$barrier_repository = new class( StorageMutationGuard::shared(), $barrier ) extends Repository {
	private $barrier;

	public function __construct( StorageMutationGuard $guard, callable $barrier ) {
		parent::__construct( null, null, $guard );
		$this->barrier = $barrier;
	}

	public function save( $type, array $values, $id = 0 ) {
		$result = parent::save( $type, $values, $id );
		if ( ! is_wp_error( $result ) ) {
			call_user_func( $this->barrier );
		}
		return $result;
	}
};
$lease_contract = [
	'blueprint_id' => 'cct-lease-integration',
	'entity' => [ 'strategy' => 'cct', 'definition' => [ 'slug' => $type ] ],
	'fields' => [ [ 'id' => 'lease-title', 'type' => 'short_text', 'storage' => [ 'key' => 'kind' ] ] ],
	'title_field_id' => 'lease-title',
];
$lease_record_id = ( new EntryStorageGateway( $barrier_repository ) )->save( $lease_contract, [ 'lease-title' => 'Lease boundary' ], 0, 'publish', 1 );
eit_cct_assert( ! is_wp_error( $lease_record_id ), 'CCT Entry write failed during the lease boundary proof.' );
eit_cct_assert( is_wp_error( $barrier_result ) && 'eit_migration_storage_locked' === $barrier_result->get_error_code(), 'A migration gate ignored the active CCT writer lease.' );
$after_commit_leases = $with_secondary( fn() => ( new MigrationStorageLockCoordinator( null, new LockStore() ) )->acquire_storage( 'cct', $type, 0 ) );
eit_cct_assert( is_array( $after_commit_leases ) && 1 === count( $after_commit_leases ), 'The CCT writer lease was not released after the outer Entry transaction committed.' );
eit_cct_assert( true === $with_secondary( fn() => ( new MigrationStorageLockCoordinator( null, new LockStore() ) )->release( $after_commit_leases ) ), 'The second connection could not release its verification gate.' );
$secondary->close();
eit_cct_assert( true === $repository->delete( $type, $lease_record_id ), 'The CCT lease verification row could not be removed.' );

$filtered = $repository->query(
	$type,
	[
		'filters'  => [ [ 'key' => 'score', 'value' => 5, 'compare' => 'gte' ] ],
		'per_page' => 10,
	]
);
eit_cct_assert( 1 === $filtered['total'] && 'Alpha' === $filtered['items'][0]['title'], 'Typed filtering failed.' );

$offset = $repository->query( $type, [ 'offset' => 1, 'per_page' => 1, 'page' => 9 ] );
eit_cct_assert( 1 === $offset['total'] && 1 === $offset['pages'] && 'Beta' === $offset['items'][0]['title'], 'Offset pagination failed.' );

$zero_definition = DefinitionManager::get( $type );
$zero_definition['original_slug'] = $type;
$zero_definition['fields'][] = [ 'key' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'required' => true, 'filterable' => true ];
$zero_definition_saved = DefinitionManager::save( $zero_definition );
eit_cct_assert( ! is_wp_error( $zero_definition_saved ), 'Could not add the required numeric field.' );
$zero_id = $repository->save( $type, [ 'title' => 'Zero', 'kind' => 'plugin', 'score' => 0, 'quantity' => '0' ] );
eit_cct_assert( ! is_wp_error( $zero_id ), 'The required string zero was treated as empty.' );

$draft_id = $repository->save( $type, [ 'title' => 'Hidden Draft', 'status' => 'draft', 'kind' => 'plugin', 'score' => 1, 'quantity' => 1 ] );
eit_cct_assert( ! is_wp_error( $draft_id ), 'Could not create the draft leakage fixture.' );
$public_result = $repository->query_public( $type, [ 'per_page' => 20 ] );
eit_cct_assert( ! in_array( $draft_id, wp_list_pluck( $public_result['items'], 'id' ), true ), 'A draft leaked through the public query contract.' );

$before_invalid = DefinitionManager::get( $type );
$duplicate = $before_invalid;
$duplicate['original_slug'] = $type;
$duplicate['fields'][] = [ 'key' => 'score', 'label' => 'Duplicate score', 'type' => 'number' ];
eit_cct_assert( is_wp_error( DefinitionManager::save( $duplicate ) ), 'A duplicate field key was accepted.' );
eit_cct_assert( $before_invalid === DefinitionManager::get( $type ), 'A rejected definition changed the published option.' );

$reserved = $before_invalid;
$reserved['original_slug'] = $type;
$reserved['fields'][] = [ 'key' => 'status', 'label' => 'Reserved status', 'type' => 'text' ];
eit_cct_assert( is_wp_error( DefinitionManager::save( $reserved ) ), 'A reserved field key was accepted.' );

$too_many_fields = $before_invalid;
$too_many_fields['original_slug'] = $type;
$too_many_fields['fields'] = [];
for ( $field_index = 0; $field_index <= DefinitionManager::MAX_FIELDS; ++$field_index ) {
	$too_many_fields['fields'][] = [ 'key' => 'field_' . $field_index, 'label' => 'Field ' . $field_index, 'type' => 'text' ];
}
eit_cct_assert( is_wp_error( DefinitionManager::save( $too_many_fields ) ), 'A definition silently truncated fields beyond its published limit.' );

$type_change = $before_invalid;
$type_change['original_slug'] = $type;
foreach ( $type_change['fields'] as &$field ) {
	if ( 'score' === $field['key'] ) {
		$field['type'] = 'text';
	}
}
unset( $field );
eit_cct_assert( is_wp_error( DefinitionManager::save( $type_change ) ), 'A published storage type changed without a migration plan.' );

$slug_change = $before_invalid;
$slug_change['original_slug'] = $type;
$slug_change['slug'] = $type . '_renamed';
eit_cct_assert( is_wp_error( DefinitionManager::save( $slug_change ) ), 'A published CCT slug changed without a migration plan.' );

$resolver_result = ( new FilterResolver() )->resolve(
	[
		'items'   => [ [ 'clientId' => 'one', 'data' => [ 'kind' => 'plugin-pro' ], 'classes' => [], 'title' => 'One', 'text' => '' ] ],
		'filters' => [ [ 'type' => 'select', 'key' => 'kind', 'value' => 'plugin' ] ],
	]
);
eit_cct_assert( 0 === $resolver_result['total'], 'Legacy DOM token matching still accepts substrings.' );

$policy = new FilterRequestPolicy();
$too_many_items = eit_filter_policy_request( [ 'items' => array_fill( 0, FilterRequestPolicy::MAX_DOM_ITEMS + 1, [ 'clientId' => 'item' ] ) ] );
eit_cct_assert( is_wp_error( $policy->validate( $too_many_items ) ), 'The REST policy accepted more than 200 DOM items.' );

$too_many_filters = eit_filter_policy_request( [ 'filters' => array_fill( 0, FilterRequestPolicy::MAX_FILTERS + 1, [ 'type' => 'search', 'value' => 'x' ] ) ] );
eit_cct_assert( is_wp_error( $policy->validate( $too_many_filters ) ), 'The REST policy accepted more than 20 filters.' );

$too_large = eit_filter_policy_request( str_repeat( 'x', FilterRequestPolicy::MAX_BODY_BYTES + 1 ) );
$too_large_result = $policy->validate( $too_large );
eit_cct_assert( is_wp_error( $too_large_result ) && 'eit_request_too_large' === $too_large_result->get_error_code(), 'The REST policy did not enforce the 32 KB body limit first.' );

$invalid_page_size = eit_filter_policy_request( [ 'perPage' => FilterRequestPolicy::MAX_PER_PAGE + 1 ] );
$invalid_page_size_result = $policy->validate( $invalid_page_size );
eit_cct_assert( is_wp_error( $invalid_page_size_result ) && 'eit_invalid_page_size' === $invalid_page_size_result->get_error_code(), 'The REST policy accepted more than 48 items per page.' );

$expensive_filters = [];
for ( $filter_index = 0; $filter_index < FilterRequestPolicy::MAX_FILTERS; ++$filter_index ) {
	$expensive_filters[] = [ 'type' => 'checkbox', 'value' => array_fill( 0, 25, 'selected' ) ];
}
$expensive = eit_filter_policy_request( [ 'provider' => 'cct', 'filters' => $expensive_filters ] );
$expensive_result = $policy->validate( $expensive );
eit_cct_assert( is_wp_error( $expensive_result ) && 'eit_request_cost_exceeded' === $expensive_result->get_error_code(), 'The REST policy accepted a request above its computed cost limit.' );

$bounded = $policy->validate( eit_filter_policy_request( [] ) );
eit_cct_assert( FilterRequestPolicy::DEFAULT_PER_PAGE === $bounded['perPage'] && 0 < $bounded['_eitRequestCost'], 'The REST policy did not apply its bounded defaults and cost.' );

$preset_limit_id = 'eit_qa_filter_limit';
FilterPresets::delete( $preset_limit_id );
$preset_limit = FilterPresets::save(
	[
		'id'      => $preset_limit_id,
		'name'    => 'QA filter limit',
		'filters' => array_fill( 0, FilterPresets::MAX_FILTERS + 1, [ 'enabled' => true, 'type' => 'search', 'label' => 'Search' ] ),
	]
);
eit_cct_assert( is_wp_error( $preset_limit ) && null === FilterPresets::get( $preset_limit_id ), 'A preset silently truncated filters beyond its public request limit.' );

DefinitionManager::save(
	[
		'original_slug' => $type,
		'slug'          => $type,
		'singular'      => 'QA Item',
		'plural'        => 'QA Items',
		'public'        => true,
		'fields'        => [
			[ 'key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'filterable' => true, 'options' => "plugin | Plugin\nwebsite | Website" ],
			[ 'key' => 'score', 'label' => 'Score', 'type' => 'number', 'filterable' => true ],
			[ 'key' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'required' => true, 'filterable' => true ],
		],
	]
);
$definition = DefinitionManager::get( $type );
$notes = wp_list_filter( $definition['fields'], [ 'key' => 'notes' ] );
eit_cct_assert( 1 === count( $notes ) && empty( reset( $notes )['active'] ), 'Removed field was not retained as inactive.' );
eit_cct_assert( 'retained' === $repository->get( $type, $first_id )['notes'], 'Inactive field data was not retained.' );

DefinitionManager::archive( $type );
eit_cct_assert( null === DefinitionManager::get( $type, false ), 'Archived definition remained publicly active.' );
eit_cct_assert( null !== $repository->get( $type, $first_id ), 'Archived definition lost its rows.' );
DefinitionManager::restore( $type );

CurrentItemContext::push( $type, [ 'id' => $first_id, 'title' => 'Outer' ] );
CurrentItemContext::push( $type, [ 'id' => $second_id, 'title' => 'Inner' ] );
eit_cct_assert( 'Inner' === CurrentItemContext::item()['title'], 'Nested context did not expose the inner item.' );
CurrentItemContext::pop();
eit_cct_assert( 'Outer' === CurrentItemContext::item()['title'], 'Nested context did not restore the outer item.' );
CurrentItemContext::clear();

DefinitionManager::archive( $type );
eit_cct_assert( DefinitionManager::delete_permanently( $type ), 'Permanent deletion failed for archived definition.' );
eit_cct_assert( null === DefinitionManager::get( $type ), 'Definition survived permanent deletion.' );
eit_cct_assert( $table !== $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Table survived permanent deletion.' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( 'CCT atomic schema, cross-connection write lease, stable identities, public status, request bounds, exact matching, required zero, persistence, and nested context verified.' );
}
