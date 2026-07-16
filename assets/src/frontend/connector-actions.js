import { $ } from './runtime.js';
import { cssEscape } from './utils.js';

export function installConnectorActions() {
  $( document ).on( 'click.eitToolkitAction', '[data-eit-action-surface]', ( event ) => {
    const action = event.currentTarget;
    const surfaceId = action.getAttribute( 'data-eit-action-surface' ) || '';
    const intent = action.getAttribute( 'data-eit-action-intent' ) || 'default';
    const workspace = document.querySelector( `[data-eit-entry-workspace][data-surface-id="${ cssEscape( surfaceId ) }"]` );
    const form = workspace?.querySelector( '[data-eit-entry-form]' );
    const submitter = workspace?.querySelector( `[data-eit-intent="${ cssEscape( intent ) }"]` );
    if ( ! form || ! submitter ) {
      workspace?.querySelector( '[data-eit-form-message]' )?.replaceChildren(
        document.createTextNode( window.eitConfig?.i18n?.entryActionUnavailable || 'This action is not available in the current item state.' ),
      );
      return;
    }
    if ( 'function' === typeof form.requestSubmit ) form.requestSubmit( submitter );
    else submitter.click();
  } );
}
