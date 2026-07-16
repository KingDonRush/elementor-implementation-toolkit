import { $ } from './runtime.js';
import { cssEscape, safeJson } from './utils.js';

function addFilterMeta(filter, $control) {
  const $group = $control.closest('[data-eit-filter-group]');
  filter.id = $group.attr('data-eit-filter-group') || '';
  filter.label = $group.attr('data-eit-filter-label') || '';
  ['source', 'compare', 'dataType'].forEach((property) => {
    const attribute = { source: 'data-eit-field-source', compare: 'data-eit-compare', dataType: 'data-eit-data-type' }[property];
    const value = $group.attr(attribute) || '';
    if (value) filter[property] = value;
  });
  return filter;
}

function syncRangeHandle($handle, input) {
  if (!$handle.length || !input) return;
  const min = parseFloat(input.min);
  const max = parseFloat(input.max);
  const value = parseFloat(input.value);
  const percent = !Number.isFinite(min) || !Number.isFinite(max) || !Number.isFinite(value) || max <= min
    ? 0 : ((value - min) / (max - min)) * 100;
  $handle.css('--eit-range-position', `${Math.max(0, Math.min(100, percent))}%`);
}

export function installControls(Controller) {
  Object.assign(Controller.prototype, {
    collectState() {
      const filters = [];
      const grouped = {};
      this.$root.find('[data-eit-control]').each((index, control) => {
        const $control = $(control);
        const type = $control.attr('data-eit-type');
        const key = $control.attr('data-eit-key') || '';
        const groupKey = `${type}:${key}`;
        if ('range' === type || 'date' === type) return;
        if ('checkbox' === control.type || 'radio' === control.type) {
          if (!control.checked) return;
          if (['toggle', 'radio', 'rating'].includes(type)) {
            filters.push(addFilterMeta({ type, key, value: control.value }, $control));
            return;
          }
          if (!grouped[groupKey]) grouped[groupKey] = addFilterMeta({ type, key, value: [] }, $control);
          grouped[groupKey].value.push(control.value);
          return;
        }
        if (['select-one', 'search', 'text'].includes(control.type) && control.value) {
          filters.push(addFilterMeta({ type, key, value: control.value }, $control));
        }
      });
      this.$root.find('.eit-range[data-eit-control]').each((index, element) => {
        const $range = $(element);
        const min = $range.find('[data-eit-range-min]').val();
        const max = $range.find('[data-eit-range-max]').val();
        if ('' === String(min) && '' === String(max)) return;
        const minimum = $range.find('[data-eit-range-min]').attr('min');
        const maximum = $range.find('[data-eit-range-max]').attr('max');
        if (undefined !== minimum && undefined !== maximum
          && String(min) === String(minimum) && String(max) === String(maximum)) return;
        filters.push(addFilterMeta({ type: 'range', key: $range.attr('data-eit-key') || '', value: { min, max } }, $range));
      });
      this.$root.find('.eit-date-range[data-eit-control]').each((index, element) => {
        const $date = $(element);
        this.syncDateRange($date, true);
        const from = $date.find('[data-eit-date-from]').val();
        const to = $date.find('[data-eit-date-to]').val();
        if (from || to) filters.push(addFilterMeta({ type: 'date', key: $date.attr('data-eit-key') || '', value: { from, to } }, $date));
      });
      Object.keys(grouped).forEach((key) => { if (grouped[key].value.length) filters.push(grouped[key]); });
      return { filters, sort: this.$root.find('[data-eit-sort]').val() || 'default' };
    },

    reset() {
      this.$root.find('[data-eit-control]').each((index, control) => {
        if ('checkbox' === control.type || 'radio' === control.type) control.checked = false;
        else if (!['range', 'date'].includes($(control).attr('data-eit-type'))) control.value = '';
      });
      this.$root.find('.eit-range[data-eit-control]').each((index, element) => this.resetRange($(element)));
      this.$root.find('.eit-date-range[data-eit-control]').each((index, element) => this.resetDate($(element)));
      this.$root.find('[data-eit-sort]').val('default');
      this.page = 1;
      this.clearSearchTimer();
      this.updateOptionStates();
      this.syncSearchClearButtons();
      this.apply(true, true);
    },

    clearFilter(filterId) {
      const [type, key = ''] = String(filterId || '').split(':');
      this.$root.find(`[data-eit-type="${cssEscape(type)}"][data-eit-key="${cssEscape(key)}"]`).each((index, control) => {
        if ('checkbox' === control.type || 'radio' === control.type) control.checked = false;
        else if ('range' === type) this.resetRange($(control));
        else if ('date' === type) this.resetDate($(control));
        else control.value = '';
      });
      this.updateOptionStates();
      this.syncSearchClearButtons();
    },

    scheduleAutoApply(control, eventType) {
      const $control = $(control);
      const delay = parseInt(this.config.searchDebounceMs, 10) || 0;
      const delayed = 'search' === $control.attr('data-eit-type') && 'input' === eventType && delay > 0;
      this.clearSearchTimer();
      if (!delayed) {
        this.apply(true, false, true);
        return;
      }
      this.searchTimer = window.setTimeout(() => {
        this.searchTimer = null;
        this.apply(true, false, true);
      }, delay);
    },

    clearSearchTimer() {
      if (!this.searchTimer) return;
      window.clearTimeout(this.searchTimer);
      this.searchTimer = null;
    },

    resetRange($range) {
      const minimum = $range.find('[data-eit-range-min]').attr('min');
      const maximum = $range.find('[data-eit-range-max]').attr('max');
      this.setRangeValue($range, { min: undefined === minimum ? '' : minimum, max: undefined === maximum ? '' : maximum });
    },

    setRangeValue($range, value = {}) {
      const pairs = [
        ['min', '[data-eit-range-min]', '[data-eit-range-min-slider]'],
        ['max', '[data-eit-range-max]', '[data-eit-range-max-slider]'],
      ];
      pairs.forEach(([key, numberSelector, sliderSelector]) => {
        if (Object.prototype.hasOwnProperty.call(value, key)) {
          $range.find(numberSelector).val(value[key] ?? '');
          $range.find(sliderSelector).val(value[key] ?? '');
        }
      });
      this.syncRangeInputs($range.find('[data-eit-range-min]').get(0));
    },

    syncSearchClearButtons() {
      this.$root.find('[data-eit-search-field]').each((index, field) => {
        const input = $(field).find('[data-eit-search-input]').get(0);
        const button = $(field).find('[data-eit-search-clear]').get(0);
        if (input && button) button.hidden = !input.value;
      });
    },

    resetDate($date) {
      $date.find('[data-eit-date-from], [data-eit-date-to]').val('');
      this.syncDateRange($date, false);
    },

    setDateValue($date, value = {}) {
      if (value.from) $date.find('[data-eit-date-from]').val(value.from);
      if (value.to) $date.find('[data-eit-date-to]').val(value.to);
      this.syncDateRange($date, false);
    },

    syncDateRanges($scope) {
      const $ranges = $scope?.length
        ? ($scope.is('.eit-date-range') ? $scope : $scope.find('.eit-date-range[data-eit-control]'))
        : this.$root.find('.eit-date-range[data-eit-control]');
      $ranges.each((index, element) => this.syncDateRange($(element), false));
    },

    syncDateRange($date, normalize) {
      const $from = $date.find('[data-eit-date-from]');
      const $to = $date.find('[data-eit-date-to]');
      let from = $from.val();
      let to = $to.val();
      let inverted = Boolean(from && to && from > to);
      if (inverted && normalize) {
        [from, to] = [to, from];
        $from.val(from);
        $to.val(to);
        inverted = false;
      }
      const hasValue = Boolean(from || to);
      $date.toggleClass('is-active', hasValue).toggleClass('is-invalid', inverted);
      $date.find('[data-eit-date-clear]').prop('hidden', !hasValue);
      $date.find('[data-eit-date-status]').prop('hidden', !inverted);
      $from.add($to).attr('aria-invalid', inverted ? 'true' : null);
    },

    syncRangeInputs(changed) {
      this.$root.find('.eit-range[data-eit-control]').each((index, element) => {
        const $range = $(element);
        const minNumber = $range.find('[data-eit-range-min]');
        const maxNumber = $range.find('[data-eit-range-max]');
        const minSlider = $range.find('[data-eit-range-min-slider]');
        const maxSlider = $range.find('[data-eit-range-max-slider]');
        if (changed === minNumber.get(0)) minSlider.val(minNumber.val());
        else if (changed === maxNumber.get(0)) maxSlider.val(maxNumber.val());
        else if (changed === minSlider.get(0)) minNumber.val(minSlider.val());
        else if (changed === maxSlider.get(0)) maxNumber.val(maxSlider.val());
        if (parseFloat(minNumber.val()) > parseFloat(maxNumber.val())) {
          maxNumber.val(minNumber.val());
          maxSlider.val(minNumber.val());
        }
        $range.find('[data-eit-range-min-label]').text(minNumber.val());
        $range.find('[data-eit-range-max-label]').text(maxNumber.val());
        syncRangeHandle($range.find('[data-eit-range-min-handle]'), minSlider.get(0));
        syncRangeHandle($range.find('[data-eit-range-max-handle]'), maxSlider.get(0));
      });
    },

    updateOptionStates() {
      this.$root.find('.eit-option').each((index, option) => {
        const input = option.querySelector('input');
        option.classList.toggle('is-active', Boolean(input && input.checked));
      });
    },

    readUrlState() {
      if (!this.config.syncUrl || !window.URLSearchParams) return;
      const params = new URLSearchParams(window.location.search);
      const prefix = `eit_${this.instance}_`;
      this.$root.find('[data-eit-control], [data-eit-sort]').each((index, control) => {
        const $control = $(control);
        const type = $control.attr('data-eit-type') || 'sort';
        const key = stateParamKey(type, $control.attr('data-eit-key') || '');
        const param = params.get(`${prefix}${type}_${key}`);
        if (null === param) return;
        if ('checkbox' === control.type || 'radio' === control.type) control.checked = param.split(',').includes(control.value);
        else if ('range' === type) this.setRangeValue($control, safeJson(param, {}));
        else if ('date' === type) this.setDateValue($control, safeJson(param, {}));
        else control.value = param;
      });
    },

    writeUrlState(state) {
      if (!window.URLSearchParams || !window.history) return;
      const params = new URLSearchParams(window.location.search);
      const prefix = `eit_${this.instance}_`;
      Array.from(params.keys()).forEach((key) => { if (key.startsWith(prefix)) params.delete(key); });
      state.filters.forEach((filter) => {
        let value = Array.isArray(filter.value) ? filter.value.join(',') : filter.value;
        if ('object' === typeof value) value = JSON.stringify(value);
        params.set(`${prefix}${filter.type}_${stateParamKey(filter.type, filter.key)}`, value);
      });
      if (state.sort && 'default' !== state.sort) params.set(`${prefix}sort_sort`, state.sort);
      window.history.replaceState({}, '', `${window.location.pathname}${params.toString() ? `?${params}` : ''}${window.location.hash}`);
    },
  });
}

function stateParamKey(type, key) {
  if (key) return key;
  return 'sort' === type ? 'sort' : 'search';
}
