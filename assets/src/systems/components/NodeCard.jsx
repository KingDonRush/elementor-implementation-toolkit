import { Handle, Position } from '@xyflow/react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { laneLabels } from '../contracts';

export default function NodeCard( { data } ) {
	const { contract, copy, errors, validated } = data;
	const health = errors.length
		? sprintf( _n( '%d validation issue', '%d validation issues', errors.length, 'elementor-implementation-toolkit' ), errors.length )
		: ( validated ? __( 'Validated', 'elementor-implementation-toolkit' ) : __( 'Not validated', 'elementor-implementation-toolkit' ) );

	return (
		<div className={ `eit-system-node eit-system-node--${ contract.lane } ${ errors.length ? 'has-errors' : '' }` }>
			<Handle type="target" position={ Position.Left } />
			<div className="eit-system-node__lane">{ laneLabels[ contract.lane ] || contract.lane }</div>
			<strong>{ contract.name }</strong>
			<span>{ copy?.[ 0 ] || contract.type }</span>
			<dl>
				<div><dt>{ __( 'Health', 'elementor-implementation-toolkit' ) }</dt><dd>{ health }</dd></div>
				<div><dt>{ __( 'Output', 'elementor-implementation-toolkit' ) }</dt><dd>{ copy?.[ 1 ] || __( 'Runtime contract', 'elementor-implementation-toolkit' ) }</dd></div>
			</dl>
			<Handle type="source" position={ Position.Right } />
		</div>
	);
}
