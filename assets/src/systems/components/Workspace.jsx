import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { humanize } from '../contracts';
import { STORE_NAME } from '../store';
import Inspector from './Inspector';
import MapCanvas from './MapCanvas';
import NodePalette from './NodePalette';
import OutlineView from './OutlineView';
import ValidationSummary from './ValidationSummary';

export default function Workspace( { onBack, onSave, onValidate, onImpact } ) {
	const state = useSelect( ( select ) => select( STORE_NAME ).getState(), [] );
	const { setView } = useDispatch( STORE_NAME );
	const { current, document, dirty, busy, view } = state;

	return (
		<section className="eit-system-workspace" aria-busy={ Boolean( busy ) }>
			<header className="eit-system-workspace__head">
				<div className="eit-system-breadcrumb"><Button variant="link" onClick={ onBack }>{ __( 'Systems', 'elementor-implementation-toolkit' ) }</Button><span aria-hidden="true">/</span><span>{ document.name }</span></div>
				<div className="eit-system-title"><div><h2>{ document.name }</h2><p>{ dirty ? __( 'Unsaved draft changes', 'elementor-implementation-toolkit' ) : __( 'Saved draft', 'elementor-implementation-toolkit' ) } · { humanize( current.status ) }</p></div><div className="eit-system-actions"><Button variant="secondary" onClick={ onSave } isBusy={ 'save' === busy } disabled={ Boolean( busy ) || ! dirty }>{ __( 'Save draft', 'elementor-implementation-toolkit' ) }</Button><Button variant="tertiary" onClick={ onValidate } isBusy={ 'validate' === busy } disabled={ Boolean( busy ) }>{ __( 'Validate', 'elementor-implementation-toolkit' ) }</Button><Button variant="primary" onClick={ onImpact } isBusy={ 'impact' === busy } disabled={ Boolean( busy ) }>{ __( 'Review impact', 'elementor-implementation-toolkit' ) }</Button></div></div>
				<div className="eit-system-view-tabs" role="tablist" aria-label={ __( 'System workspace view', 'elementor-implementation-toolkit' ) }><button type="button" role="tab" aria-selected={ 'canvas' === view } onClick={ () => setView( 'canvas' ) }>{ __( 'Map', 'elementor-implementation-toolkit' ) }</button><button type="button" role="tab" aria-selected={ 'outline' === view } onClick={ () => setView( 'outline' ) }>{ __( 'Outline', 'elementor-implementation-toolkit' ) }</button></div>
			</header>
			<ValidationSummary />
			<div className="eit-system-workspace__body"><NodePalette /><div id="eit-system-main" className="eit-system-main" role="region" aria-label={ __( 'System workspace', 'elementor-implementation-toolkit' ) } tabIndex="-1">{ 'canvas' === view ? <MapCanvas /> : <OutlineView /> }</div><Inspector /></div>
		</section>
	);
}
