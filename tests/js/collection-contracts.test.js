import { describe, expect, it } from 'vitest';
import { queryFields, selectedFacetIds, selectedFilterIds, toggleRequiredSelection } from '../../assets/src/systems/collection-contracts.js';

const document = {
	nodes: [
		{ id: 'entity', type: 'entity' },
		{ id: 'group', type: 'field_group', config: { fields: [
			{ id: 'price', name: 'Price', type: 'decimal', capabilities: { filter: true, sort: true }, indexing: { filter: true, sort: true } },
			{ id: 'tier', name: 'Tier', type: 'single_choice', capabilities: { filter: true }, indexing: { filter: true } },
			{ id: 'private', name: 'Internal', type: 'short_text', capabilities: { filter: true }, indexing: { filter: false } },
		] } },
		{ id: 'collection', type: 'collection', config: {} },
		{ id: 'filters', type: 'filter_surface', config: {} },
	],
	connections: [
		{ type: 'entity_fields', from: 'entity', to: 'group' },
		{ type: 'collection_for', from: 'entity', to: 'collection' },
		{ type: 'filters', from: 'collection', to: 'filters' },
	],
};

describe( 'Collection map decisions', () => {
	it( 'derives selectable fields only from connected published capabilities', () => {
		expect( queryFields( document, document.nodes[3], 'filter' ).map( ( field ) => field.id ) ).toEqual( [ 'price', 'tier' ] );
		expect( queryFields( document, document.nodes[2], 'sort' ).map( ( field ) => field.id ) ).toEqual( [ 'price' ] );
	} );

	it( 'uses all fields by default and categorical facets without raw mappings', () => {
		const fields = queryFields( document, document.nodes[3], 'filter' );
		const selected = selectedFilterIds( document.nodes[3], fields );
		expect( selected ).toEqual( [ 'price', 'tier' ] );
		expect( selectedFacetIds( document.nodes[3], fields, selected ) ).toEqual( [ 'tier' ] );
	} );

	it( 'does not allow the primary filter decision to become an accidental empty wildcard', () => {
		expect( toggleRequiredSelection( [ 'price' ], 'price', false ) ).toEqual( [ 'price' ] );
		expect( toggleRequiredSelection( [ 'price', 'tier' ], 'price', false ) ).toEqual( [ 'tier' ] );
	} );
} );
