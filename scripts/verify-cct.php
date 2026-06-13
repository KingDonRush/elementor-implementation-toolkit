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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eit_cct_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$type = 'eit_cct_qa';
$repository = new Repository();

if ( DefinitionManager::get( $type ) ) {
	DefinitionManager::archive( $type );
	DefinitionManager::delete_permanently( $type );
}

DefinitionManager::save(
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

$first_id = $repository->save( $type, [ 'title' => 'Alpha', 'kind' => 'plugin', 'score' => 8, 'notes' => 'retained' ] );
$second_id = $repository->save( $type, [ 'title' => 'Beta', 'kind' => 'website', 'score' => 4 ] );
eit_cct_assert( ! is_wp_error( $first_id ) && ! is_wp_error( $second_id ), 'Could not insert disposable rows.' );

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

$table = SchemaManager::table_name( $type );
DefinitionManager::archive( $type );
eit_cct_assert( DefinitionManager::delete_permanently( $type ), 'Permanent deletion failed for archived definition.' );
eit_cct_assert( null === DefinitionManager::get( $type ), 'Definition survived permanent deletion.' );
eit_cct_assert( $table !== $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Table survived permanent deletion.' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( 'CCT lifecycle, persistence, filtering, pagination, and nested context verified.' );
}
