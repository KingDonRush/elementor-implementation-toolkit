import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { autoLayout, createNode, laneLabels } from '../contracts';
import { STORE_NAME } from '../store';

const typeLabels = {
	entity: __( 'Entity', 'elementor-implementation-toolkit' ), field_group: __( 'Field Group', 'elementor-implementation-toolkit' ), relation: __( 'Relation', 'elementor-implementation-toolkit' ), entry_surface: __( 'Entry Surface', 'elementor-implementation-toolkit' ), collection: __( 'Collection', 'elementor-implementation-toolkit' ), filter_surface: __( 'Filter Surface', 'elementor-implementation-toolkit' ), presentation: __( 'Presentation', 'elementor-implementation-toolkit' ), route: __( 'Route', 'elementor-implementation-toolkit' ), policy: __( 'Policy', 'elementor-implementation-toolkit' ), adapter: __( 'Adapter', 'elementor-implementation-toolkit' ),
};

export default function NodePalette() {
	const { document, schema } = useSelect( ( select ) => select( STORE_NAME ).getState(), [] );
	const { selectNode, updateDocument } = useDispatch( STORE_NAME );

	const addNode = ( type ) => {
		const node = createNode( type, schema );
		const laneIndex = Object.keys( laneLabels ).indexOf( node.lane );
		const laneCount = document.nodes.filter( ( item ) => item.lane === node.lane ).length;
		node.position = { x: laneIndex * 320, y: laneCount * 190 };
		updateDocument( { ...document, nodes: [ ...document.nodes, node ] } );
		selectNode( node.id );
	};

	return (
		<aside className="eit-system-palette" aria-label={ __( 'Add system nodes', 'elementor-implementation-toolkit' ) }>
			<div className="eit-system-palette__head"><h3>{ __( 'Add a node', 'elementor-implementation-toolkit' ) }</h3><p>{ __( 'Choose the lane first. New nodes remain drafts until connected and validated.', 'elementor-implementation-toolkit' ) }</p></div>
			{ Object.entries( laneLabels ).map( ( [ lane, label ] ) => (
				<details key={ lane }>
					<summary>{ label }</summary>
					<div>{ Object.entries( schema.node_types ).filter( ( entry ) => entry[ 1 ] === lane ).map( ( [ type ] ) => <Button key={ type } variant="tertiary" onClick={ () => addNode( type ) }>{ typeLabels[ type ] }</Button> ) }</div>
				</details>
			) ) }
			<Button variant="secondary" onClick={ () => updateDocument( autoLayout( document ) ) }>{ __( 'Auto-arrange map', 'elementor-implementation-toolkit' ) }</Button>
		</aside>
	);
}
