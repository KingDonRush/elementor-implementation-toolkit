import { $, dynamicTagCatalog } from './runtime.js';

let panelObserver = null;
let refreshQueued = false;
let installed = false;

export function compatibleFieldOptions(catalog, entityId, category) {
  const fields = catalog?.entities?.[entityId]?.fields || {};
  return Object.fromEntries(Object.entries(fields).filter(([, field]) => (
    category === 'all' || (field.categories || []).includes(category)
  )).map(([fieldId, field]) => [fieldId, field.label]));
}

function visibleControl(name) {
  return $(`.elementor-control-${name}:visible`).last();
}

function contextualControl($entityControl, name) {
  const $stack = $entityControl.closest('.elementor-controls-stack');
  const $control = $stack.find(`.elementor-control-${name}`).last();
  return $control.length ? $control : $(`.elementor-control-${name}`).last();
}

function replaceFieldOptions($select, options, selectedValue, shouldReset) {
  const currentOptions = Object.fromEntries($select.find('option').slice(1).map((index, option) => [option.value, option.textContent]).get());
  const unchanged = JSON.stringify(currentOptions) === JSON.stringify(options);
  if (unchanged) return;

  $select.empty().append($('<option/>', {
    value: '',
    text: 'Select a published Field',
  }));
  Object.entries(options).forEach(([value, label]) => {
    $select.append($('<option/>', { value, text: label }));
  });

  const nextValue = Object.prototype.hasOwnProperty.call(options, selectedValue) ? selectedValue : '';
  $select.val(nextValue).trigger('change.select2');
  if (shouldReset && selectedValue && !nextValue) {
    $select.trigger('change');
  }
}

export function syncEntityFieldControls(shouldReset = false) {
  const $entityControl = visibleControl('entity_id');
  const $fieldControl = contextualControl($entityControl, 'field_id');
  const $categoryControl = contextualControl($entityControl, 'eit_field_category');
  if (!$entityControl.length || !$fieldControl.length || !$categoryControl.length) return;

  const $entity = $entityControl.find('[data-setting="entity_id"]');
  const $field = $fieldControl.find('select[data-setting="field_id"]');
  const category = $categoryControl.find('[data-setting="eit_field_category"]').val() || 'all';
  if (!$entity.length || !$field.length) return;

  const entityId = $entity.val() || dynamicTagCatalog.contextEntityId || '';
  const selectedValue = $field.val() || $field.data('eitSelectedField') || '';
  if (selectedValue) $field.data('eitSelectedField', selectedValue);
  const options = compatibleFieldOptions(dynamicTagCatalog, entityId, category);
  replaceFieldOptions($field, options, selectedValue, shouldReset);
}

function queueRefresh() {
  if (!installed || refreshQueued) return;
  refreshQueued = true;
  window.queueMicrotask(() => {
    refreshQueued = false;
    if (!installed) return;
    syncEntityFieldControls(false);
  });
}

export function installDynamicTagContext() {
  uninstallDynamicTagContext();
  installed = true;
  $(document).on('change.eitEntityContext', '.elementor-control-entity_id [data-setting="entity_id"]', () => {
    syncEntityFieldControls(true);
  });

  const bindObserver = () => {
    panelObserver?.disconnect();
    panelObserver = null;
    const panel = document.querySelector('#elementor-panel-content-wrapper');
    if (!panel || !window.MutationObserver) return;
    panelObserver = new MutationObserver(queueRefresh);
    panelObserver.observe(panel, { childList: true, subtree: true });
  };
  $(window).on('elementor:init.eitEntityContext', bindObserver);
  bindObserver();
  queueRefresh();
  return uninstallDynamicTagContext;
}

export function uninstallDynamicTagContext() {
  installed = false;
  refreshQueued = false;
  $(document).off('.eitEntityContext');
  $(window).off('.eitEntityContext');
  panelObserver?.disconnect();
  panelObserver = null;
}
