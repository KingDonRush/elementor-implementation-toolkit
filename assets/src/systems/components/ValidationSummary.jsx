import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { STORE_NAME } from '../store';

export default function ValidationSummary() {
	const validation = useSelect( ( select ) => select( STORE_NAME ).getState().validation, [] );
	const { selectNode } = useDispatch( STORE_NAME );
	if ( ! validation ) {
		return <div className="eit-validation-state is-neutral"><strong>{ __( 'Not validated', 'elementor-implementation-toolkit' ) }</strong><span>{ __( 'Save and validate to obtain compiler evidence.', 'elementor-implementation-toolkit' ) }</span></div>;
	}
	if ( validation.valid ) {
		return <div className="eit-validation-state is-valid" role="status"><strong>{ __( 'Validation passed', 'elementor-implementation-toolkit' ) }</strong><span>{ __( 'The saved graph can proceed to a real impact review.', 'elementor-implementation-toolkit' ) }</span></div>;
	}
	return <div className="eit-validation-state is-invalid" role="alert"><strong>{ __( 'Publication blocked', 'elementor-implementation-toolkit' ) }</strong><span>{ __( 'Correct the affected nodes, then validate again.', 'elementor-implementation-toolkit' ) }</span><ul>{ validation.errors.slice( 0, 6 ).map( ( error, index ) => <li key={ `${ error.code }-${ index }` }>{ error.node_id ? <Button variant="link" onClick={ () => selectNode( error.node_id ) }>{ error.message }</Button> : error.message }</li> ) }</ul>{ validation.errors.length > 6 ? <small>{ validation.errors.length - 6 } { __( 'additional issues remain.', 'elementor-implementation-toolkit' ) }</small> : null }</div>;
}
