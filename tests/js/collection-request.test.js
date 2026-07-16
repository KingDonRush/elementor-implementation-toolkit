import { describe, expect, it } from 'vitest';
import { collectionEndpoint, collectionPayload, normalizeCollectionResponse } from '../../assets/src/frontend/collection-request.js';

describe( 'Collection browser request contract', () => {
	it( 'maps widget state to Field IDs without provider, storage or selector inputs', () => {
		const payload = collectionPayload(
			{
				filters: [
					{ type: 'range', key: 'price-id', compare: 'between', value: { min: '0', max: '50' } },
					{ type: 'checkbox', key: 'status-id', compare: 'in', value: [ 'open' ] },
				],
				sort: 'price-id:desc',
			},
			{ perPage: 80, collectionFacetIds: [ 'status-id', 'status-id' ] },
			2,
		);

		expect( payload ).toEqual( {
			page: 2,
			per_page: 48,
			filters: [
				{ field_id: 'price-id', operator: 'between', value: { min: '0', max: '50' } },
				{ field_id: 'status-id', operator: 'in', value: [ 'open' ] },
			],
			facets: [ 'status-id' ],
			sort: { field_id: 'price-id', direction: 'desc' },
		} );
		expect( payload ).not.toHaveProperty( 'provider' );
		expect( payload ).not.toHaveProperty( 'targetSelector' );
	} );

	it( 'uses the stable Collection route and normalizes pagination', () => {
		expect( collectionEndpoint( { collectionRestUrl: '/wp-json/eit/v1/collections/' }, 'collection-id' ) )
			.toBe( '/wp-json/eit/v1/collections/collection-id/query' );
		expect( normalizeCollectionResponse( { pagination: { total: 12, page: 2, pages: 3, per_page: 5 } } ) )
			.toMatchObject( { total: 12, page: 2, pages: 3, perPage: 5 } );
	} );

	it( 'requests factual explanations only when the published contract enables them', () => {
		expect( collectionPayload( { filters: [] }, { collectionExplain: true }, 1 ) ).toMatchObject( { explain: true } );
		expect( collectionPayload( { filters: [] }, { collectionExplain: false }, 1 ) ).not.toHaveProperty( 'explain' );
	} );
} );
