export function safeJson(value, fallback) {
  if (!value) return fallback;
  if ('object' === typeof value) return value;

  try {
    return JSON.parse(value);
  } catch (error) {
    return fallback;
  }
}

export function slug(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

export function unique(items) {
  const seen = {};
  return items.filter((item) => {
    if (!item || seen[item]) return false;
    seen[item] = true;
    return true;
  });
}

export function normalizeWhitespace(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

export function cssEscape(value) {
  if (window.CSS && window.CSS.escape) return window.CSS.escape(value);
  return String(value).replace(/["\\]/g, '\\$&');
}

export function detectListings(doc, exclude) {
  const selectors = [
    '[data-eit-listing]', '.jet-listing-grid', '.elementor-posts-container',
    '.elementor-loop-container', '.products', '.elementor-widget-posts',
    '.elementor-widget-loop-grid', '.elementor-widget-woocommerce-products',
    '.elementor-widget-container',
  ];
  const found = [];

  selectors.forEach((selector) => {
    Array.from(doc.querySelectorAll(selector)).forEach((element) => {
      if (exclude && (element === exclude || element.contains(exclude))) return;
      const items = detectItems(element);
      if (items.length < 2) return;
      if (found.some((entry) => entry.element === element || entry.element.contains(element))) return;
      found.push({ element, items });
    });
  });

  return found;
}

export function detectItems(target) {
  const selectors = [
    '[data-eit-item]', '.jet-listing-grid__item', '.elementor-post', '.e-loop-item',
    '.product', '.elementor-grid-item', 'article', 'li',
  ];
  let best = [];

  selectors.some((selector) => {
    const items = removeNested(Array.from(target.querySelectorAll(selector)).filter((item) =>
      (null !== item.offsetParent || item.hasAttribute('data-eit-client-id'))
      && !item.closest('.eit-filter-controller')));
    if (items.length < 2) return false;
    best = items;
    return true;
  });

  if (!best.length) {
    best = Array.from(target.children).filter((child) =>
      (null !== child.offsetParent || child.hasAttribute('data-eit-client-id'))
      && !child.classList.contains('eit-filter-controller'));
  }

  return best;
}

function removeNested(items) {
  return items.filter((item) => !items.some((candidate) => candidate !== item && candidate.contains(item)));
}

export function inferPostId(element) {
  const data = element.getAttribute('data-eit-post-id') || element.getAttribute('data-post-id') || element.getAttribute('data-id');
  if (data && /^[1-9][0-9]*$/.test(data)) return data;
  const match = String(element.className || '').match(/(?:post|product)-(\d+)/);
  return match ? match[1] : 0;
}

export function inferUrl(element) {
  const link = element.querySelector('a[href]');
  return link ? link.href : '';
}

export function inferTitle(element) {
  const title = element.querySelector('[data-eit-title], .elementor-post__title, .woocommerce-loop-product__title, h1, h2, h3, h4');
  return title ? normalizeWhitespace(title.textContent || '') : '';
}

export function collectData(element) {
  const data = {};
  Array.from(element.attributes || []).forEach((attribute) => {
    if (0 !== attribute.name.indexOf('data-')) return;
    const key = attribute.name.replace(/^data-(eit-)?/, '').replace(/-/g, '_');
    data[key] = attribute.value;
  });
  Array.from(element.querySelectorAll('[data-eit-field]')).forEach((field) => {
    const key = field.getAttribute('data-eit-field');
    const value = field.getAttribute('data-eit-value') || field.textContent || '';
    if (key) data[slug(key)] = normalizeWhitespace(value);
  });
  return data;
}

export function paginationWindow(page, pages) {
  const visible = {};
  const result = [];
  let previous = 0;

  [1, pages, page - 2, page - 1, page, page + 1, page + 2].forEach((candidate) => {
    if (candidate >= 1 && candidate <= pages) visible[candidate] = true;
  });
  Object.keys(visible).map(Number).sort((left, right) => left - right).forEach((candidate) => {
    if (previous && candidate - previous > 1) result.push(null);
    result.push(candidate);
    previous = candidate;
  });
  return result;
}

export function formatActiveValue(filter, value, labels) {
  if ('date' === filter.type) {
    if (value.from && value.to) return `${labels.from || 'From'} ${value.from} ${labels.to || 'to'} ${value.to}`;
    if (value.from) return `${labels.from || 'From'} ${value.from}`;
    if (value.to) return `${labels.to || 'To'} ${value.to}`;
    return '';
  }
  return Object.keys(value).map((key) => value[key]).filter(Boolean).join(' - ');
}
