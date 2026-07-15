import { __ } from '@wordpress/i18n';

const LANE_ORDER = [ 'data', 'experience', 'presentation', 'governance' ];

export const nodeCopy = {
	entity: [ __( 'Stores one kind of content or operational record.', 'elementor-implementation-toolkit' ), __( 'WordPress data definition', 'elementor-implementation-toolkit' ) ],
	field_group: [ __( 'Groups semantic fields owned by an Entity.', 'elementor-implementation-toolkit' ), __( 'Field contracts', 'elementor-implementation-toolkit' ) ],
	relation: [ __( 'Connects records through a normalized relation.', 'elementor-implementation-toolkit' ), __( 'Relation contract', 'elementor-implementation-toolkit' ) ],
	entry_surface: [ __( 'Defines governed create and update behavior.', 'elementor-implementation-toolkit' ), __( 'Entry contract', 'elementor-implementation-toolkit' ) ],
	collection: [ __( 'Defines the only query contract for a result set.', 'elementor-implementation-toolkit' ), __( 'Collection contract', 'elementor-implementation-toolkit' ) ],
	filter_surface: [ __( 'Derives available filters from field capabilities.', 'elementor-implementation-toolkit' ), __( 'Filter contract', 'elementor-implementation-toolkit' ) ],
	presentation: [ __( 'Connects runtime data to a visual adapter.', 'elementor-implementation-toolkit' ), __( 'Presentation contract', 'elementor-implementation-toolkit' ) ],
	route: [ __( 'Exposes a Presentation at an intentional route.', 'elementor-implementation-toolkit' ), __( 'Route contract', 'elementor-implementation-toolkit' ) ],
	policy: [ __( 'Applies capability, ownership and object scope.', 'elementor-implementation-toolkit' ), __( 'Policy contract', 'elementor-implementation-toolkit' ) ],
	adapter: [ __( 'Declares an external system that remains authoritative.', 'elementor-implementation-toolkit' ), __( 'Adapter contract', 'elementor-implementation-toolkit' ) ],
};

export const laneLabels = {
	data: __( 'Data', 'elementor-implementation-toolkit' ),
	experience: __( 'Experience', 'elementor-implementation-toolkit' ),
	presentation: __( 'Presentation', 'elementor-implementation-toolkit' ),
	governance: __( 'Governance', 'elementor-implementation-toolkit' ),
};

export function humanize( value ) {
	return String( value || '' ).replace( /_/g, ' ' );
}

export function uuid() {
	if ( window.crypto?.randomUUID ) {
		return window.crypto.randomUUID();
	}
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, ( token ) => {
		const value = Math.floor( Math.random() * 16 );
		return ( 'x' === token ? value : ( value & 0x3 ) | 0x8 ).toString( 16 );
	} );
}

export function slugify( value ) {
	return String( value || '' )
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' )
		.slice( 0, 80 ) || 'untitled-system';
}

export function fieldContract( schema, name = 'Title', type = 'short_text' ) {
	const id = uuid();
	const definition = schema.primitives[ type ]?.definition || schema.primitives.short_text.definition;
	return {
		id,
		name,
		type,
		shape: definition.shape,
		validation: { required: false },
		exposure: { public: false, roles: [] },
		storage: { key: `eit_${ id.replace( /-/g, '' ).slice( 0, 16 ) }`, aliases: [] },
		indexing: { search: false, filter: false, sort: false },
		components: definition.components,
		elementor: definition.elementor,
		capabilities: definition.capabilities,
	};
}

export function changeFieldPrimitive( field, schema, type ) {
	const definition = schema.primitives[ type ]?.definition;
	if ( ! definition ) {
		return field;
	}
	return {
		...field,
		type,
		shape: definition.shape,
		components: definition.components,
		elementor: definition.elementor,
		capabilities: definition.capabilities,
		indexing: {
			search: Boolean( field.indexing.search && definition.capabilities.search ),
			filter: Boolean( field.indexing.filter && definition.capabilities.filter ),
			sort: Boolean( field.indexing.sort && definition.capabilities.sort ),
		},
	};
}

export function createNode( type, schema, name ) {
	const id = uuid();
	const defaults = {
		entity: { slug: slugify( name || 'content' ).replace( /-/g, '_' ).slice( 0, 32 ), mode: 'structured', public: false, routed: false, versioned: false },
		field_group: { fields: [ fieldContract( schema ) ] },
		relation: { cardinality: 'many_to_one' },
		entry_surface: { operations: [ 'create', 'update' ] },
		collection: { page_size: 24 },
		filter_surface: { fields: [] },
		presentation: { adapter: 'elementor' },
		route: { path: '/' },
		policy: { capability: 'edit_posts', ownership: 'any' },
		adapter: { adapter_id: '' },
	};
	return {
		id,
		type,
		lane: schema.node_types[ type ],
		name: name || humanize( type ).replace( /^./, ( character ) => character.toUpperCase() ),
		config: defaults[ type ] || {},
		position: { x: 0, y: 0 },
	};
}

export function starterDocument( name, schema ) {
	const id = uuid();
	const entity = createNode( 'entity', schema, 'Content' );
	const fields = createNode( 'field_group', schema, 'Content fields' );
	const document = {
		api_version: schema.api_version,
		kind: schema.kind,
		id,
		slug: slugify( name ),
		name: String( name ).trim(),
		version: 1,
		nodes: [ entity, fields ],
		connections: [ { id: uuid(), type: 'entity_fields', from: entity.id, to: fields.id } ],
	};
	return autoLayout( document );
}

export function autoLayout( document ) {
	const counters = Object.fromEntries( LANE_ORDER.map( ( lane ) => [ lane, 0 ] ) );
	const nodes = document.nodes.map( ( node ) => {
		const lane = node.lane || 'data';
		const laneIndex = Math.max( 0, LANE_ORDER.indexOf( lane ) );
		const row = counters[ lane ] || 0;
		counters[ lane ] = row + 1;
		return { ...node, position: { x: laneIndex * 320, y: row * 190 } };
	} );
	return { ...document, nodes };
}

export function connectionType( schema, sourceType, targetType ) {
	for ( const [ type, definition ] of Object.entries( schema.connections ) ) {
		if ( definition[ 0 ] === sourceType && definition[ 1 ] === targetType ) {
			return type;
		}
	}
	return null;
}

export function nodeErrors( validation, nodeId ) {
	return validation?.errors?.filter( ( error ) => error.node_id === nodeId ) || [];
}

export function mapNodes( document, validation, selectedId, onSelect ) {
	return document.nodes.map( ( node ) => ({
		...node,
		type: 'eitNode',
		selected: node.id === selectedId,
		data: {
			contract: node,
			copy: nodeCopy[ node.type ],
			errors: nodeErrors( validation, node.id ),
			validated: Boolean( validation ),
			onSelect,
		},
		ariaLabel: `${ node.name }. ${ nodeCopy[ node.type ]?.[ 0 ] || node.type }`,
		domAttributes: { 'data-node-id': node.id },
	} ) );
}

export function mapEdges( document ) {
	return document.connections.map( ( connection ) => ({
		id: connection.id,
		source: connection.from,
		target: connection.to,
		type: 'smoothstep',
		data: { type: connection.type },
		ariaLabel: humanize( connection.type ),
	} ) );
}
