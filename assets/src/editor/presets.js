import {
  $, config, getContainerSettings, getDynamicSetting, getSetting, i18n, isTruthy, normalizeRepeaterRows,
} from './runtime.js';
import { getEditedFilterControllerContainer } from './model.js';
import { markEditorCompatFallback } from './compat.js';
import { scheduleFilterTypeSync } from './cadence.js';

function sortValuePart(value) {
  return String(value || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^_+|_+$/g, '');
}

function sortOptionValue(option) {
  const source = sortValuePart(getSetting(option, 'source', 'default')) || 'default';
  const direction = sortValuePart(getSetting(option, 'direction', 'asc')) === 'desc' ? 'desc' : 'asc';
  let key = sortValuePart(getSetting(option, 'key', ''));
  let dataType = sortValuePart(getSetting(option, 'data_type', 'text')) || 'text';

  if (source === 'default') return 'default';
  if (source === 'title' || source === 'date') return `${source}_${direction}`;
  if (source === 'numeric') {
    key = key || 'sort';
    return key === 'sort' ? `numeric_${direction}` : `data_${key}_number_${direction}`;
  }
  if (source === 'rating') {
    key = key || 'rating';
    return key === 'rating' ? `rating_${direction}` : `data_${key}_number_${direction}`;
  }
  if (source === 'data' && key) {
    dataType = ['text', 'number', 'date'].includes(dataType) ? dataType : 'text';
    return `data_${key}_${dataType}_${direction}`;
  }
  return '';
}

function defaultSortLines() {
  return 'default|Default\ntitle_asc|Title A-Z\ntitle_desc|Title Z-A\ndate_desc|Newest\nnumeric_asc|Lowest value\nnumeric_desc|Highest value';
}

function normalizeSortLines(lines) {
  return String(lines || '').split(/\r\n|\r|\n/).map((line) => line.trim()).filter(Boolean).join('\n');
}

function compileSortOptions(items, fallback) {
  const rows = normalizeRepeaterRows(items);
  if (rows === null) {
    return fallback || '';
  }

  const compiled = rows.map((option) => {
    const label = getSetting(option, 'label', '');
    const value = sortOptionValue(option);
    return label && value ? `${value}|${label}` : '';
  }).filter(Boolean).join('\n');
  const normalizedFallback = normalizeSortLines(fallback);

  if (normalizeSortLines(compiled) === normalizeSortLines(defaultSortLines())
    && normalizedFallback && normalizedFallback !== normalizeSortLines(defaultSortLines())) {
    return normalizedFallback;
  }
  return compiled;
}

function mapWidgetFiltersToPreset(filters) {
  const rows = normalizeRepeaterRows(filters);
  if (!rows) {
    return [];
  }

  return rows.map((filter = {}) => ({
    enabled: true,
    label: getSetting(filter, 'label', 'Filter'),
    type: getSetting(filter, 'type', 'search'),
    field_binding: getSetting(filter, 'field_binding', ''),
    field_binding_dynamic: getDynamicSetting(filter, 'field_binding') || getSetting(filter, 'field_binding_dynamic', ''),
    key: getSetting(filter, 'key', ''),
    resolved_key: getSetting(filter, 'resolved_key', ''),
    key_source: getSetting(filter, 'key_source', ''),
    source: getSetting(filter, 'source', 'visible_text'),
    placeholder: getSetting(filter, 'placeholder', ''),
    options: getSetting(filter, 'options', ''),
    radio_show_all: isTruthy(getSetting(filter, 'radio_show_all', '')),
    radio_all_label: getSetting(filter, 'radio_all_label', 'All'),
    range_min: getSetting(filter, 'range_min', 0),
    range_max: getSetting(filter, 'range_max', 100),
    range_step: getSetting(filter, 'range_step', 1),
    layout_width: getSetting(filter, 'layout_width', 100),
    show_label: isTruthy(getSetting(filter, 'show_label', 'yes')),
  }));
}

function buildPresetPayload(settings) {
  const showApply = isTruthy(getSetting(settings, 'show_apply', ''));
  return {
    operation: 'create',
    after_save: getSetting(settings, 'preset_save_behavior', 'link') || 'link',
    preset: {
      name: getSetting(settings, 'preset_save_name', ''),
      slug: '',
      description: '',
      target_selector: getSetting(settings, 'target_selector', ''),
      item_selector: getSetting(settings, 'item_selector', ''),
      apply_mode: showApply ? 'button' : 'auto',
      search_debounce_ms: getSetting(settings, 'search_debounce_ms', 250),
      sync_url: isTruthy(getSetting(settings, 'sync_url', 'yes')),
      per_page: getSetting(settings, 'per_page', 24),
      show_result_count: isTruthy(getSetting(settings, 'show_result_count', 'yes')),
      result_count_text: getSetting(settings, 'result_count_text', '{count} results'),
      show_active_chips: isTruthy(getSetting(settings, 'show_active_chips', 'yes')),
      show_sort: isTruthy(getSetting(settings, 'show_sort', 'yes')),
      sort_label: getSetting(settings, 'sort_label', 'Sort by'),
      sort_options: compileSortOptions(getSetting(settings, 'sort_options_items', null), getSetting(settings, 'sort_options', '')),
      apply_text: getSetting(settings, 'apply_text', 'Apply filters'),
      reset_text: getSetting(settings, 'reset_text', 'Reset'),
      empty_text: getSetting(settings, 'empty_text', 'No matching items found.'),
      pagination_type: getSetting(settings, 'pagination_type', 'numbers'),
      previous_text: getSetting(settings, 'previous_text', 'Previous'),
      next_text: getSetting(settings, 'next_text', 'Next'),
      filters: mapWidgetFiltersToPreset(getSetting(settings, 'filters', [])),
    },
    source_widget: {
      element_id: getSetting(settings, '_element_id', ''),
      document_id: window.elementor?.config?.document?.id || 0,
    },
  };
}

function setActionStatus($button, message, state) {
  let $status = $button.closest('[data-eit-editor-action]').find('[data-eit-action-status]');
  if (!$status.length) {
    $status = $button.closest('.eit-editor-save-preset').find('[data-eit-save-preset-status]');
  }
  $status.removeClass('is-error is-success is-loading').addClass(state ? `is-${state}` : '').text(message || '');
}

function upsertPresetOption(preset) {
  if (!preset?.id) return;
  $('select[data-setting="filter_preset"]').each(function addOption() {
    const exists = Array.from(this.options).some((option) => option.value === preset.id);
    if (!exists) this.add(new Option(preset.name || preset.id, preset.id));
  });
}

function setEditorSettings(container, settings) {
  if (!container || !settings) return;
  const syncTypes = Object.prototype.hasOwnProperty.call(settings, 'filters');

  Object.keys(settings).forEach((controlId) => {
    const input = document.querySelector(`[data-setting="${controlId}"]`);
    const value = settings[controlId];
    if (input && typeof value !== 'object' && input.value !== value) {
      input.value = value;
      $(input).trigger('input').trigger('change');
    }
  });

  if (window.$e?.run) {
    try {
      window.$e.run('document/elements/settings', {
        container,
        settings,
        options: { render: false, renderUI: true },
      });
      if (syncTypes) scheduleFilterTypeSync();
      return;
    } catch (error) {
      markEditorCompatFallback('settings-command');
    }
  }

  container.settings?.set?.(settings);
  if (syncTypes) scheduleFilterTypeSync();
}

export function handleSavePreset(event) {
  event.preventDefault();
  const $button = $(event.currentTarget);
  const container = getEditedFilterControllerContainer();
  const payload = buildPresetPayload(getContainerSettings(container));

  if (!config.canManagePresets || !String(payload.preset.name || '').trim()) {
    const message = config.canManagePresets
      ? (i18n.presetNameRequired || 'Add a preset name before saving.')
      : (i18n.presetSaveFailed || 'Could not save preset.');
    setActionStatus($button, message, 'error');
    return;
  }

  $button.prop('disabled', true);
  setActionStatus($button, i18n.presetSaving || 'Saving preset...', 'loading');
  $.ajax({
    url: config.presetSaveUrl || `${config.restUrl || ''}filter-presets`,
    method: 'POST',
    contentType: 'application/json',
    data: JSON.stringify(payload),
    beforeSend: (xhr) => config.restNonce && xhr.setRequestHeader('X-WP-Nonce', config.restNonce),
  }).done((response = {}) => {
    upsertPresetOption(response.preset);
    setEditorSettings(container, response.editor_update || {});
    setActionStatus($button, i18n.presetSaved || 'Preset saved.', 'success');
  }).fail((xhr) => {
    setActionStatus($button, xhr?.responseJSON?.message || i18n.presetSaveFailed || 'Could not save preset.', 'error');
  }).always(() => $button.prop('disabled', false));
}

export function handleImportPreset(event) {
  event.preventDefault();
  const $button = $(event.currentTarget);
  const container = getEditedFilterControllerContainer();
  const settings = getContainerSettings(container);
  const presetId = getSetting(settings, 'filter_preset', '');
  const localFilters = getSetting(settings, 'filters', []);

  if (!config.canManagePresets || !presetId) {
    const message = presetId
      ? (i18n.presetImportFailed || 'Could not import preset.')
      : (i18n.presetSelectRequired || 'Select a preset first.');
    setActionStatus($button, message, 'error');
    return;
  }
  if (Array.isArray(localFilters) && localFilters.length
    && !window.confirm(i18n.presetImportConfirm || 'Importing this preset will replace the current local widget filter controls. Continue?')) {
    return;
  }

  $button.prop('disabled', true);
  setActionStatus($button, i18n.presetImporting || 'Importing preset...', 'loading');
  $.ajax({
    url: `${config.restUrl || ''}filter-presets/${encodeURIComponent(presetId)}`,
    method: 'GET',
    beforeSend: (xhr) => config.restNonce && xhr.setRequestHeader('X-WP-Nonce', config.restNonce),
  }).done((response = {}) => {
    setEditorSettings(container, response.widget_settings || {});
    setActionStatus($button, i18n.presetImported || 'Preset imported as local widget controls.', 'success');
  }).fail((xhr) => {
    setActionStatus($button, xhr?.responseJSON?.message || i18n.presetImportFailed || 'Could not import preset.', 'error');
  }).always(() => $button.prop('disabled', false));
}
