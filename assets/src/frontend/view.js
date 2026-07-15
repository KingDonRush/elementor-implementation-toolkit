import { $, i18n } from './runtime.js';
import { cssEscape, formatActiveValue, normalizeWhitespace, paginationWindow } from './utils.js';

export function installView(Controller) {
  Object.assign(Controller.prototype, {
    applyResult(result) {
      if ('string' === typeof result.html && 'cct' === this.config.provider) {
        this.target.innerHTML = result.html;
        if (window.elementorFrontend?.elementsHandler) window.elementorFrontend.elementsHandler.runReadyTrigger($(this.target));
        this.refreshTarget();
        this.$root.find('[data-eit-empty]').prop('hidden', (result.total || 0) > 0);
        return;
      }
      const visible = {};
      (result.ids || []).forEach((id) => { visible[id] = true; });
      let parent = null;
      this.items.forEach((item) => {
        const element = this.itemMap[item.clientId];
        if (!element) return;
        if (!parent) parent = element.parentNode;
        element.hidden = !visible[item.clientId];
        element.classList.toggle('eit-is-filtered-out', !visible[item.clientId]);
      });
      if (parent) (result.ids || []).forEach((id) => { if (this.itemMap[id]) parent.appendChild(this.itemMap[id]); });
      this.$root.find('[data-eit-empty]').prop('hidden', (result.total || 0) > 0);
    },

    renderMeta(result, filters = this.collectState().filters) {
      const count = Number(result.total || 0);
      this.$root.find('[data-eit-result-count]').text((this.config.resultText || '{count} results').replace('{count}', count));
      this.renderActiveChips(filters);
    },

    renderActiveChips(filters) {
      const $container = this.$root.find('[data-eit-active-filters]').empty();
      if (!$container.length) return;
      filters.forEach((filter) => {
        const values = Array.isArray(filter.value) ? filter.value : [filter.value];
        values.forEach((rawValue) => {
          const value = 'object' === typeof rawValue
            ? formatActiveValue(filter, rawValue, i18n)
            : this.getValueLabel(filter, rawValue);
          if (!value) return;
          $('<button/>', {
            type: 'button', class: 'eit-active-chip',
            text: `${filter.label || i18n.filter || 'Filter'}: ${value}`,
            'data-eit-remove-filter': `${filter.type}:${filter.key}`,
          }).appendTo($container);
        });
      });
    },

    renderPagination(result) {
      const type = this.config.paginationType || 'numbers';
      const $pagination = this.$root.find('[data-eit-pagination]').empty();
      const page = Number(result.page || 1);
      const pages = Number(result.pages || 1);
      if ('none' === type || pages <= 1) return;
      if (['prev_next', 'numbers_arrows'].includes(type)) appendPageButton($pagination, this.config.previousText || i18n.previous || 'Previous', Math.max(1, page - 1), page <= 1, false);
      if (['numbers', 'numbers_arrows'].includes(type)) {
        paginationWindow(page, pages).forEach((index) => {
          if (null === index) appendPageEllipsis($pagination);
          else appendPageButton($pagination, String(index), index, false, index === page);
        });
      }
      if (['prev_next', 'numbers_arrows'].includes(type)) appendPageButton($pagination, this.config.nextText || i18n.next || 'Next', Math.min(pages, page + 1), page >= pages, false);
    },

    setLoading(loading) {
      this.$root.toggleClass('is-loading', loading).attr('aria-busy', loading ? 'true' : 'false');
      if (this.target) {
        this.target.classList.toggle('eit-target-is-loading', loading);
        this.target.setAttribute('aria-busy', loading ? 'true' : 'false');
      }
    },

    renderError(message, shouldFocus) {
      const $error = this.$root.addClass('has-error').find('[data-eit-error]');
      $error.text(message).prop('hidden', false);
      this.announce(message);
      if (shouldFocus && $error.length) $error.get(0).focus();
    },

    clearError() {
      this.$root.removeClass('has-error').find('[data-eit-error]').empty().prop('hidden', true);
    },

    announce(message) {
      window.clearTimeout(this.statusTimer);
      this.$root.find('[data-eit-status]').text('');
      this.statusTimer = window.setTimeout(() => {
        this.$root.find('[data-eit-status]').text(message);
        this.statusTimer = null;
      }, 20);
    },

    focusResults() {
      const target = this.$root.find('[data-eit-result-count]').get(0) || this.target;
      if (!target) return;
      if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
      target.focus();
    },

    getValueLabel(filter, value) {
      if (!filter.id) return value;
      const $group = this.$root.find(`[data-eit-filter-group="${cssEscape(filter.id)}"]`);
      let label = '';
      $group.find('input, option').each((index, option) => {
        if (String(option.value) !== String(value)) return;
        label = 'OPTION' === option.tagName
          ? $(option).text()
          : ($(option).closest('.eit-option').find('.eit-option__label').first().text() || $(option).closest('label').text());
        return false;
      });
      return normalizeWhitespace(label) || value;
    },
  });
}

function appendPageButton($container, label, page, disabled, active) {
  $('<button/>', {
    type: 'button', class: `eit-page-button${active ? ' is-active' : ''}`, text: label,
    disabled, 'aria-current': active ? 'page' : null,
    'aria-label': /^\d+$/.test(label) ? `${i18n.page || 'Page'} ${label}` : label,
    'data-eit-page': page,
  }).appendTo($container);
}

function appendPageEllipsis($container) {
  $('<span/>', { class: 'eit-page-ellipsis', text: '\u2026', 'aria-hidden': 'true' }).appendTo($container);
}
