<?php

use EIT\Support\FilterResolver;
use EIT\Support\SortOptions;

$sort_items = [
	[
		'clientId'      => 'bravo',
		'originalIndex' => 0,
		'title'         => 'Bravo',
		'text'          => 'Bravo',
		'classes'       => [],
		'data'          => [ 'date' => '2026-01-02', 'sort' => '2', 'rating' => '4', 'price' => '20' ],
	],
	[
		'clientId'      => 'alpha',
		'originalIndex' => 1,
		'title'         => 'Alpha',
		'text'          => 'Alpha',
		'classes'       => [],
		'data'          => [ 'date' => '2026-01-01', 'sort' => '1', 'rating' => '5', 'price' => '10' ],
	],
	[
		'clientId'      => 'charlie',
		'originalIndex' => 2,
		'title'         => 'Charlie',
		'text'          => 'Charlie',
		'classes'       => [],
		'data'          => [ 'date' => '2026-01-03', 'sort' => '3', 'rating' => '3', 'price' => '30' ],
	],
];

$resolver = new FilterResolver();
eit_fc_assert( 'TEST-FC-ROBUSTNESS-009', 'Structured sort compiles custom data option', 'data_price_number_desc' === SortOptions::value_for_item( [ 'source' => 'data', 'key' => 'price', 'data_type' => 'number', 'direction' => 'desc' ] ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-009', 'Title sort asc orders items', 'alpha,bravo,charlie' === eit_fc_ids_for_result( $resolver->resolve( [ 'items' => $sort_items, 'sort' => 'title_asc', 'perPage' => 12 ] ) ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-009', 'Numeric sort asc orders items', 'alpha,bravo,charlie' === eit_fc_ids_for_result( $resolver->resolve( [ 'items' => $sort_items, 'sort' => 'numeric_asc', 'perPage' => 12 ] ) ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-009', 'Rating sort desc orders items', 'alpha,bravo,charlie' === eit_fc_ids_for_result( $resolver->resolve( [ 'items' => $sort_items, 'sort' => 'rating_desc', 'perPage' => 12 ] ) ) );
eit_fc_assert( 'TEST-FC-ROBUSTNESS-009', 'Custom data sort desc orders items', 'charlie,bravo,alpha' === eit_fc_ids_for_result( $resolver->resolve( [ 'items' => $sort_items, 'sort' => 'data_price_number_desc', 'perPage' => 12 ] ) ) );

