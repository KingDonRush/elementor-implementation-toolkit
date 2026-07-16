import { $, config, i18n } from './runtime.js';
import { collectionEndpoint, collectionPayload, normalizeCollectionResponse } from './collection-request.js';
import {
  collectData, cssEscape, detectItems, detectListings, inferPostId, inferTitle,
  inferUrl, normalizeWhitespace, safeJson,
} from './utils.js';

export class Controller {
  constructor(root) {
    this.$root = $(root);
    this.config = safeJson(this.$root.attr('data-eit-config'), {});
    this.filters = safeJson(this.$root.attr('data-eit-filters'), []);
    this.instance = this.config.instance || this.$root.attr('data-eit-instance') || 'default';
    this.page = 1;
    this.target = null;
    this.items = [];
    this.itemMap = {};
    this.lastResult = null;
    this.request = null;
    this.requestSequence = 0;
    this.searchTimer = null;
    this.statusTimer = null;
    this.init();
  }

  init() {
    this.bind();
    this.readUrlState();
    this.syncSearchClearButtons();
    this.syncRangeInputs();
    this.syncDateRanges();
    this.updateOptionStates();
    this.apply(false, false);
  }

  bind() {
    this.$root.on('submit', '.eit-filter-controller__form', (event) => {
      event.preventDefault();
      this.clearSearchTimer();
      this.page = 1;
      this.apply(true, true);
    });
    this.$root.on('input change', '[data-eit-control], [data-eit-sort]', (event) => {
      this.updateOptionStates();
      this.syncRangeInputs(event.currentTarget);
      this.syncDateRanges($(event.currentTarget).closest('.eit-date-range'));
      this.syncSearchClearButtons();
      if (this.config.autoApply) {
        this.page = 1;
        this.scheduleAutoApply(event.currentTarget, event.type);
      }
    });
    this.$root.on('click', '[data-eit-search-clear]', (event) => {
      const input = $(event.currentTarget).closest('[data-eit-search-field]').find('[data-eit-search-input]').get(0);
      if (!input) return;
      input.value = '';
      input.focus();
      this.page = 1;
      this.updateOptionStates();
      this.syncSearchClearButtons();
      this.clearSearchTimer();
      if (this.config.autoApply) this.apply(true, false, true);
    });
    this.$root.on('click', '[data-eit-date-clear]', (event) => {
      this.resetDate($(event.currentTarget).closest('.eit-date-range'));
      this.page = 1;
      this.clearSearchTimer();
      if (this.config.autoApply) this.apply(true, false, true);
    });
    this.$root.on('click', '[data-eit-reset]', () => this.reset());
    this.$root.on('click', '[data-eit-page]', (event) => {
      this.clearSearchTimer();
      this.page = parseInt($(event.currentTarget).attr('data-eit-page'), 10) || 1;
      this.apply(true, true);
    });
    this.$root.on('click', '[data-eit-remove-filter]', (event) => {
      this.clearSearchTimer();
      this.clearFilter($(event.currentTarget).attr('data-eit-remove-filter'));
      this.page = 1;
      this.apply(true, true);
    });
  }

  refreshTarget() {
    this.target = this.findTarget();
    this.itemMap = {};
    this.items = this.indexItems();
  }

  findTarget() {
    if ('collection' === this.config.provider) {
      if (this.config.collectionTarget) {
        return document.querySelector(`[data-eit-collection-surface="${cssEscape(this.config.collectionTarget)}"] [data-eit-collection-results]`);
      }
      return this.$root.find('[data-eit-collection-results]').get(0) || null;
    }
    const selector = this.config.targetSelector || '';
    const target = selector ? document.querySelector(selector) : null;
    if (target) return target;
    const detected = detectListings(document, this.$root.get(0));
    return detected.length ? detected[0].element : null;
  }

  indexItems() {
    if ('collection' === this.config.provider) return [];
    if (!this.target) return [];
    const selector = this.config.itemSelector || '';
    const elements = selector ? this.target.querySelectorAll(selector) : detectItems(this.target);
    const items = [];

    Array.from(elements).slice(0, 200).forEach((element, index) => {
      const clientId = element.getAttribute('data-eit-client-id') || `${this.instance}-${index}-${Math.random().toString(36).slice(2, 7)}`;
      let originalIndex = element.getAttribute('data-eit-original-index');
      if (null === originalIndex) {
        originalIndex = String(index);
        element.setAttribute('data-eit-original-index', originalIndex);
      }
      element.setAttribute('data-eit-client-id', clientId);
      this.itemMap[clientId] = element;
      items.push({
        clientId,
        originalIndex: parseInt(originalIndex, 10) || 0,
        postId: inferPostId(element),
        url: inferUrl(element),
        title: inferTitle(element),
        text: normalizeWhitespace(element.textContent || ''),
        classes: Array.from(element.classList || []),
        data: collectData(element),
      });
    });
    return items.sort((left, right) => left.originalIndex - right.originalIndex);
  }

  apply(shouldSyncUrl, shouldFocusResults, shouldFocusError = shouldFocusResults) {
    this.refreshTarget();
    const sequence = ++this.requestSequence;
    if (this.request && 4 !== this.request.readyState) this.request.abort();

    if (!this.target) {
      this.request = null;
      this.setLoading(false);
      this.renderError(i18n.targetMissing || 'The connected listing could not be found.', shouldFocusError);
      return;
    }
    if ('dom' === this.config.provider && !this.items.length) {
      const empty = { total: 0, page: 1, pages: 1, ids: [] };
      this.clearError();
      this.applyResult(empty);
      this.renderMeta(empty);
      this.renderPagination(empty);
      this.setLoading(false);
      return;
    }

    const state = this.collectState();
    const legacyPayload = {
      provider: this.config.provider || 'dom',
      cctType: this.config.cctType || '',
      templateId: this.config.cctTemplateId || 0,
      items: 'cct' === this.config.provider ? [] : this.items,
      filters: state.filters,
      sort: state.sort,
      page: this.page,
      perPage: this.config.perPage || 24,
    };
    const isCollection = 'collection' === this.config.provider;
    const payload = isCollection ? collectionPayload(state, this.config, this.page) : legacyPayload;
    const requestUrl = isCollection ? collectionEndpoint(config, this.config.collectionId || '') : config.restUrl;
    this.clearError();
    this.setLoading(true);
    this.request = $.ajax({
      url: requestUrl,
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(payload),
      beforeSend: (xhr) => { if (config.nonce) xhr.setRequestHeader('X-WP-Nonce', config.nonce); },
    });
    this.request.done((response) => {
      if (sequence !== this.requestSequence) return;
      this.lastResult = isCollection ? normalizeCollectionResponse(response) : (response || {});
      this.applyResult(this.lastResult);
      if (isCollection) this.renderFacets(this.lastResult.facets || []);
      this.renderMeta(this.lastResult, state.filters);
      this.renderPagination(this.lastResult);
      this.announce(this.resultMessage(this.lastResult.total || 0));
      if (shouldSyncUrl && this.config.syncUrl) this.writeUrlState(state);
      if (shouldFocusResults) this.focusResults();
      $(document).trigger('eit:listing-updated', [this.target, this.lastResult]);
    }).fail((xhr, status) => {
      if ('abort' === status || sequence !== this.requestSequence) return;
      const message = xhr?.responseJSON?.message || i18n.error || 'Filters could not be updated. Your current results are still visible.';
      this.renderError(message, shouldFocusError);
    }).always(() => {
      if (sequence !== this.requestSequence) return;
      this.request = null;
      this.setLoading(false);
    });
  }
}

export { cssEscape };
