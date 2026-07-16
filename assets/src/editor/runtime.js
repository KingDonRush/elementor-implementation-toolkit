export const $ = window.jQuery;
export const config = window.eitEditorConfig || {};
export const i18n = config.i18n || {};
export const filterTypeDefinitions = config.filterTypes || {};
export const dynamicTagCatalog = window.eitDynamicTagCatalog || { contextEntityId: '', entities: {} };

export function isTruthy(value) {
  return value === true || value === 1 || ['1', 'yes', 'on', 'true'].includes(value);
}

export function getContainerSettings(container) {
  if (!container?.settings) {
    return {};
  }

  return container.settings.toJSON ? (container.settings.toJSON() || {}) : {};
}

export function getSetting(settings, key, fallback) {
  return settings[key] !== undefined && settings[key] !== null ? settings[key] : fallback;
}

export function getDynamicSetting(settings, key) {
  const dynamicSettings = getSetting(settings, '__dynamic__', {});
  return dynamicSettings && typeof dynamicSettings === 'object'
    ? getSetting(dynamicSettings, key, '')
    : '';
}

export function normalizeRepeaterRows(rows) {
  if (rows === null || rows === undefined) {
    return null;
  }

  if (rows.toJSON) {
    rows = rows.toJSON();
  } else if (rows.models) {
    rows = rows.models;
  }

  if (!Array.isArray(rows) && typeof rows === 'object') {
    rows = Object.keys(rows).map((key) => rows[key]);
  }

  if (!Array.isArray(rows)) {
    return null;
  }

  return rows.map((row) => {
    if (!row) {
      return {};
    }
    if (row.toJSON) {
      return row.toJSON();
    }
    return row.attributes || row;
  });
}
