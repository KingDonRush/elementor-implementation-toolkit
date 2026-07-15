const { createReduxStore, register } = window.wp.data;

export const STORE_NAME = 'eit/systems';

const initialState = {
	systems: [],
	schema: null,
	current: null,
	document: null,
	selectedNodeId: null,
	view: 'canvas',
	busy: '',
	dirty: false,
	validation: null,
	prepared: null,
	notice: null,
};

const actions = {
	setSystems: ( systems ) => ({ type: 'SET_SYSTEMS', systems }),
	setSchema: ( schema ) => ({ type: 'SET_SCHEMA', schema }),
	openSystem: ( current ) => ({ type: 'OPEN_SYSTEM', current }),
	closeSystem: () => ({ type: 'CLOSE_SYSTEM' }),
	updateDocument: ( document ) => ({ type: 'UPDATE_DOCUMENT', document }),
	selectNode: ( id ) => ({ type: 'SELECT_NODE', id }),
	setView: ( view ) => ({ type: 'SET_VIEW', view }),
	setBusy: ( busy ) => ({ type: 'SET_BUSY', busy }),
	setValidation: ( validation ) => ({ type: 'SET_VALIDATION', validation }),
	setPrepared: ( prepared ) => ({ type: 'SET_PREPARED', prepared }),
	setNotice: ( notice ) => ({ type: 'SET_NOTICE', notice }),
};

function reducer( state = initialState, action ) {
	switch ( action.type ) {
		case 'SET_SYSTEMS': return { ...state, systems: action.systems };
		case 'SET_SCHEMA': return { ...state, schema: action.schema };
		case 'OPEN_SYSTEM': return { ...state, current: action.current, document: action.current.document, selectedNodeId: action.current.document?.nodes?.[ 0 ]?.id || null, validation: action.current.validation, prepared: null, dirty: false };
		case 'CLOSE_SYSTEM': return { ...state, current: null, document: null, selectedNodeId: null, validation: null, prepared: null, dirty: false };
		case 'UPDATE_DOCUMENT': return { ...state, document: action.document, dirty: true, validation: null, prepared: null };
		case 'SELECT_NODE': return { ...state, selectedNodeId: action.id };
		case 'SET_VIEW': return { ...state, view: action.view };
		case 'SET_BUSY': return { ...state, busy: action.busy };
		case 'SET_VALIDATION': return { ...state, validation: action.validation, prepared: null };
		case 'SET_PREPARED': return { ...state, prepared: action.prepared };
		case 'SET_NOTICE': return { ...state, notice: action.notice };
		default: return state;
	}
}

const selectors = {
	getState: ( state ) => state,
	getSystems: ( state ) => state.systems,
	getSchema: ( state ) => state.schema,
	getCurrent: ( state ) => state.current,
	getDocument: ( state ) => state.document,
	getSelectedNode: ( state ) => state.document?.nodes?.find( ( node ) => node.id === state.selectedNodeId ) || null,
};

export const store = createReduxStore( STORE_NAME, { reducer, actions, selectors } );
register( store );
