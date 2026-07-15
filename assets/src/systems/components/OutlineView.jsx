import { __, _n, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { humanize, laneLabels, nodeCopy, nodeErrors } from '../contracts';
import { STORE_NAME } from '../store';

export default function OutlineView() {
	const { document, selectedNodeId, validation } = useSelect( ( select ) => select( STORE_NAME ).getState(), [] );
	const { selectNode } = useDispatch( STORE_NAME );

	return (
		<div className="eit-system-outline" aria-label={ __( 'System outline', 'elementor-implementation-toolkit' ) }>
			{ Object.entries( laneLabels ).map( ( [ lane, label ] ) => {
				const nodes = document.nodes.filter( ( node ) => node.lane === lane );
				return (
					<section key={ lane }>
						<h3>{ label }</h3>
						{ nodes.length ? <ul>{ nodes.map( ( node ) => {
							const errors = nodeErrors( validation, node.id );
							const issueText = errors.length ? sprintf( _n( '%d issue', '%d issues', errors.length, 'elementor-implementation-toolkit' ), errors.length ) : '';
							return <li key={ node.id }><Button variant={ selectedNodeId === node.id ? 'primary' : 'secondary' } onClick={ () => selectNode( node.id ) }><span><strong>{ node.name }</strong><small>{ nodeCopy[ node.type ]?.[ 1 ] }{ issueText ? ` · ${ issueText }` : '' }</small></span></Button></li>;
						} ) }</ul> : <p>{ __( 'No nodes in this lane.', 'elementor-implementation-toolkit' ) }</p> }
					</section>
				);
			} ) }
			<section className="eit-system-outline__connections">
				<h3>{ __( 'Executable connections', 'elementor-implementation-toolkit' ) }</h3>
				{ document.connections.length ? <ol>{ document.connections.map( ( connection ) => {
					const source = document.nodes.find( ( node ) => node.id === connection.from );
					const target = document.nodes.find( ( node ) => node.id === connection.to );
					return <li key={ connection.id }><strong>{ source?.name }</strong> → <strong>{ target?.name }</strong><small>{ humanize( connection.type ) }</small></li>;
				} ) }</ol> : <p>{ __( 'No connections yet.', 'elementor-implementation-toolkit' ) }</p> }
			</section>
		</div>
	);
}
