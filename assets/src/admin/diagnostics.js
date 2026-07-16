( function () {
	'use strict';

	const config = window.eitAdminConfig || {};
	const text = config.i18n || {};

	function request( path, options = {} ) {
		return window.fetch( `${ config.restRoot || '' }${ path }`, {
			...options,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '', ...( options.headers || {} ) },
		} ).then( async ( response ) => {
			const payload = await response.json().catch( () => ( {} ) );
			if ( ! response.ok ) throw new Error( payload.message || text.migrationFailed || 'Request failed.' );
			return payload;
		} );
	}

	document.querySelectorAll( '[data-eit-migration]' ).forEach( ( root ) => {
		const prepare = root.querySelector( '[data-eit-prepare-migration]' );
		const apply = root.querySelector( '[data-eit-apply-migration]' );
		const cancel = root.querySelector( '[data-eit-cancel-migration]' );
		const confirmation = root.querySelector( '[data-eit-migration-confirm]' );
		const summary = root.querySelector( '[data-eit-migration-summary]' );
		const status = root.querySelector( '[data-eit-migration-status]' );
		let token = '';

		const busy = ( active ) => {
			root.setAttribute( 'aria-busy', active ? 'true' : 'false' );
			prepare.disabled = active;
			apply.disabled = active;
		};

		prepare.addEventListener( 'click', async () => {
			const selection = [ ...root.querySelectorAll( '[data-eit-migration-source]:checked' ) ].map( ( input ) => ( { source_type: input.dataset.sourceType, source_key: input.dataset.sourceKey } ) );
			if ( ! selection.length ) {
				status.textContent = text.migrationChoose || 'Choose at least one source.';
				return;
			}
			token = '';
			confirmation.hidden = true;
			busy( true );
			status.textContent = text.migrationPreparing || 'Preparing…';
			try {
				const plan = await request( '/migration/prepare', { method: 'POST', body: JSON.stringify( { selection } ) } );
				token = plan.confirmation_token;
				confirmation.hidden = false;
				summary.textContent = ( text.migrationPrepared || '%d candidates are ready.' ).replace( '%d', String( plan.items.length ) );
				status.textContent = '';
			} catch ( error ) {
				status.textContent = error.message;
			} finally {
				busy( false );
				if ( token ) apply.focus();
			}
		} );

		apply.addEventListener( 'click', async () => {
			if ( ! token ) return;
			busy( true );
			status.textContent = text.migrationApplying || 'Comparing…';
			try {
				const outcome = await request( '/migration/apply', { method: 'POST', body: JSON.stringify( { confirmation_token: token } ) } );
				if ( outcome.failed ) {
					token = '';
					confirmation.hidden = true;
					busy( false );
					status.textContent = ( text.migrationPartial || '%d source imports failed. Review source drift and prepare again.' ).replace( '%d', String( outcome.failed ) );
					prepare.focus();
					return;
				}
				status.textContent = text.migrationComplete || 'Migration recorded.';
				window.location.reload();
			} catch ( error ) {
				status.textContent = error.message;
				busy( false );
			}
		} );

		cancel.addEventListener( 'click', () => {
			token = '';
			confirmation.hidden = true;
			prepare.focus();
		} );
	} );

	document.querySelectorAll( '[data-eit-copy-handoff]' ).forEach( ( button ) => {
		button.addEventListener( 'click', async () => {
			const notes = document.querySelector( '[data-eit-handoff-notes]' );
			const status = document.querySelector( '[data-eit-handoff-status]' );
			if ( ! notes ) return;
			try {
				await navigator.clipboard.writeText( notes.value );
			} catch ( error ) {
				notes.select();
				document.execCommand( 'copy' );
			}
			if ( status ) status.textContent = text.handoffCopied || 'Markdown copied.';
		} );
	} );
}() );
