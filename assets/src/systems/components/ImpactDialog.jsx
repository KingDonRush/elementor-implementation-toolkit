import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';

export default function ImpactDialog( { prepared, version, busy, onClose, onPublish } ) {
	if ( ! prepared ) {
		return null;
	}
	const impact = prepared.impact;
	const summary = impact.summary;

	return (
		<Modal title={ impact.blocked ? __( 'Publication is blocked', 'elementor-implementation-toolkit' ) : __( 'Review compiler impact', 'elementor-implementation-toolkit' ) } onRequestClose={ onClose } shouldCloseOnClickOutside={ false }>
			<p>{ __( 'This preview was produced by the server compiler from the saved draft. No runtime mutation has happened.', 'elementor-implementation-toolkit' ) }</p>
			<dl className="eit-impact-summary">
				<div><dt>{ __( 'Artifacts added', 'elementor-implementation-toolkit' ) }</dt><dd>{ summary.added }</dd></div>
				<div><dt>{ __( 'Artifacts changed', 'elementor-implementation-toolkit' ) }</dt><dd>{ summary.changed }</dd></div>
				<div><dt>{ __( 'Artifacts removed', 'elementor-implementation-toolkit' ) }</dt><dd>{ summary.removed }</dd></div>
				<div><dt>{ __( 'Field binding changes', 'elementor-implementation-toolkit' ) }</dt><dd>{ summary.binding_changes }</dd></div>
			</dl>
			{ impact.affected_node_ids.length ? <div><h3>{ __( 'Affected nodes', 'elementor-implementation-toolkit' ) }</h3><ul className="eit-impact-list">{ impact.affected_node_ids.map( ( id ) => <li key={ id }><code>{ id }</code></li> ) }</ul></div> : <p>{ __( 'No compiled artifacts differ from the active version.', 'elementor-implementation-toolkit' ) }</p> }
			{ impact.blockers.length ? <div className="eit-impact-blockers" role="alert"><h3>{ __( 'Corrections required', 'elementor-implementation-toolkit' ) }</h3><ul>{ impact.blockers.map( ( blocker, index ) => <li key={ `${ blocker.code }-${ index }` }>{ blocker.message }</li> ) }</ul></div> : null }
			<div className="eit-modal-actions">
				<Button variant="tertiary" onClick={ onClose } disabled={ Boolean( busy ) }>{ impact.blocked ? __( 'Return to system', 'elementor-implementation-toolkit' ) : __( 'Cancel', 'elementor-implementation-toolkit' ) }</Button>
				{ ! impact.blocked ? <Button variant="primary" onClick={ onPublish } isBusy={ 'publish' === busy } disabled={ Boolean( busy ) }>{ sprintf( __( 'Publish version %d', 'elementor-implementation-toolkit' ), version ) }</Button> : null }
			</div>
		</Modal>
	);
}
