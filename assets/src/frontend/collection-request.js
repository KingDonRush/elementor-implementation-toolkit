export function collectionEndpoint( runtime, collectionId ) {
	const base = runtime.collectionRestUrl || `${ String( runtime.entryRestUrl || '/wp-json/eit/v1' ).replace( /\/$/, '' ) }/collections`;
	return `${ String( base ).replace( /\/$/, '' ) }/${ encodeURIComponent( collectionId ) }/query`;
}

export function collectionPayload( state, runtime, page ) {
	const filters = ( state.filters || [] ).map( ( filter ) => {
		const operator = filter.compare || defaultOperator( filter );
		let value = filter.value;
		if ( [ 'in', 'not_in' ].includes( operator ) && ! Array.isArray( value ) ) {
			value = [ value ];
		}
		return { field_id: filter.key, operator, value };
	} ).filter( ( filter ) => Boolean( filter.field_id ) );
	const payload = {
		page: Math.max( 1, Number( page ) || 1 ),
		per_page: Math.min( 48, Math.max( 1, Number( runtime.perPage ) || 24 ) ),
		filters,
		facets: Array.from( new Set( runtime.collectionFacetIds || [] ) ),
	};
	if ( runtime.collectionExplain ) payload.explain = true;
	const sort = collectionSort( state.sort );
	if ( sort ) payload.sort = sort;
	return payload;
}

export function normalizeCollectionResponse( response = {} ) {
	const pagination = response.pagination || {};
	return {
		...response,
		total: Number( pagination.total || 0 ),
		page: Number( pagination.page || 1 ),
		pages: Number( pagination.pages || 1 ),
		perPage: Number( pagination.per_page || 0 ),
	};
}

function collectionSort( value ) {
	if ( ! value || 'default' === value ) return null;
	const separator = String( value ).lastIndexOf( ':' );
	if ( separator < 1 ) return null;
	const fieldId = String( value ).slice( 0, separator );
	const direction = 'desc' === String( value ).slice( separator + 1 ) ? 'desc' : 'asc';
	return { field_id: fieldId, direction };
}

function defaultOperator( filter ) {
	if ( [ 'range', 'date' ].includes( filter.type ) ) return 'between';
	if ( [ 'checkbox', 'chips', 'swatch' ].includes( filter.type ) ) return 'in';
	return 'equals';
}
