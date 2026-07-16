import { Controller } from './controller.js';
import { installControls } from './controls.js';
import { installFacets } from './facets.js';
import { $ } from './runtime.js';
import { installView } from './view.js';
import { EntryWorkspace } from './entry-workspace.js';

installControls(Controller);
installFacets(Controller);
installView(Controller);

$(() => {
  $('.eit-filter-controller').each((index, element) => new Controller(element));
	document.querySelectorAll( '[data-eit-entry-workspace]' ).forEach( ( element ) => new EntryWorkspace( element ) );
});
