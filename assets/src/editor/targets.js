import { $, i18n } from './runtime.js';

let currentTargets = [];

function getPreviewDocument() {
  const iframe = document.querySelector('#elementor-preview-iframe');
  return iframe?.contentDocument || null;
}

function ensurePreviewStyles(doc) {
  if (!doc || doc.getElementById('eit-editor-highlight-styles')) return;
  const style = doc.createElement('style');
  style.id = 'eit-editor-highlight-styles';
  style.textContent = '.eit-editor-highlight{outline:3px solid #ff2f92!important;outline-offset:4px!important;box-shadow:0 0 0 9999px rgba(255,47,146,.08)!important;position:relative!important;z-index:9999!important;}';
  doc.head.appendChild(style);
}

function removeNested(items) {
  return items.filter((item) => !items.some((candidate) => candidate !== item && candidate.contains(item)));
}

function detectItems(target) {
  const selectors = [
    '[data-eit-item]', '.jet-listing-grid__item', '.elementor-post', '.e-loop-item',
    '.product', '.elementor-grid-item', 'article', 'li',
  ];
  let best = [];

  selectors.some((selector) => {
    const visible = Array.from(target.querySelectorAll(selector)).filter((item) => item.offsetParent !== null);
    const items = removeNested(visible);
    if (items.length < 2) return false;
    best = items;
    return true;
  });

  return best.length
    ? best
    : Array.from(target.children).filter((child) => child.offsetParent !== null);
}

function detectListings(doc) {
  const selectors = [
    '[data-eit-listing]', '.jet-listing-grid', '.elementor-posts-container',
    '.elementor-loop-container', '.products', '.elementor-widget-posts',
    '.elementor-widget-loop-grid', '.elementor-widget-woocommerce-products', '.elementor-widget-container',
  ];
  const found = [];

  selectors.forEach((selector) => {
    doc.querySelectorAll(selector).forEach((element) => {
      if (element.closest('.eit-filter-controller')) return;
      const items = detectItems(element);
      if (items.length < 2) return;
      if (found.some((entry) => entry.element === element || entry.element.contains(element))) return;
      found.push({ element, items });
    });
  });
  return found;
}

function getListingLabel(element, items, index) {
  let base = 'Listing';
  if (element.matches('.jet-listing-grid') || element.querySelector('.jet-listing-grid__item')) {
    base = 'JetEngine Listing';
  } else if (element.matches('.products') || element.querySelector('.product')) {
    base = 'Products';
  } else if (element.matches('.elementor-posts-container, .elementor-widget-posts') || element.querySelector('.elementor-post')) {
    base = 'Posts';
  }
  return `${base} #${index + 1} (${items.length})`;
}

function getStableSelector(element) {
  if (element.id) return `#${element.id}`;
  const elementorClass = Array.from(element.classList || []).find((name) => /^elementor-element-[a-z0-9]+$/.test(name));
  if (elementorClass) return `.${elementorClass}`;
  const parent = element.closest('[data-id].elementor-element');
  const dataId = parent?.getAttribute('data-id');
  return dataId ? `.elementor-element-${dataId}` : '';
}

function scanTargets() {
  const doc = getPreviewDocument();
  if (!doc) {
    currentTargets = [];
    return currentTargets;
  }

  ensurePreviewStyles(doc);
  currentTargets = detectListings(doc).map((entry, index) => ({
    label: getListingLabel(entry.element, entry.items, index),
    selector: getStableSelector(entry.element),
    element: entry.element,
    count: entry.items.length,
  })).filter((entry) => entry.selector);
  return currentTargets;
}

function highlight(element) {
  clearHighlights();
  element?.classList.add('eit-editor-highlight');
}

export function clearHighlights() {
  const doc = getPreviewDocument();
  doc?.querySelectorAll('.eit-editor-highlight').forEach((element) => element.classList.remove('eit-editor-highlight'));
}

export function renderPanelHelper() {
  const $control = $('.elementor-control-target_selector');
  const input = $control.find('input[data-setting="target_selector"], textarea[data-setting="target_selector"]').get(0);
  if (!$control.length || !input) return;

  const targets = scanTargets();
  let $helper = $control.find('.eit-editor-targets');
  if (!$helper.length) {
    $helper = $('<div/>', { class: 'eit-editor-targets' }).appendTo($control);
  }

  $helper.empty();
  $('<div/>', { class: 'eit-editor-targets__title', text: i18n.detectedTargets || 'Detected listings' }).appendTo($helper);
  if (!targets.length) {
    $('<p/>', { class: 'eit-editor-targets__empty', text: i18n.noTargets || 'No listings detected on this canvas yet.' }).appendTo($helper);
    return;
  }

  targets.forEach((target) => {
    $('<button/>', {
      type: 'button', class: 'eit-editor-target', text: target.label, 'data-selector': target.selector,
    }).on('mouseenter', () => highlight(target.element))
      .on('mouseleave', clearHighlights)
      .on('click', () => {
        input.value = target.selector;
        $(input).trigger('input').trigger('change').trigger('keyup');
        clearHighlights();
      }).appendTo($helper);
  });

  $('<p/>', { class: 'eit-editor-targets__hint', text: i18n.fallback || 'Manual selector remains available for difficult cases.' }).appendTo($helper);
}
