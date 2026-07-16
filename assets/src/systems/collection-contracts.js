export function collectionForNode( document, node ) {
	if ( 'collection' === node.type ) {
		return node;
	}
	if ( 'filter_surface' !== node.type ) {
		return null;
	}
	const edge = document.connections.find( ( connection ) => 'filters' === connection.type && connection.to === node.id );
	return document.nodes.find( ( candidate ) => candidate.id === edge?.from ) || null;
}

export function entityForCollection( document, collection ) {
	if ( ! collection ) {
		return null;
	}
	const edge = document.connections.find( ( connection ) => 'collection_for' === connection.type && connection.to === collection.id );
	return document.nodes.find( ( node ) => node.id === edge?.from ) || null;
}

export function adapterFieldsForEntity( document, entity, schema = {} ) {
	if ( ! entity ) {
		return [];
	}
	const adapterEdge = document.connections.find( ( connection ) => 'adapts' === connection.type && connection.to === entity.id );
	const adapterNode = document.nodes.find( ( candidate ) => candidate.id === adapterEdge?.from );
	const fields = schema.adapters?.[ adapterNode?.config?.adapter_id ]?.fields;
	return Array.isArray( fields ) ? fields : [];
}

export function queryFields( document, node, capability, schema = {} ) {
	const entity = entityForCollection( document, collectionForNode( document, node ) );
	if ( ! entity ) {
		return [];
	}
	const adapterFields = adapterFieldsForEntity( document, entity, schema );
	if ( adapterFields.length ) {
		return adapterFields.filter( ( field ) => Boolean( field.capabilities?.[ capability ] && field.indexing?.[ capability ] ) );
	}
	const groupIds = document.connections
		.filter( ( connection ) => 'entity_fields' === connection.type && connection.from === entity.id )
		.map( ( connection ) => connection.to );
	return document.nodes
		.filter( ( candidate ) => groupIds.includes( candidate.id ) )
		.flatMap( ( group ) => group.config?.fields || [] )
		.filter( ( field ) => Boolean( field.capabilities?.[ capability ] && field.indexing?.[ capability ] ) );
}

export function selectedFilterIds( node, fields ) {
	const available = fields.map( ( field ) => field.id );
	const configured = Array.isArray( node.config?.fields ) ? node.config.fields.filter( ( id ) => available.includes( id ) ) : [];
	return configured.length ? configured : available;
}

export function selectedFacetIds( node, fields, selectedIds ) {
	if ( Object.prototype.hasOwnProperty.call( node.config || {}, 'facet_fields' ) ) {
		return ( node.config.facet_fields || [] ).filter( ( id ) => selectedIds.includes( id ) );
	}
	return fields
		.filter( ( field ) => selectedIds.includes( field.id ) && [ 'boolean', 'single_choice', 'multiple_choice', 'taxonomy', 'relation' ].includes( field.type ) )
		.map( ( field ) => field.id );
}

export function toggleRequiredSelection( selected, fieldId, checked ) {
	if ( checked ) {
		return Array.from( new Set( [ ...selected, fieldId ] ) );
	}
	return selected.length > 1 ? selected.filter( ( id ) => id !== fieldId ) : selected;
}
