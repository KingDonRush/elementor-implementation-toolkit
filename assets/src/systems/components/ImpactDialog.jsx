import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';
import { humanize, laneLabels, nodeTypeLabels } from '../contracts';

export default function ImpactDialog( { prepared, document, version, busy, onClose, onPublish } ) {
	if ( ! prepared ) {
		return null;
	}
	const impact = prepared.impact;
	const summary = impact.summary;
	const runtime = impact.map?.summary || {};
	const nodes = new Map( ( document?.nodes || [] ).map( ( node ) => [ node.id, node ] ) );
	const affectedParts = impact.affected_node_ids.map( ( id ) => ( { id, node: nodes.get( id ) } ) );

	return (
		<Modal title={ impact.blocked ? __( 'Publication is blocked', 'elementor-implementation-toolkit' ) : __( 'Review compiler impact', 'elementor-implementation-toolkit' ) } onRequestClose={ onClose } shouldCloseOnClickOutside={ false }>
			<p>{ __( 'This preview was produced by the server compiler from the saved draft. No runtime mutation has happened.', 'elementor-implementation-toolkit' ) }</p>
			<dl className="eit-impact-summary">
				<div><dt>{ __( 'Artifacts added', 'elementor-implementation-toolkit' ) }</dt><dd>{ summary.added }</dd></div>
				<div><dt>{ __( 'Artifacts changed', 'elementor-implementation-toolkit' ) }</dt><dd>{ summary.changed }</dd></div>
				<div><dt>{ __( 'Artifacts removed', 'elementor-implementation-toolkit' ) }</dt><dd>{ summary.removed }</dd></div>
				<div><dt>{ __( 'Field binding changes', 'elementor-implementation-toolkit' ) }</dt><dd>{ summary.binding_changes }</dd></div>
			</dl>
			{ impact.map ? <div><h3>{ __( 'Runtime consumers', 'elementor-implementation-toolkit' ) }</h3><dl className="eit-impact-summary eit-impact-summary--runtime">
				<div><dt>{ __( 'Pages', 'elementor-implementation-toolkit' ) }</dt><dd>{ runtime.pages || 0 }</dd></div>
				<div><dt>{ __( 'Templates', 'elementor-implementation-toolkit' ) }</dt><dd>{ runtime.templates || 0 }</dd></div>
				<div><dt>{ __( 'Widgets', 'elementor-implementation-toolkit' ) }</dt><dd>{ runtime.widgets || 0 }</dd></div>
				<div><dt>{ __( 'Fields', 'elementor-implementation-toolkit' ) }</dt><dd>{ runtime.fields || 0 }</dd></div>
				<div><dt>{ __( 'Records', 'elementor-implementation-toolkit' ) }</dt><dd>{ runtime.records || 0 }</dd></div>
				<div><dt>{ __( 'Adapters', 'elementor-implementation-toolkit' ) }</dt><dd>{ runtime.adapters || 0 }</dd></div>
			</dl><details><summary>{ __( 'Inspect affected runtime identities', 'elementor-implementation-toolkit' ) }</summary><ul className="eit-impact-list">
				{ [ ...( impact.map.pages || [] ), ...( impact.map.templates || [] ) ].map( ( document ) => <li key={ `document-${ document.id }` }>{ document.title || `#${ document.id }` } <code>#{ document.id }</code></li> ) }
				{ ( impact.map.widgets || [] ).map( ( widget ) => <li key={ `widget-${ widget.document_id }-${ widget.id }` }>{ widget.type } <code>{ widget.id }</code></li> ) }
				{ ( impact.map.fields || [] ).map( ( field ) => <li key={ `field-${ field.id }` }>{ field.name } <code>{ field.id }</code></li> ) }
			</ul><p>{ sprintf( __( '%d Elementor revisions remain backup evidence and are excluded from active usage.', 'elementor-implementation-toolkit' ), impact.map.revision_backup_count || 0 ) }</p></details></div> : null }
			{ affectedParts.length ? <div><h3>{ __( 'Affected system parts', 'elementor-implementation-toolkit' ) }</h3><ul className="eit-impact-list" aria-label={ __( 'Affected system parts', 'elementor-implementation-toolkit' ) }>{ affectedParts.map( ( { id, node } ) => <li className="eit-impact-part" key={ id }>
				<strong>{ node?.name || __( 'Removed system component', 'elementor-implementation-toolkit' ) }</strong>
				<span>{ node ? sprintf( __( '%1$s · %2$s', 'elementor-implementation-toolkit' ), laneLabels[ node.lane ] || humanize( node.lane ), nodeTypeLabels[ node.type ] || humanize( node.type ) ) : __( 'No longer present in this draft', 'elementor-implementation-toolkit' ) }</span>
				<details><summary>{ __( 'Technical identity', 'elementor-implementation-toolkit' ) }</summary><code>{ id }</code></details>
			</li> ) }</ul></div> : <p>{ __( 'No compiled artifacts differ from the active version.', 'elementor-implementation-toolkit' ) }</p> }
			{ impact.blockers.length ? <div className="eit-impact-blockers" role="alert"><h3>{ __( 'Corrections required', 'elementor-implementation-toolkit' ) }</h3><ul>{ impact.blockers.map( ( blocker, index ) => <li key={ `${ blocker.code }-${ index }` }>{ blocker.message }</li> ) }</ul></div> : null }
			<div className="eit-modal-actions">
				<Button variant="tertiary" onClick={ onClose } disabled={ Boolean( busy ) }>{ impact.blocked ? __( 'Return to system', 'elementor-implementation-toolkit' ) : __( 'Cancel', 'elementor-implementation-toolkit' ) }</Button>
				{ ! impact.blocked ? <Button variant="primary" onClick={ onPublish } isBusy={ 'publish' === busy } disabled={ Boolean( busy ) }>{ sprintf( __( 'Publish version %d', 'elementor-implementation-toolkit' ), version ) }</Button> : null }
			</div>
		</Modal>
	);
}
