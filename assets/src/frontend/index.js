import { Controller } from './controller.js';
import { installControls } from './controls.js';
import { installFacets } from './facets.js';
import { $ } from './runtime.js';
import { installView } from './view.js';
import {
  initializeFrontendFallbacks,
  installElementorLifecycle,
} from './elementor-lifecycle.js';

installControls(Controller);
installFacets(Controller);
installView(Controller);
installElementorLifecycle();

$(() => {
  initializeFrontendFallbacks(document);
});
