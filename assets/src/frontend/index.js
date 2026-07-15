import { Controller } from './controller.js';
import { installControls } from './controls.js';
import { $ } from './runtime.js';
import { installView } from './view.js';

installControls(Controller);
installView(Controller);

$(() => {
  $('.eit-filter-controller').each((index, element) => new Controller(element));
});
