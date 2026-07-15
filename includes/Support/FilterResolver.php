<?php
/**
 * Bounded orchestration for the 1.x legacy DOM provider.
 */

namespace EIT\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterResolver {

	private $normalizer;
	private $matcher;

	public function __construct( LegacyDomPayload $normalizer = null, LegacyDomMatcher $matcher = null ) {
		$this->normalizer = $normalizer ?: new LegacyDomPayload();
		$this->matcher = $matcher ?: new LegacyDomMatcher();
	}

	public function resolve( $payload ) {
		$request = $this->normalizer->normalize( $payload );
		$matched = $this->matcher->filter( $request['items'], $request['filters'] );
		$matched = $this->matcher->sort( $matched, $request['sort'] );
		$total = count( $matched );
		$pages = max( 1, (int) ceil( $total / $request['per_page'] ) );
		$page = min( $request['page'], $pages );
		$current = array_slice( $matched, ( $page - 1 ) * $request['per_page'], $request['per_page'] );

		return [
			'ids'        => array_values( wp_list_pluck( $current, 'clientId' ) ),
			'allIds'     => array_values( wp_list_pluck( $matched, 'clientId' ) ),
			'total'      => $total,
			'page'       => $page,
			'pages'      => $pages,
			'perPage'    => $request['per_page'],
			'pagination' => [
				'hasPrevious' => $page > 1,
				'hasNext'     => $page < $pages,
			],
		];
	}
}
