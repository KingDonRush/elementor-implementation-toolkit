import { useCallback, useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { api, errorMessage } from '../api';
import { starterDocument } from '../contracts';
import { STORE_NAME } from '../store';
import DiscardDialog from './DiscardDialog';
import ImpactDialog from './ImpactDialog';
import SystemList from './SystemList';
import Workspace from './Workspace';
import WorkspaceErrorBoundary from './WorkspaceErrorBoundary';

function setUrl( systemId = '', view = '' ) {
	const url = new URL( window.location.href );
	systemId ? url.searchParams.set( 'system', systemId ) : url.searchParams.delete( 'system' );
	view ? url.searchParams.set( 'workspace', view ) : url.searchParams.delete( 'workspace' );
	window.history.replaceState( {}, '', url );
}

export default function App() {
	const [ discarding, setDiscarding ] = useState( false );
	const state = useSelect( ( select ) => select( STORE_NAME ).getState(), [] );
	const dispatch = useDispatch( STORE_NAME );
	const { systems, schema, current, document, selectedNodeId, view, busy, dirty, prepared, notice } = state;

	const reportError = useCallback( ( error ) => {
		dispatch.setNotice( { status: 'error', message: errorMessage( error ) } );
	}, [ dispatch ] );

	const refreshList = useCallback( async () => {
		const response = await api.list();
		dispatch.setSystems( response.items );
		return response.items;
	}, [ dispatch ] );

	const open = useCallback( async ( id ) => {
		dispatch.setBusy( 'open' );
		try {
			const record = await api.get( id );
			dispatch.openSystem( record );
			setUrl( id, view );
		} catch ( error ) {
			reportError( error );
		} finally {
			dispatch.setBusy( '' );
		}
	}, [ dispatch, reportError, view ] );

	useEffect( () => {
		let active = true;
		( async () => {
			dispatch.setBusy( 'boot' );
			try {
				const [ schemaResponse, listResponse ] = await Promise.all( [ api.schema(), api.list() ] );
				if ( ! active ) return;
				dispatch.setSchema( schemaResponse );
				dispatch.setSystems( listResponse.items );
				const requested = new URL( window.location.href ).searchParams.get( 'system' );
				if ( requested && listResponse.items.some( ( system ) => system.id === requested ) ) await open( requested );
			} catch ( error ) {
				if ( active ) reportError( error );
			} finally {
				if ( active ) dispatch.setBusy( '' );
			}
		} )();
		return () => { active = false; };
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		const warn = ( event ) => { if ( dirty ) { event.preventDefault(); event.returnValue = ''; } };
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	useEffect( () => { if ( current ) setUrl( current.id, view ); }, [ current, view ] );

	const persist = async ( operation = 'save' ) => {
		dispatch.setBusy( operation );
		const selected = selectedNodeId;
		try {
			const record = await api.save( document );
			dispatch.openSystem( record );
			if ( selected ) dispatch.selectNode( selected );
			await refreshList();
			return record;
		} catch ( error ) {
			reportError( error );
			return null;
		} finally {
			dispatch.setBusy( '' );
		}
	};

	const validate = async () => {
		const saved = await persist( 'validate' );
		if ( ! saved ) return null;
		dispatch.setBusy( 'validate' );
		try {
			const validation = await api.validate( saved.id );
			dispatch.setValidation( validation );
			if ( ! validation.valid ) {
				const firstNode = validation.errors.find( ( error ) => error.node_id )?.node_id;
				if ( firstNode ) dispatch.selectNode( firstNode );
				dispatch.setNotice( { status: 'warning', message: __( 'Publication is blocked. Correct the highlighted node issues.', 'elementor-implementation-toolkit' ) } );
			}
			return validation;
		} catch ( error ) {
			reportError( error );
			return null;
		} finally {
			dispatch.setBusy( '' );
		}
	};

	const reviewImpact = async () => {
		dispatch.setBusy( 'impact' );
		try {
			const saved = await api.save( document );
			dispatch.openSystem( saved );
			const validation = await api.validate( saved.id );
			dispatch.setValidation( validation );
			if ( ! validation.valid ) {
				const firstNode = validation.errors.find( ( error ) => error.node_id )?.node_id;
				if ( firstNode ) dispatch.selectNode( firstNode );
				dispatch.setNotice( { status: 'warning', message: __( 'Impact review stopped because the saved Blueprint is incomplete.', 'elementor-implementation-toolkit' ) } );
				return;
			}
			const impact = await api.impact( saved.id );
			dispatch.setPrepared( impact );
			await refreshList();
		} catch ( error ) {
			reportError( error );
		} finally {
			dispatch.setBusy( '' );
		}
	};

	const publish = async () => {
		dispatch.setBusy( 'publish' );
		try {
			await api.apply( prepared );
			await api.reconcile( prepared.id );
			const record = await api.get( document.id );
			dispatch.openSystem( record );
			dispatch.setPrepared( null );
			dispatch.setNotice( { status: 'success', message: __( 'Blueprint published and reconciled.', 'elementor-implementation-toolkit' ) } );
			await refreshList();
		} catch ( error ) {
			reportError( error );
		} finally {
			dispatch.setBusy( '' );
		}
	};

	const create = async ( name ) => {
		dispatch.setBusy( 'create' );
		try {
			const record = await api.create( starterDocument( name, schema ) );
			dispatch.openSystem( record );
			await refreshList();
			setUrl( record.id, view );
			return true;
		} catch ( error ) {
			reportError( error );
			return false;
		} finally {
			dispatch.setBusy( '' );
		}
	};

	const closeSystem = () => {
		setDiscarding( false );
		dispatch.closeSystem();
		setUrl();
	};
	const requestClose = () => dirty ? setDiscarding( true ) : closeSystem();

	if ( ! schema ) return <div className="eit-system-loading" role="status"><Spinner /> <span>{ __( 'Loading executable contracts…', 'elementor-implementation-toolkit' ) }</span></div>;

	return <>
		<a className="screen-reader-text" href="#eit-system-main">{ __( 'Skip to system workspace', 'elementor-implementation-toolkit' ) }</a>
		<div className="eit-system-live" aria-live="polite" aria-atomic="true">{ notice ? <Notice status={ notice.status } onRemove={ () => dispatch.setNotice( null ) }>{ notice.message }</Notice> : null }</div>
		{ current ? <WorkspaceErrorBoundary onRecover={ requestClose }><Workspace onBack={ requestClose } onSave={ () => persist() } onValidate={ validate } onImpact={ reviewImpact } /></WorkspaceErrorBoundary> : <SystemList systems={ systems } busy={ busy } onCreate={ create } onOpen={ open } /> }
		<ImpactDialog prepared={ prepared } version={ document?.version || 1 } busy={ busy } onClose={ () => dispatch.setPrepared( null ) } onPublish={ publish } />
		<DiscardDialog open={ discarding } onCancel={ () => setDiscarding( false ) } onDiscard={ closeSystem } />
	</>;
}
