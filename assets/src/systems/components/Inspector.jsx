import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	Modal,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { changeFieldPrimitive, fieldContract, humanize, nodeCopy, nodeErrors } from '../contracts';
import { STORE_NAME } from '../store';

function FieldEditor( { field, onChange, schema } ) {
	return (
		<details className="eit-inspector-field">
			<summary>{ field.name }</summary>
			<div>
				<TextControl label={ __( 'Public name', 'elementor-implementation-toolkit' ) } value={ field.name } onChange={ ( name ) => onChange( { ...field, name } ) } />
				<SelectControl label={ __( 'Semantic type', 'elementor-implementation-toolkit' ) } value={ field.type } options={ Object.keys( schema.primitives ).map( ( type ) => ({ label: humanize( type ), value: type }) ) } onChange={ ( type ) => onChange( changeFieldPrimitive( field, schema, type ) ) } />
				<ToggleControl label={ __( 'Required', 'elementor-implementation-toolkit' ) } checked={ Boolean( field.validation.required ) } onChange={ ( required ) => onChange( { ...field, validation: { ...field.validation, required } } ) } />
				<ToggleControl label={ __( 'Filterable', 'elementor-implementation-toolkit' ) } checked={ Boolean( field.indexing.filter ) } disabled={ ! field.capabilities.filter } onChange={ ( filter ) => onChange( { ...field, indexing: { ...field.indexing, filter } } ) } />
				<ToggleControl label={ __( 'Sortable', 'elementor-implementation-toolkit' ) } checked={ Boolean( field.indexing.sort ) } disabled={ ! field.capabilities.sort } onChange={ ( sort ) => onChange( { ...field, indexing: { ...field.indexing, sort } } ) } />
			</div>
		</details>
	);
}

function EssentialDecisions( { node, update, schema } ) {
	const config = node.config || {};
	if ( 'entity' === node.type ) {
		return <>
			<SelectControl label={ __( 'Content mode', 'elementor-implementation-toolkit' ) } value={ config.mode || 'structured' } options={ [ { label: __( 'Structured', 'elementor-implementation-toolkit' ), value: 'structured' }, { label: __( 'Editorial', 'elementor-implementation-toolkit' ), value: 'editorial' }, { label: __( 'Hybrid', 'elementor-implementation-toolkit' ), value: 'hybrid' } ] } onChange={ ( mode ) => update( { ...node, config: { ...config, mode } } ) } />
			<ToggleControl label={ __( 'Public content', 'elementor-implementation-toolkit' ) } checked={ Boolean( config.public ) } onChange={ ( value ) => update( { ...node, config: { ...config, public: value } } ) } />
			<ToggleControl label={ __( 'Has a public route', 'elementor-implementation-toolkit' ) } checked={ Boolean( config.routed ) } onChange={ ( value ) => update( { ...node, config: { ...config, routed: value } } ) } />
			<ToggleControl label={ __( 'Keep revisions', 'elementor-implementation-toolkit' ) } checked={ Boolean( config.versioned ) } onChange={ ( value ) => update( { ...node, config: { ...config, versioned: value } } ) } />
		</>;
	}
	if ( 'field_group' === node.type ) {
		const updateField = ( index, field ) => update( { ...node, config: { ...config, fields: config.fields.map( ( current, offset ) => index === offset ? field : current ) } } );
		return <div className="eit-inspector-fields">
			{ config.fields.map( ( field, index ) => <FieldEditor key={ field.id } field={ field } schema={ schema } onChange={ ( value ) => updateField( index, value ) } /> ) }
			<Button variant="secondary" onClick={ () => update( { ...node, config: { ...config, fields: [ ...config.fields, fieldContract( schema, __( 'New field', 'elementor-implementation-toolkit' ) ) ] } } ) }>{ __( 'Add field', 'elementor-implementation-toolkit' ) }</Button>
		</div>;
	}
	if ( 'relation' === node.type ) {
		return <SelectControl label={ __( 'Cardinality', 'elementor-implementation-toolkit' ) } value={ config.cardinality || 'many_to_one' } options={ [ 'one_to_one', 'one_to_many', 'many_to_one', 'many_to_many' ].map( ( value ) => ({ label: humanize( value ), value }) ) } onChange={ ( cardinality ) => update( { ...node, config: { ...config, cardinality } } ) } />;
	}
	if ( 'entry_surface' === node.type ) {
		const operations = config.operations || [];
		const toggle = ( operation, checked ) => update( { ...node, config: { ...config, operations: checked ? [ ...new Set( [ ...operations, operation ] ) ] : operations.filter( ( value ) => value !== operation ) } } );
		return <><CheckboxControl label={ __( 'Create entries', 'elementor-implementation-toolkit' ) } checked={ operations.includes( 'create' ) } onChange={ ( checked ) => toggle( 'create', checked ) } /><CheckboxControl label={ __( 'Update entries', 'elementor-implementation-toolkit' ) } checked={ operations.includes( 'update' ) } onChange={ ( checked ) => toggle( 'update', checked ) } /><SelectControl label={ __( 'New entry status', 'elementor-implementation-toolkit' ) } value={ config.initial_status || 'draft' } options={ [ 'draft', 'review', 'publish' ].map( ( value ) => ({ label: humanize( value ), value }) ) } onChange={ ( initial_status ) => update( { ...node, config: { ...config, initial_status } } ) } /><ToggleControl label={ __( 'Autosave drafts', 'elementor-implementation-toolkit' ) } checked={ Boolean( config.autosave?.enabled ) } onChange={ ( enabled ) => update( { ...node, config: { ...config, autosave: { ...config.autosave, enabled } } } ) } /><ToggleControl label={ __( 'Moderated guest intake', 'elementor-implementation-toolkit' ) } checked={ Boolean( config.guest?.enabled ) } onChange={ ( enabled ) => update( { ...node, config: { ...config, guest: { ...config.guest, enabled } } } ) } /></>;
	}
	if ( 'collection' === node.type ) {
		return <TextControl type="number" min="1" max="48" label={ __( 'Items per page', 'elementor-implementation-toolkit' ) } value={ config.page_size || 24 } onChange={ ( value ) => update( { ...node, config: { ...config, page_size: Math.min( 48, Math.max( 1, Number( value ) || 24 ) ) } } ) } />;
	}
	if ( 'presentation' === node.type ) {
		return <SelectControl label={ __( 'Presentation adapter', 'elementor-implementation-toolkit' ) } value={ config.adapter || 'elementor' } options={ [ { label: 'Elementor', value: 'elementor' } ] } onChange={ ( adapter ) => update( { ...node, config: { ...config, adapter } } ) } />;
	}
	if ( 'route' === node.type ) {
		return <TextControl label={ __( 'Public path', 'elementor-implementation-toolkit' ) } value={ config.path || '/' } onChange={ ( path ) => update( { ...node, config: { ...config, path } } ) } />;
	}
	if ( 'policy' === node.type ) {
		return <><SelectControl label={ __( 'Required capability', 'elementor-implementation-toolkit' ) } value={ config.capability || 'edit_posts' } options={ [ 'read', 'edit_posts', 'publish_posts', 'manage_options' ].map( ( value ) => ({ label: humanize( value ), value }) ) } onChange={ ( capability ) => update( { ...node, config: { ...config, capability } } ) } /><SelectControl label={ __( 'Ownership scope', 'elementor-implementation-toolkit' ) } value={ config.ownership || 'any' } options={ [ { label: __( 'Any permitted object', 'elementor-implementation-toolkit' ), value: 'any' }, { label: __( 'Own objects only', 'elementor-implementation-toolkit' ), value: 'own' } ] } onChange={ ( ownership ) => update( { ...node, config: { ...config, ownership } } ) } /></>;
	}
	if ( 'adapter' === node.type ) {
		return <SelectControl label={ __( 'Registered adapter', 'elementor-implementation-toolkit' ) } value={ config.adapter_id || '' } options={ [ { label: __( 'Choose an adapter', 'elementor-implementation-toolkit' ), value: '' }, ...Object.keys( schema.adapters ).map( ( value ) => ({ label: value, value }) ) ] } onChange={ ( adapterId ) => update( { ...node, config: { ...config, adapter_id: adapterId } } ) } />;
	}
	return <p>{ __( 'This node derives its decisions from connected Field Contracts.', 'elementor-implementation-toolkit' ) }</p>;
}

export default function Inspector() {
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const { document, schema, selectedNode, validation } = useSelect( ( select ) => {
		const state = select( STORE_NAME ).getState();
		return { ...state, selectedNode: select( STORE_NAME ).getSelectedNode() };
	}, [] );
	const { selectNode, updateDocument } = useDispatch( STORE_NAME );

	if ( ! selectedNode ) {
		return <aside className="eit-system-inspector"><div className="eit-empty-panel"><h3>{ __( 'Select a node', 'elementor-implementation-toolkit' ) }</h3><p>{ __( 'The inspector exposes only decisions that change this contract.', 'elementor-implementation-toolkit' ) }</p></div></aside>;
	}
	const update = ( node ) => updateDocument( { ...document, nodes: document.nodes.map( ( item ) => item.id === node.id ? node : item ) } );
	const connections = document.connections.filter( ( connection ) => connection.from === selectedNode.id || connection.to === selectedNode.id );
	const errors = nodeErrors( validation, selectedNode.id );
	const access = 'policy' === selectedNode.type ? `${ selectedNode.config.capability || 'edit_posts' } · ${ selectedNode.config.ownership || 'any' }` : ( selectedNode.config.public ? __( 'Public projection; mutations still require Policy.', 'elementor-implementation-toolkit' ) : __( 'Not public unless a connected Policy and Surface permit it.', 'elementor-implementation-toolkit' ) );
	const remove = () => {
		updateDocument( { ...document, nodes: document.nodes.filter( ( node ) => node.id !== selectedNode.id ), connections: document.connections.filter( ( connection ) => connection.from !== selectedNode.id && connection.to !== selectedNode.id ) } );
		selectNode( document.nodes.find( ( node ) => node.id !== selectedNode.id )?.id || null );
		setConfirmDelete( false );
	};

	return (
		<aside className="eit-system-inspector" aria-label={ __( 'Node inspector', 'elementor-implementation-toolkit' ) }>
			<header><span>{ humanize( selectedNode.type ) }</span><h2>{ selectedNode.name }</h2>{ errors.length ? <p className="eit-inspector-error">{ errors.length } { __( 'validation issues affect this node.', 'elementor-implementation-toolkit' ) }</p> : null }</header>
			<section><h3>1. { __( 'What this node does', 'elementor-implementation-toolkit' ) }</h3><p>{ nodeCopy[ selectedNode.type ]?.[ 0 ] }</p><TextControl label={ __( 'Public name', 'elementor-implementation-toolkit' ) } value={ selectedNode.name } onChange={ ( name ) => update( { ...selectedNode, name } ) } /></section>
			<section><h3>2. { __( 'Where it enters the flow', 'elementor-implementation-toolkit' ) }</h3>{ connections.length ? <ul>{ connections.map( ( connection ) => <li key={ connection.id }>{ humanize( connection.type ) }</li> ) }</ul> : <p>{ __( 'Not connected. Validation will block publication.', 'elementor-implementation-toolkit' ) }</p> }</section>
			<section><h3>3. { __( 'Compiled effect', 'elementor-implementation-toolkit' ) }</h3><p>{ nodeCopy[ selectedNode.type ]?.[ 1 ] }. { __( 'Exact additions and changes appear only in the compiler impact review.', 'elementor-implementation-toolkit' ) }</p></section>
			<section><h3>4. { __( 'Who can access it', 'elementor-implementation-toolkit' ) }</h3><p>{ access }</p></section>
			<section><h3>5. { __( 'Essential decisions', 'elementor-implementation-toolkit' ) }</h3><EssentialDecisions node={ selectedNode } update={ update } schema={ schema } /></section>
			<section><details><summary>6. { __( 'Technical details', 'elementor-implementation-toolkit' ) }</summary><dl className="eit-technical-details"><div><dt>ID</dt><dd><code>{ selectedNode.id }</code></dd></div><div><dt>Type</dt><dd><code>{ selectedNode.type }</code></dd></div><div><dt>Lane</dt><dd><code>{ selectedNode.lane }</code></dd></div></dl></details></section>
			<footer><Button isDestructive variant="secondary" onClick={ () => setConfirmDelete( true ) }>{ __( 'Remove draft node', 'elementor-implementation-toolkit' ) }</Button></footer>
			{ confirmDelete ? <Modal title={ __( 'Remove this draft node?', 'elementor-implementation-toolkit' ) } onRequestClose={ () => setConfirmDelete( false ) }><p>{ __( 'Its draft connections will also be removed. Published runtime stays unchanged until a later impact review and publication.', 'elementor-implementation-toolkit' ) }</p><div className="eit-modal-actions"><Button variant="tertiary" onClick={ () => setConfirmDelete( false ) }>{ __( 'Keep node', 'elementor-implementation-toolkit' ) }</Button><Button isDestructive variant="primary" onClick={ remove }>{ __( 'Remove draft node', 'elementor-implementation-toolkit' ) }</Button></div></Modal> : null }
		</aside>
	);
}
