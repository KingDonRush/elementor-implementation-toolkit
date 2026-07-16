import { $, filterTypeDefinitions } from './runtime.js';
import { getEditedFilterControllerContainer, getFilterRows } from './model.js';
import { markEditorCompatFallback } from './compat.js';

const stateControls = [
  'eit_filter_has_field_controls',
  'eit_filter_has_option_controls',
  'eit_filter_has_checkbox_controls',
  'eit_filter_has_chips_controls',
  'eit_filter_has_radio_controls',
  'eit_filter_has_toggle_controls',
  'eit_filter_has_swatch_controls',
  'eit_filter_has_search_controls',
  'eit_filter_has_select_controls',
  'eit_filter_has_range_controls',
  'eit_filter_has_date_controls',
  'eit_filter_has_rating_controls',
];

let syncTimer = null;
let followupTimer = null;
let hooksBound = false;
let panelObserver = null;
let panelObserverTimer = null;
let legacyPanelHook = null;

function hasType(types, type) {
  return types.includes(type);
}

function typeHasStyleFamily(type, family) {
  const families = filterTypeDefinitions[type]?.styleFamilies || [];
  if (families.includes(family)) return true;
  if (family === 'field') return ['search', 'select', 'range', 'date'].includes(type);
  if (family === 'option') return ['checkbox', 'radio', 'chips', 'toggle', 'swatch', 'rating'].includes(type);
  return false;
}

export function computeFilterTypeFlags(filters) {
  const types = [];
  filters.forEach((filter) => {
    const type = filter?.type || 'search';
    if (!types.includes(type)) types.push(type);
  });

  return {
    eit_filter_has_field_controls: types.some((type) => typeHasStyleFamily(type, 'field')) ? 'yes' : '',
    eit_filter_has_option_controls: types.some((type) => typeHasStyleFamily(type, 'option')) ? 'yes' : '',
    eit_filter_has_checkbox_controls: hasType(types, 'checkbox') ? 'yes' : '',
    eit_filter_has_chips_controls: hasType(types, 'chips') ? 'yes' : '',
    eit_filter_has_radio_controls: hasType(types, 'radio') ? 'yes' : '',
    eit_filter_has_toggle_controls: hasType(types, 'toggle') ? 'yes' : '',
    eit_filter_has_swatch_controls: hasType(types, 'swatch') ? 'yes' : '',
    eit_filter_has_search_controls: hasType(types, 'search') ? 'yes' : '',
    eit_filter_has_select_controls: hasType(types, 'select') ? 'yes' : '',
    eit_filter_has_range_controls: hasType(types, 'range') ? 'yes' : '',
    eit_filter_has_date_controls: hasType(types, 'date') ? 'yes' : '',
    eit_filter_has_rating_controls: hasType(types, 'rating') ? 'yes' : '',
  };
}

function currentFlags(container) {
  return stateControls.reduce((flags, id) => {
    flags[id] = container?.settings?.get ? (container.settings.get(id) || '') : '';
    return flags;
  }, {});
}

function flagsChanged(current, next) {
  return stateControls.some((id) => (current[id] || '') !== (next[id] || ''));
}

function setHiddenInputs(flags, triggerEvents) {
  stateControls.forEach((id) => {
    const input = document.querySelector(`[data-setting="${id}"]`);
    if (!input || input.value === flags[id]) return;
    input.value = flags[id];
    if (triggerEvents) $(input).trigger('input').trigger('change');
  });
}

function applyStylePanelCadence(flags) {
  const $body = $('body');
  $body.addClass('eit-filter-style-cadence-active');
  $body.toggleClass('eit-filter-style-has-field', flags.eit_filter_has_field_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-option', flags.eit_filter_has_option_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-checkbox', flags.eit_filter_has_checkbox_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-chips', flags.eit_filter_has_chips_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-radio', flags.eit_filter_has_radio_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-toggle', flags.eit_filter_has_toggle_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-swatch', flags.eit_filter_has_swatch_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-search', flags.eit_filter_has_search_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-select', flags.eit_filter_has_select_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-range', flags.eit_filter_has_range_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-date', flags.eit_filter_has_date_controls === 'yes');
  $body.toggleClass('eit-filter-style-has-rating', flags.eit_filter_has_rating_controls === 'yes');
}

function clearStylePanelCadence() {
  $('body').removeClass(
    'eit-filter-style-cadence-active eit-filter-style-has-field eit-filter-style-has-option '
    + 'eit-filter-style-has-checkbox eit-filter-style-has-chips eit-filter-style-has-radio '
    + 'eit-filter-style-has-toggle eit-filter-style-has-swatch eit-filter-style-has-search '
    + 'eit-filter-style-has-select eit-filter-style-has-range eit-filter-style-has-date '
    + 'eit-filter-style-has-rating',
  );
}

function syncFilterTypeState() {
  const container = getEditedFilterControllerContainer();
  if (!container) {
    clearStylePanelCadence();
    return;
  }

  const filters = getFilterRows(container);
  if (!filters) {
    clearStylePanelCadence();
    return;
  }

  const flags = computeFilterTypeFlags(filters);
  const current = currentFlags(container);
  setHiddenInputs(flags, false);
  applyStylePanelCadence(flags);
  if (!flagsChanged(current, flags)) return;

  if (window.$e?.run) {
    try {
      window.$e.run('document/elements/settings', {
        container,
        settings: flags,
        options: { render: false, renderUI: true },
      });
      return;
    } catch (error) {
      markEditorCompatFallback('style-state-command');
    }
  }

  container.settings?.set?.(flags);
  setHiddenInputs(flags, true);
}

export function scheduleFilterTypeSync() {
  window.clearTimeout(syncTimer);
  window.clearTimeout(followupTimer);
  syncTimer = window.setTimeout(syncFilterTypeState, 80);
  followupTimer = window.setTimeout(syncFilterTypeState, 320);
}

function bindElementorHooks(onPanelChange) {
  if (hooksBound || !window.elementor?.hooks?.addAction) return;
  hooksBound = true;
  legacyPanelHook = () => {
    onPanelChange();
    scheduleFilterTypeSync();
    window.queueMicrotask(() => bindPanelObserver(onPanelChange));
  };
  window.elementor.hooks.addAction('panel/open_editor/widget/eit-filter-controller', legacyPanelHook);
}

function disconnectPanelObserver() {
  panelObserver?.disconnect();
  panelObserver = null;
  window.clearTimeout(panelObserverTimer);
  panelObserverTimer = null;
}

function bindPanelObserver(onPanelChange) {
  if (panelObserver || !window.MutationObserver || !getEditedFilterControllerContainer()) return;
  const panel = document.querySelector('#elementor-panel-content-wrapper');
  if (!panel) return;

  panelObserver = new MutationObserver(() => {
    window.clearTimeout(panelObserverTimer);
    panelObserverTimer = window.setTimeout(() => {
      panelObserverTimer = null;
      if (!getEditedFilterControllerContainer()) {
        disconnectPanelObserver();
        clearStylePanelCadence();
        return;
      }
      onPanelChange();
      scheduleFilterTypeSync();
    }, 80);
  });
  panelObserver.observe(panel, { childList: true, subtree: true });
}

export function installCadence(onPanelChange) {
  uninstallCadence();
  const refresh = () => {
    bindElementorHooks(onPanelChange);
    if (getEditedFilterControllerContainer()) bindPanelObserver(onPanelChange);
    else disconnectPanelObserver();
    onPanelChange();
    scheduleFilterTypeSync();
  };

  $(document).on('input.eitCadence change.eitCadence click.eitCadence', '.elementor-control-filters', scheduleFilterTypeSync);
  $(document).on(
    'click.eitCadence',
    '.elementor-panel-navigation-tab, .elementor-tab-control-content, .elementor-tab-control-style, .elementor-tab-control-advanced',
    scheduleFilterTypeSync,
  );
  $(window).on('elementor:init.eitCadence', refresh);
  $(refresh);
  return uninstallCadence;
}

export function uninstallCadence() {
  window.clearTimeout(syncTimer);
  window.clearTimeout(followupTimer);
  syncTimer = null;
  followupTimer = null;
  disconnectPanelObserver();
  $(document).off('.eitCadence');
  $(window).off('.eitCadence');
  if (hooksBound && legacyPanelHook && window.elementor?.hooks?.removeAction) {
    window.elementor.hooks.removeAction('panel/open_editor/widget/eit-filter-controller', legacyPanelHook);
  }
  hooksBound = false;
  legacyPanelHook = null;
  clearStylePanelCadence();
}
