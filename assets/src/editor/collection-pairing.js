import { $, i18n } from './runtime.js';

let activeWidget = '';
let hookBus = null;
let hookBindings = [];
let previewObserver = null;

const supportedWidgets = [
  'eit-toolkit-filter-surface',
  'eit-toolkit-collection-surface',
];

function previewDocument() {
  return document.querySelector('#elementor-preview-iframe')?.contentDocument || null;
}

function collectionIds(doc) {
  return Array.from(doc?.querySelectorAll('[data-eit-collection-surface]') || [])
    .map((node) => node.getAttribute('data-eit-collection-surface') || '')
    .filter(Boolean);
}

function hasRenderedPairingError(doc) {
  return Boolean(doc?.querySelector('.elementor-widget-eit-toolkit-filter-surface .eit-connector-notice[role="alert"]'));
}

export function pairingStatus(ids, override, type, ownCollectionId = '', renderedError = false) {
  if (type === 'eit-toolkit-collection-surface') {
    if (!ownCollectionId) return { state: 'error', code: 'unconfigured' };
    if (renderedError) return { state: 'error', code: 'mismatch' };
    return { state: 'success', code: 'authority' };
  }
  if (!ids.length) return { state: 'error', code: 'missing' };
  if (ids.length === 1) {
    return override && override !== ids[0]
      ? { state: 'error', code: 'mismatch' }
      : { state: 'success', code: override ? 'compatible' : 'automatic' };
  }
  const matches = ids.filter((id) => id === override);
  return matches.length === 1
    ? { state: 'success', code: 'disambiguated' }
    : { state: 'error', code: override ? 'mismatch' : 'ambiguous' };
}

function statusMessage(code) {
  const messages = {
    automatic: i18n.collectionPairAutomatic || 'Connected automatically to the Collection Surface on this document.',
    compatible: i18n.collectionPairCompatible || 'The saved 1.x binding matches the Collection Surface.',
    disambiguated: i18n.collectionPairDisambiguated || 'Connected to exactly one matching Collection Surface.',
    authority: i18n.collectionPairAuthority || 'This Surface owns the Collection contract for connected filters.',
    missing: i18n.collectionPairMissing || 'Add a Toolkit Collection Surface to complete this connection.',
    unconfigured: i18n.collectionPairUnconfigured || 'Select a published Collection for this Surface.',
    ambiguous: i18n.collectionPairAmbiguous || 'Several Collection Surfaces were found. Choose the specific Collection below.',
    mismatch: i18n.collectionPairMismatch || 'The Filter and Collection Surfaces do not use the same published Collection.',
  };
  return messages[code] || messages.mismatch;
}

export function renderCollectionPairStatus() {
  if (!activeWidget) return;
  const $status = $('[data-eit-collection-pair-status]:visible').last();
  if (!$status.length) return;
  const doc = previewDocument();
  const controlValue = $('.elementor-control-collection_id:visible select').last().val() || '';
  const status = pairingStatus(
    collectionIds(doc),
    activeWidget === 'eit-toolkit-filter-surface' ? controlValue : '',
    activeWidget,
    activeWidget === 'eit-toolkit-collection-surface' ? controlValue : '',
    hasRenderedPairingError(doc),
  );
  $status.attr('class', `eit-connector-status is-${status.state}`).text(statusMessage(status.code));
}

function bindPreviewObserver() {
  previewObserver?.disconnect();
  previewObserver = null;
  if (!activeWidget) return;
  const body = previewDocument()?.body;
  if (!body || !window.MutationObserver) return;
  previewObserver = new MutationObserver(renderCollectionPairStatus);
  previewObserver.observe(body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['data-eit-collection-surface', 'data-eit-config'],
  });
}

function openPanel(type) {
  activeWidget = type;
  bindPreviewObserver();
  window.queueMicrotask(renderCollectionPairStatus);
}

function closePanel() {
  activeWidget = '';
  previewObserver?.disconnect();
  previewObserver = null;
}

function widgetType(model, view) {
  return model?.get?.('widgetType') || view?.model?.get?.('widgetType') || '';
}

function addHook(name, callback) {
  hookBus.addAction(name, callback);
  hookBindings.push({ name, callback });
}

function unbindHooks() {
  if (hookBus?.removeAction) {
    hookBindings.forEach(({ name, callback }) => hookBus.removeAction(name, callback));
  }
  hookBindings = [];
  hookBus = null;
}

function bindHooks() {
  if (hookBus || !window.elementor?.hooks?.addAction) return;
  hookBus = window.elementor.hooks;
  addHook('panel/open_editor/widget', (panel, model, view) => {
    const type = widgetType(model, view);
    if (supportedWidgets.includes(type)) {
      openPanel(type);
      return;
    }
    closePanel();
  });
  ['section', 'column', 'container'].forEach((elementType) => {
    addHook(`panel/open_editor/${elementType}`, closePanel);
  });
}

function rebindHooks() {
  unbindHooks();
  bindHooks();
}

export function installCollectionPairing() {
  uninstallCollectionPairing();
  $(document).on('change.eitCollectionPairing', '.elementor-control-collection_id select', renderCollectionPairStatus);
  $('#elementor-preview-iframe').on('load.eitCollectionPairing', () => {
    bindPreviewObserver();
    renderCollectionPairStatus();
  });
  $(window).on('elementor:init.eitCollectionPairing', rebindHooks);
  bindHooks();
  return uninstallCollectionPairing;
}

export function uninstallCollectionPairing() {
  $(document).off('.eitCollectionPairing');
  $(window).off('.eitCollectionPairing');
  $('#elementor-preview-iframe').off('.eitCollectionPairing');
  unbindHooks();
  closePanel();
}
