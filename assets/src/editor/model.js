import { $, normalizeRepeaterRows } from './runtime.js';

export function getEditedElementView() {
  if (!window.elementor?.getPanelView) {
    return null;
  }

  const panel = window.elementor.getPanelView();
  const page = panel?.getCurrentPageView ? panel.getCurrentPageView() : null;
  return page?.getOption ? (page.getOption('editedElementView') || null) : null;
}

function getWidgetType(view) {
  if (view?.model?.get) {
    return view.model.get('widgetType');
  }
  if (view?.container?.model?.get) {
    return view.container.model.get('widgetType');
  }
  return '';
}

export function getEditedFilterControllerContainer() {
  const view = getEditedElementView();
  if (getWidgetType(view) !== 'eit-filter-controller') {
    return null;
  }
  return view?.getContainer ? view.getContainer() : (view?.container || null);
}

function readModelValue(model, key) {
  if (!model) {
    return undefined;
  }
  if (model.get) {
    return model.get(key);
  }
  if (model.attributes && model.attributes[key] !== undefined) {
    return model.attributes[key];
  }
  return model[key];
}

function normalizeFilterRows(filters) {
  if (!filters) {
    return null;
  }

  if (!Array.isArray(filters) && typeof filters === 'object' && readModelValue(filters, 'type') !== undefined) {
    filters = [filters];
  }

  const rows = normalizeRepeaterRows(filters);
  if (!rows) {
    return null;
  }

  return rows.filter(Boolean).map((filter) => ({ type: readModelValue(filter, 'type') || 'search' }));
}

function readFilterRowsFromPanel() {
  const rows = [];
  const $control = $('.elementor-control-filters');
  if (!$control.length) {
    return null;
  }

  $control.find('select[data-setting="type"]').each(function collectType() {
    rows.push({ type: this.value || 'search' });
  });

  return rows.length ? rows : null;
}

export function getFilterRows(container) {
  const panelRows = readFilterRowsFromPanel();
  if (panelRows !== null) {
    return panelRows;
  }

  const filters = container?.settings?.get ? container.settings.get('filters') : null;
  return normalizeFilterRows(filters);
}
