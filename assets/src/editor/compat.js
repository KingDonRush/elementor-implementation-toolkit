import { $, i18n } from './runtime.js';

const fallbacks = {};

function fallbackMessage() {
  return i18n.editorCompatFallback
    || 'Elementor editor compatibility fallback is active. The widget still works, but this panel is being synchronized by the toolkit because Elementor did not refresh it natively.';
}

export function markEditorCompatFallback(reason) {
  if (!reason || fallbacks[reason]) {
    return;
  }

  fallbacks[reason] = true;
  window.console?.warn?.(`[EIT] ${fallbackMessage()} Reason: ${reason}`);
  renderEditorCompatWarning();
}

export function renderEditorCompatWarning() {
  if (!Object.keys(fallbacks).length) {
    return;
  }

  const $anchor = $('.elementor-control-target_selector, .elementor-control-filters, .elementor-control-section_sort, .elementor-control')
    .filter(':visible')
    .first();
  if (!$anchor.length) {
    return;
  }

  let $warning = $('.eit-editor-compat-warning').first();
  if (!$warning.length) {
    $warning = $('<div/>', { class: 'eit-editor-compat-warning', role: 'status' }).append(
      $('<strong/>', { text: i18n.editorCompatFallbackTitle || 'Compatibility fallback active' }),
      $('<span/>', { text: fallbackMessage() }),
    );
  }

  if (!$warning.parent().length || $warning.next().get(0) !== $anchor.get(0)) {
    $warning.insertBefore($anchor);
  }
}
