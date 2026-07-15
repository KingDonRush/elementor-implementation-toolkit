import { __ } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';

export default function DiscardDialog( { open, onCancel, onDiscard } ) {
	if ( ! open ) return null;
	return (
		<Modal title={ __( 'Discard unsaved draft changes?', 'elementor-implementation-toolkit' ) } onRequestClose={ onCancel } shouldCloseOnClickOutside={ false }>
			<p>{ __( 'Changes made since the last draft save will be lost. Published runtime will not be affected.', 'elementor-implementation-toolkit' ) }</p>
			<div className="eit-modal-actions">
				<Button variant="tertiary" onClick={ onCancel }>{ __( 'Keep editing', 'elementor-implementation-toolkit' ) }</Button>
				<Button isDestructive variant="primary" onClick={ onDiscard }>{ __( 'Discard changes', 'elementor-implementation-toolkit' ) }</Button>
			</div>
		</Modal>
	);
}
