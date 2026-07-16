import {
	Background,
	Controls,
	ReactFlow,
	ReactFlowProvider,
	applyNodeChanges,
	useReactFlow,
} from '@xyflow/react';
import { useCallback, useEffect, useMemo } from 'react';
import { __ } from '@wordpress/i18n';
import { useDispatch, useSelect } from '@wordpress/data';
import { STORE_NAME } from '../store';
import { connectionType, mapEdges, mapNodes, uuid } from '../contracts';
import NodeCard from './NodeCard';

const nodeTypes = { eitNode: NodeCard };

function Flow() {
	const { document, schema, selectedNodeId, validation } = useSelect( ( select ) => {
		const state = select( STORE_NAME ).getState();
		return state;
	}, [] );
	const { selectNode, setNotice, updateDocument } = useDispatch( STORE_NAME );
	const { fitView } = useReactFlow();
	const nodes = useMemo( () => mapNodes( document, validation, selectedNodeId ), [ document, validation, selectedNodeId ] );
	const edges = useMemo( () => mapEdges( document ), [ document ] );

	useEffect( () => {
		const frame = window.requestAnimationFrame( () => fitView( { padding: 0.18 } ) );
		return () => window.cancelAnimationFrame( frame );
	}, [ document.nodes.length, fitView ] );

	const onNodesChange = useCallback( ( changes ) => {
		const positioned = applyNodeChanges( changes, nodes );
		const positions = new Map( positioned.map( ( node ) => [ node.id, node.position ] ) );
		if ( changes.some( ( change ) => 'position' === change.type && change.position ) ) {
			updateDocument( { ...document, nodes: document.nodes.map( ( node ) => ({ ...node, position: positions.get( node.id ) || node.position }) ) } );
		}
	}, [ document, nodes, updateDocument ] );

	const onConnect = useCallback( ( connection ) => {
		const source = document.nodes.find( ( node ) => node.id === connection.source );
		const target = document.nodes.find( ( node ) => node.id === connection.target );
		const type = source && target ? connectionType( schema, source.type, target.type ) : null;
		if ( ! type ) {
			setNotice( { status: 'error', message: __( 'These node types cannot be connected in this direction.', 'elementor-implementation-toolkit' ) } );
			return;
		}
		if ( document.connections.some( ( edge ) => edge.from === source.id && edge.to === target.id && edge.type === type ) ) {
			setNotice( { status: 'warning', message: __( 'This executable connection already exists.', 'elementor-implementation-toolkit' ) } );
			return;
		}
		updateDocument( { ...document, connections: [ ...document.connections, { id: uuid(), type, from: source.id, to: target.id } ] } );
	}, [ document, schema, setNotice, updateDocument ] );

	return (
		<ReactFlow
			nodes={ nodes }
			edges={ edges }
			nodeTypes={ nodeTypes }
			onNodesChange={ onNodesChange }
			onNodeClick={ ( event, node ) => selectNode( node.id ) }
			onConnect={ onConnect }
			deleteKeyCode={ null }
			fitView
			fitViewOptions={ { padding: 0.18 } }
			minZoom={ 0.35 }
			maxZoom={ 1.5 }
			nodesFocusable
			edgesFocusable
			autoPanOnNodeFocus
			snapToGrid
			snapGrid={ [ 16, 16 ] }
			ariaLabelConfig={ {
				'node.a11yDescription.default': __( 'Press Enter or Space to select this node. Use arrow keys to move it.', 'elementor-implementation-toolkit' ),
				'edge.a11yDescription.default': __( 'Executable connection between two system nodes.', 'elementor-implementation-toolkit' ),
				'controls.ariaLabel': __( 'Map controls', 'elementor-implementation-toolkit' ),
				'controls.zoomIn.ariaLabel': __( 'Zoom in', 'elementor-implementation-toolkit' ),
				'controls.zoomOut.ariaLabel': __( 'Zoom out', 'elementor-implementation-toolkit' ),
				'controls.fitView.ariaLabel': __( 'Fit system to view', 'elementor-implementation-toolkit' ),
				'controls.interactive.ariaLabel': __( 'Toggle map interaction', 'elementor-implementation-toolkit' ),
			} }
			proOptions={ { hideAttribution: false } }
		>
			<Background gap={ 24 } size={ 1 } color="#dcdcde" />
			<Controls showInteractive={ false } />
		</ReactFlow>
	);
}

export default function MapCanvas() {
	return <div className="eit-system-canvas" aria-label={ __( 'Executable system map', 'elementor-implementation-toolkit' ) }><ReactFlowProvider><Flow /></ReactFlowProvider></div>;
}
