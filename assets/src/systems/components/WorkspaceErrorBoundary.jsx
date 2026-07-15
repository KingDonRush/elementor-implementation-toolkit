import { Component } from 'react';
import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

export default class WorkspaceErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { error: null };
	}

	static getDerivedStateFromError( error ) {
		return { error };
	}

	render() {
		if ( ! this.state.error ) return this.props.children;

		return (
			<div className="eit-system-recovery" role="alert">
				<Notice status="error" isDismissible={ false }>
					<h2>{ __( 'The system workspace could not be displayed.', 'elementor-implementation-toolkit' ) }</h2>
					<p>{ __( 'Your saved Blueprint was not changed. Return to Systems and try opening it again.', 'elementor-implementation-toolkit' ) }</p>
					<p><code>{ this.state.error?.message || __( 'Unknown rendering error', 'elementor-implementation-toolkit' ) }</code></p>
					<Button variant="secondary" onClick={ this.props.onRecover }>{ __( 'Return to Systems', 'elementor-implementation-toolkit' ) }</Button>
				</Notice>
			</div>
		);
	}
}
