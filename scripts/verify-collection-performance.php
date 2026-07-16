<?php
/**
 * Local 10k-record performance gate for the indexed CCT Collection provider.
 *
 * Run with:
 * wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-collection-performance.php
 */

use EIT\Blueprint\Uuid;
use EIT\CCT\DefinitionManager;
use EIT\CCT\SchemaManager;
use EIT\Collection\CctCollectionProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$slug = 'eit_collection_perf';
$previous = get_option( DefinitionManager::OPTION, [] );
$previous = is_array( $previous ) ? $previous : [];
$definition = [
	'slug' => $slug,
	'singular' => 'Performance item',
	'plural' => 'Performance items',
	'public' => true,
	'state' => 'active',
	'fields' => [
		[ 'key' => 'price', 'label' => 'Price', 'type' => 'number', 'filterable' => true, 'sortable' => true, 'active' => true ],
		[ 'key' => 'city', 'label' => 'City', 'type' => 'select', 'options' => "north|North\nsouth|South\neast|East\nwest|West", 'filterable' => true, 'sortable' => true, 'active' => true ],
	],
];

try {
	$definitions = $previous;
	$definitions[ $slug ] = $definition;
	update_option( DefinitionManager::OPTION, $definitions, false );
	$result = SchemaManager::sync_definition( $definition );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	$table = SchemaManager::table_name( $slug );
	$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Temporary internal verification table.
	$cities = [ 'north', 'south', 'east', 'west' ];
	$now = current_time( 'mysql' );
	for ( $offset = 0; $offset < 10000; $offset += 500 ) {
		$rows = [];
		$params = [];
		for ( $index = $offset; $index < $offset + 500; ++$index ) {
			$rows[] = '(%s,%s,%d,%d,%s,%s,%f,%s)';
			array_push( $params, 'Item ' . $index, 'publish', 1, $index, $now, $now, (float) ( $index % 1000 ), $cities[ $index % 4 ] );
		}
		$sql = "INSERT INTO `{$table}` (title,status,author_id,menu_order,created_at,updated_at,f_price,f_city) VALUES " . implode( ',', $rows );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Generated placeholders cover every value; table and columns are internal.
		$inserted = $wpdb->query( $wpdb->prepare( $sql, $params ) );
		if ( 500 !== $inserted ) {
			throw new RuntimeException( 'Performance fixture insertion failed.' );
		}
	}

	$price_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:performance:price' );
	$city_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'verify:performance:city' );
	$contract = [
		'entity' => [ 'definition' => [ 'slug' => $slug ] ],
		'search_field_ids' => [],
		'fields' => [
			[ 'id' => $price_id, 'name' => 'Price', 'type' => 'decimal', 'storage' => [ 'key' => 'price' ] ],
			[ 'id' => $city_id, 'name' => 'City', 'type' => 'single_choice', 'storage' => [ 'key' => 'city' ] ],
		],
	];
	$request = [
		'page' => 1,
		'per_page' => 24,
		'search' => '',
		'filters' => [
			[ 'field_id' => $price_id, 'operator' => 'gte', 'value' => 400 ],
			[ 'field_id' => $city_id, 'operator' => 'in', 'value' => [ 'north', 'south' ] ],
		],
		'sort' => [ 'field_id' => $price_id, 'direction' => 'desc' ],
		'facets' => [ $city_id ],
	];
	$provider = new CctCollectionProvider();
	$provider->query( $contract, $request );
	$durations = [];
	$query_counts = [];
	for ( $run = 0; $run < 20; ++$run ) {
		$before_queries = $wpdb->num_queries;
		$started = hrtime( true );
		$query = $provider->query( $contract, $request );
		$durations[] = ( hrtime( true ) - $started ) / 1000000;
		$query_counts[] = $wpdb->num_queries - $before_queries;
		if ( is_wp_error( $query ) || 24 !== count( $query['items'] ?? [] ) ) {
			throw new RuntimeException( 'Performance query returned an invalid page.' );
		}
	}
	sort( $durations, SORT_NUMERIC );
	$p95 = $durations[ (int) ceil( count( $durations ) * 0.95 ) - 1 ];
	if ( $p95 >= 250 ) {
		throw new RuntimeException( sprintf( 'Indexed Collection p95 %.2f ms exceeds the 250 ms gate.', $p95 ) );
	}
	if ( max( $query_counts ) > 8 ) {
		throw new RuntimeException( 'Collection query count suggests an N+1 regression.' );
	}
	echo sprintf( "Collection performance passed: 10000 records, p95 %.2f ms, max %d queries.\n", $p95, max( $query_counts ) );
} finally {
	update_option( DefinitionManager::OPTION, $previous, false );
	SchemaManager::drop_table( $slug );
}
