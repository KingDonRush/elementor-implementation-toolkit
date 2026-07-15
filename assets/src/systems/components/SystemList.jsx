import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Button, Modal, TextControl } from '@wordpress/components';

function statusLabel( status ) {
	if ( 'draft' === status ) return __( 'Draft only', 'elementor-implementation-toolkit' );
	if ( 'published' === status ) return __( 'Published and reconciled', 'elementor-implementation-toolkit' );
	if ( 'changes_pending' === status ) return __( 'Draft changes pending', 'elementor-implementation-toolkit' );
	return status;
}

export default function SystemList( { systems, busy, onCreate, onOpen } ) {
	const [ creating, setCreating ] = useState( false );
	const [ name, setName ] = useState( '' );
	const submit = async () => {
		if ( ! name.trim() ) {
			return;
		}
		const created = await onCreate( name.trim() );
		if ( ! created ) return;
		setName( '' );
		setCreating( false );
	};

	return (
		<section className="eit-system-library" aria-busy={ Boolean( busy ) }>
			<div className="eit-system-library__head">
				<div><h2>{ __( 'Executable systems', 'elementor-implementation-toolkit' ) }</h2><p>{ __( 'Each system is one versioned Blueprint, not a loose set of CPT, form and widget configurations.', 'elementor-implementation-toolkit' ) }</p></div>
				<Button variant="primary" onClick={ () => setCreating( true ) } disabled={ Boolean( busy ) }>{ __( 'Create system', 'elementor-implementation-toolkit' ) }</Button>
			</div>
			{ systems.length ? (
				<div className="eit-system-table-wrap"><table className="widefat striped eit-system-table">
					<thead><tr><th>{ __( 'System', 'elementor-implementation-toolkit' ) }</th><th>{ __( 'Observed state', 'elementor-implementation-toolkit' ) }</th><th>{ __( 'Draft revision', 'elementor-implementation-toolkit' ) }</th><th><span className="screen-reader-text">{ __( 'Actions', 'elementor-implementation-toolkit' ) }</span></th></tr></thead>
					<tbody>{ systems.map( ( system ) => <tr key={ system.id }>
						<td><strong>{ system.name }</strong><code>{ system.slug }</code></td>
						<td><span className={ `eit-status-pill eit-status-pill--${ system.status }` }>{ statusLabel( system.status ) }</span></td>
						<td>{ system.draft_revision }</td>
						<td><Button variant="secondary" onClick={ () => onOpen( system.id ) }>{ __( 'Open system', 'elementor-implementation-toolkit' ) }</Button></td>
					</tr> ) }</tbody>
				</table></div>
			) : (
				<div className="eit-system-empty"><h3>{ __( 'No systems yet', 'elementor-implementation-toolkit' ) }</h3><p>{ __( 'Start with one Entity and one Field Group. The map will expose missing connections before anything can reach runtime.', 'elementor-implementation-toolkit' ) }</p><Button variant="primary" onClick={ () => setCreating( true ) }>{ __( 'Create the first system', 'elementor-implementation-toolkit' ) }</Button></div>
			) }
			{ creating ? <Modal title={ __( 'Create an executable system', 'elementor-implementation-toolkit' ) } onRequestClose={ () => setCreating( false ) } shouldCloseOnClickOutside={ false }>
				<TextControl autoFocus label={ __( 'System name', 'elementor-implementation-toolkit' ) } help={ __( 'Use the business concept, such as Properties or Appointments.', 'elementor-implementation-toolkit' ) } value={ name } onChange={ setName } onKeyDown={ ( event ) => { if ( 'Enter' === event.key ) submit(); } } />
				<div className="eit-modal-actions"><Button variant="tertiary" onClick={ () => setCreating( false ) }>{ __( 'Cancel', 'elementor-implementation-toolkit' ) }</Button><Button variant="primary" onClick={ submit } disabled={ ! name.trim() || Boolean( busy ) } isBusy={ 'create' === busy }>{ __( 'Create system', 'elementor-implementation-toolkit' ) }</Button></div>
			</Modal> : null }
		</section>
	);
}
