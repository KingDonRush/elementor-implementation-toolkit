(function ($) {
    'use strict';

    var config = window.eitConfig || {};
    var i18n = config.i18n || {};

    function safeJson(value, fallback) {
        if (!value) {
            return fallback;
        }

        if ('object' === typeof value) {
            return value;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return fallback;
        }
    }

    function slug(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9_-]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function unique(items) {
        var seen = {};

        return items.filter(function (item) {
            if (!item || seen[item]) {
                return false;
            }

            seen[item] = true;
            return true;
        });
    }

    function syncRangeHandle($handle, input) {
        var min;
        var max;
        var value;
        var percent;

        if (!$handle.length || !input) {
            return;
        }

        min = parseFloat(input.min);
        max = parseFloat(input.max);
        value = parseFloat(input.value);

        if (!isFinite(min) || !isFinite(max) || !isFinite(value) || max <= min) {
            percent = 0;
        } else {
            percent = ((value - min) / (max - min)) * 100;
        }

        percent = Math.max(0, Math.min(100, percent));
        $handle.css('--eit-range-position', percent + '%');
    }

    function addFilterMeta(filter, $control) {
        var $group = $control.closest('[data-eit-filter-group]');
        var source = $group.attr('data-eit-field-source') || '';
        var compare = $group.attr('data-eit-compare') || '';
        var dataType = $group.attr('data-eit-data-type') || '';

        if (source) {
            filter.source = source;
        }

        if (compare) {
            filter.compare = compare;
        }

        if (dataType) {
            filter.dataType = dataType;
        }

        return filter;
    }

    function Controller(root) {
        this.$root = $(root);
        this.config = safeJson(this.$root.attr('data-eit-config'), {});
        this.filters = safeJson(this.$root.attr('data-eit-filters'), []);
        this.instance = this.config.instance || this.$root.attr('data-eit-instance') || 'default';
        this.page = 1;
        this.target = null;
        this.items = [];
        this.itemMap = {};
        this.lastResult = null;
        this.isApplying = false;
        this.searchTimer = null;
        this.init();
    }

    Controller.prototype.init = function () {
        this.bind();
        this.readUrlState();
        this.syncSearchClearButtons();
        this.syncRangeInputs();
        this.updateOptionStates();
        this.refreshTarget();
        this.apply(false);
    };

    Controller.prototype.bind = function () {
        var self = this;

        this.$root.on('submit', '.eit-filter-controller__form', function (event) {
            event.preventDefault();
            self.clearSearchTimer();
            self.page = 1;
            self.apply(true);
        });

        this.$root.on('input change', '[data-eit-control], [data-eit-sort]', function (event) {
            self.updateOptionStates();
            self.syncRangeInputs(this);
            self.syncSearchClearButtons();

            if (self.config.autoApply) {
                self.page = 1;
                self.scheduleAutoApply(this, event.type);
            }
        });

        this.$root.on('click', '[data-eit-search-clear]', function () {
            var input = $(this).closest('[data-eit-search-field]').find('[data-eit-search-input]').get(0);

            if (!input) {
                return;
            }

            input.value = '';
            input.focus();
            self.page = 1;
            self.updateOptionStates();
            self.syncSearchClearButtons();

            self.clearSearchTimer();

            if (self.config.autoApply) {
                self.apply(true);
            }
        });

        this.$root.on('click', '[data-eit-reset]', function () {
            self.reset();
        });

        this.$root.on('click', '[data-eit-page]', function () {
            self.clearSearchTimer();
            self.page = parseInt($(this).attr('data-eit-page'), 10) || 1;
            self.apply(true);
        });

        this.$root.on('click', '[data-eit-remove-filter]', function () {
            self.clearSearchTimer();
            self.clearFilter($(this).attr('data-eit-remove-filter'));
            self.page = 1;
            self.apply(true);
        });
    };

    Controller.prototype.refreshTarget = function () {
        this.target = this.findTarget();
        this.items = this.indexItems();
        this.itemMap = {};

        this.items.forEach(function (item) {
            this.itemMap[item.clientId] = item;
        }, this);
    };

    Controller.prototype.findTarget = function () {
        var selector = this.config.targetSelector || '';
        var target = selector ? document.querySelector(selector) : null;

        if (target) {
            return target;
        }

        var detected = detectListings(document, this.$root.get(0));
        return detected.length ? detected[0].element : null;
    };

    Controller.prototype.indexItems = function () {
        if (!this.target) {
            return [];
        }

        var itemSelector = this.config.itemSelector || '';
        var itemElements = itemSelector ? this.target.querySelectorAll(itemSelector) : detectItems(this.target);
        var items = [];

        Array.prototype.forEach.call(itemElements, function (element, index) {
            var clientId = element.getAttribute('data-eit-client-id') || this.instance + '-' + index + '-' + Math.random().toString(36).slice(2, 7);
            var originalIndex = element.getAttribute('data-eit-original-index');

            if (null === originalIndex) {
                originalIndex = String(index);
                element.setAttribute('data-eit-original-index', originalIndex);
            }

            element.setAttribute('data-eit-client-id', clientId);

            items.push({
                clientId: clientId,
                originalIndex: parseInt(originalIndex, 10) || 0,
                postId: inferPostId(element),
                url: inferUrl(element),
                title: inferTitle(element),
                text: normalizeWhitespace(element.textContent || ''),
                classes: Array.prototype.slice.call(element.classList || []),
                data: collectData(element)
            });
        }, this);

        return items.sort(function (a, b) {
            return a.originalIndex - b.originalIndex;
        });
    };

    Controller.prototype.collectState = function () {
        var filters = [];
        var grouped = {};

        this.$root.find('[data-eit-control]').each(function () {
            var control = this;
            var $control = $(control);
            var type = $control.attr('data-eit-type');
            var key = $control.attr('data-eit-key') || '';
            var groupKey = type + ':' + key;

            if ('range' === type || 'date' === type) {
                return;
            }

            if ('checkbox' === control.type || 'radio' === control.type) {
                if (!control.checked) {
                    return;
                }

                if ('toggle' === type || 'radio' === type || 'rating' === type) {
                    filters.push(addFilterMeta({
                        type: type,
                        key: key,
                        value: control.value
                    }, $control));
                    return;
                }

                if (!grouped[groupKey]) {
                    grouped[groupKey] = addFilterMeta({
                        type: type,
                        key: key,
                        value: []
                    }, $control);
                }

                grouped[groupKey].value.push(control.value);
                return;
            }

            if ('select-one' === control.type || 'search' === control.type || 'text' === control.type) {
                if (!control.value) {
                    return;
                }

                filters.push(addFilterMeta({
                    type: type,
                    key: key,
                    value: control.value
                }, $control));
            }
        });

        this.$root.find('.eit-range[data-eit-control]').each(function () {
            var $range = $(this);
            var min = $range.find('[data-eit-range-min]').val();
            var max = $range.find('[data-eit-range-max]').val();
            var originalMin = $range.find('[data-eit-range-min]').attr('min');
            var originalMax = $range.find('[data-eit-range-max]').attr('max');

            if (String(min) === String(originalMin) && String(max) === String(originalMax)) {
                return;
            }

            filters.push(addFilterMeta({
                type: 'range',
                key: $range.attr('data-eit-key') || '',
                value: {
                    min: min,
                    max: max
                }
            }, $range));
        });

        this.$root.find('.eit-date-range[data-eit-control]').each(function () {
            var $date = $(this);
            var from = $date.find('[data-eit-date-from]').val();
            var to = $date.find('[data-eit-date-to]').val();

            if (!from && !to) {
                return;
            }

            filters.push(addFilterMeta({
                type: 'date',
                key: $date.attr('data-eit-key') || '',
                value: {
                    from: from,
                    to: to
                }
            }, $date));
        });

        Object.keys(grouped).forEach(function (groupKey) {
            if (grouped[groupKey].value.length) {
                filters.push(grouped[groupKey]);
            }
        });

        return {
            filters: filters,
            sort: this.$root.find('[data-eit-sort]').val() || 'default'
        };
    };

    Controller.prototype.apply = function (shouldSyncUrl) {
        var self = this;

        this.refreshTarget();

        if (!this.target || !this.items.length || this.isApplying) {
            this.renderMeta({
                total: 0,
                page: 1,
                pages: 1,
                ids: []
            });
            return;
        }

        var state = this.collectState();
        var payload = {
            items: this.items,
            filters: state.filters,
            sort: state.sort,
            page: this.page,
            perPage: this.config.perPage || 9
        };

        this.isApplying = true;
        this.setLoading(true);

        $.ajax({
            url: config.restUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            beforeSend: function (xhr) {
                if (config.nonce) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                }
            }
        }).done(function (response) {
            self.lastResult = response || {};
            self.applyResult(self.lastResult);
            self.renderMeta(self.lastResult, state.filters);
            self.renderPagination(self.lastResult);

            if (shouldSyncUrl && self.config.syncUrl) {
                self.writeUrlState(state);
            }

            $(document).trigger('eit:listing-updated', [self.target, self.lastResult]);
        }).fail(function () {
            self.renderMeta({
                total: 0,
                page: 1,
                pages: 1,
                ids: []
            }, state.filters);
        }).always(function () {
            self.isApplying = false;
            self.setLoading(false);
        });
    };

    Controller.prototype.applyResult = function (result) {
        var ids = result.ids || [];
        var visible = {};
        var parent = null;

        ids.forEach(function (id) {
            visible[id] = true;
        });

        this.items.forEach(function (item) {
            var element = document.querySelector('[data-eit-client-id="' + cssEscape(item.clientId) + '"]');

            if (!element) {
                return;
            }

            if (!parent) {
                parent = element.parentNode;
            }

            element.hidden = !visible[item.clientId];
            element.classList.toggle('eit-is-filtered-out', !visible[item.clientId]);
        });

        if (parent) {
            ids.forEach(function (id) {
                var element = document.querySelector('[data-eit-client-id="' + cssEscape(id) + '"]');

                if (element) {
                    parent.appendChild(element);
                }
            });
        }

        this.$root.find('[data-eit-empty]').prop('hidden', (result.total || 0) > 0);
    };

    Controller.prototype.renderMeta = function (result, filters) {
        var countText = this.config.resultText || '{count} results';
        var count = result.total || 0;

        this.$root.find('[data-eit-result-count]').text(countText.replace('{count}', count));
        this.renderActiveChips(filters || this.collectState().filters);
    };

    Controller.prototype.renderActiveChips = function (filters) {
        var $container = this.$root.find('[data-eit-active-filters]');

        if (!$container.length) {
            return;
        }

        $container.empty();

        filters.forEach(function (filter) {
            var values = Array.isArray(filter.value) ? filter.value : [filter.value];

            values.forEach(function (value) {
                if ('object' === typeof value) {
                    value = Object.keys(value).map(function (key) {
                        return value[key];
                    }).filter(Boolean).join(' - ');
                }

                if (!value) {
                    return;
                }

                $('<button/>', {
                    type: 'button',
                    class: 'eit-active-chip',
                    text: getFilterLabel(filter.key, filter.type) + ': ' + value,
                    'data-eit-remove-filter': filter.type + ':' + filter.key
                }).appendTo($container);
            });
        });
    };

    Controller.prototype.renderPagination = function (result) {
        var type = this.config.paginationType || 'numbers';
        var $pagination = this.$root.find('[data-eit-pagination]');
        var page = result.page || 1;
        var pages = result.pages || 1;

        $pagination.empty();

        if ('none' === type || pages <= 1) {
            return;
        }

        if ('prev_next' === type || 'numbers_arrows' === type) {
            appendPageButton($pagination, this.config.previousText || i18n.previous || 'Previous', Math.max(1, page - 1), page <= 1, false);
        }

        if ('numbers' === type || 'numbers_arrows' === type) {
            for (var index = 1; index <= pages; index++) {
                appendPageButton($pagination, String(index), index, false, index === page);
            }
        }

        if ('prev_next' === type || 'numbers_arrows' === type) {
            appendPageButton($pagination, this.config.nextText || i18n.next || 'Next', Math.min(pages, page + 1), page >= pages, false);
        }
    };

    Controller.prototype.reset = function () {
        var self = this;

        this.$root.find('[data-eit-control]').each(function () {
            if ('checkbox' === this.type || 'radio' === this.type) {
                this.checked = false;
            } else if ('range' !== $(this).attr('data-eit-type') && 'date' !== $(this).attr('data-eit-type')) {
                this.value = '';
            }
        });

        this.$root.find('.eit-range[data-eit-control]').each(function () {
            self.resetRange($(this));
        });

        this.$root.find('.eit-date-range[data-eit-control]').each(function () {
            self.resetDate($(this));
        });
        this.$root.find('[data-eit-sort]').val('default');
        this.page = 1;
        this.clearSearchTimer();
        this.updateOptionStates();
        this.syncSearchClearButtons();
        this.apply(true);
    };

    Controller.prototype.clearFilter = function (filterId) {
        var parts = String(filterId || '').split(':');
        var type = parts[0];
        var key = parts[1] || '';
        var self = this;

        this.$root.find('[data-eit-type="' + cssEscape(type) + '"][data-eit-key="' + cssEscape(key) + '"]').each(function () {
            if ('checkbox' === this.type || 'radio' === this.type) {
                this.checked = false;
            } else if ('range' === type) {
                self.resetRange($(this));
            } else if ('date' === type) {
                self.resetDate($(this));
            } else {
                this.value = '';
            }
        });

        this.updateOptionStates();
        this.syncSearchClearButtons();
    };

    Controller.prototype.scheduleAutoApply = function (control, eventType) {
        var self = this;
        var $control = $(control);
        var delay = parseInt(this.config.searchDebounceMs, 10) || 0;
        var isSearchInput = 'search' === $control.attr('data-eit-type') && 'input' === eventType;

        this.clearSearchTimer();

        if (isSearchInput && delay > 0) {
            this.searchTimer = window.setTimeout(function () {
                self.searchTimer = null;
                self.apply(true);
            }, delay);
            return;
        }

        this.apply(true);
    };

    Controller.prototype.clearSearchTimer = function () {
        if (!this.searchTimer) {
            return;
        }

        window.clearTimeout(this.searchTimer);
        this.searchTimer = null;
    };

    Controller.prototype.resetRange = function ($range) {
        this.setRangeValue($range, {
            min: $range.find('[data-eit-range-min]').attr('min'),
            max: $range.find('[data-eit-range-max]').attr('max')
        });
    };

    Controller.prototype.setRangeValue = function ($range, value) {
        value = value && 'object' === typeof value ? value : {};

        var min = value.min;
        var max = value.max;
        var $minNumber = $range.find('[data-eit-range-min]');
        var $maxNumber = $range.find('[data-eit-range-max]');
        var $minSlider = $range.find('[data-eit-range-min-slider]');
        var $maxSlider = $range.find('[data-eit-range-max-slider]');

        if (null !== min && undefined !== min && '' !== String(min)) {
            $minNumber.val(min);
            $minSlider.val(min);
        }

        if (null !== max && undefined !== max && '' !== String(max)) {
            $maxNumber.val(max);
            $maxSlider.val(max);
        }

        this.syncRangeInputs($minNumber.get(0));
    };

    Controller.prototype.syncSearchClearButtons = function () {
        this.$root.find('[data-eit-search-field]').each(function () {
            var input = $(this).find('[data-eit-search-input]').get(0);
            var button = $(this).find('[data-eit-search-clear]').get(0);

            if (!input || !button) {
                return;
            }

            button.hidden = !input.value;
        });
    };

    Controller.prototype.resetDate = function ($date) {
        $date.find('[data-eit-date-from], [data-eit-date-to]').val('');
    };

    Controller.prototype.setDateValue = function ($date, value) {
        value = value && 'object' === typeof value ? value : {};

        if (value.from) {
            $date.find('[data-eit-date-from]').val(value.from);
        }

        if (value.to) {
            $date.find('[data-eit-date-to]').val(value.to);
        }
    };

    Controller.prototype.syncRangeInputs = function (changed) {
        this.$root.find('.eit-range[data-eit-control]').each(function () {
            var $range = $(this);
            var minNumber = $range.find('[data-eit-range-min]');
            var maxNumber = $range.find('[data-eit-range-max]');
            var minSlider = $range.find('[data-eit-range-min-slider]');
            var maxSlider = $range.find('[data-eit-range-max-slider]');

            if (changed === minNumber.get(0)) {
                minSlider.val(minNumber.val());
            } else if (changed === maxNumber.get(0)) {
                maxSlider.val(maxNumber.val());
            } else if (changed === minSlider.get(0)) {
                minNumber.val(minSlider.val());
            } else if (changed === maxSlider.get(0)) {
                maxNumber.val(maxSlider.val());
            }

            if (parseFloat(minNumber.val()) > parseFloat(maxNumber.val())) {
                maxNumber.val(minNumber.val());
                maxSlider.val(minNumber.val());
            }

            $range.find('[data-eit-range-min-label]').text(minNumber.val());
            $range.find('[data-eit-range-max-label]').text(maxNumber.val());
            syncRangeHandle($range.find('[data-eit-range-min-handle]'), minSlider.get(0));
            syncRangeHandle($range.find('[data-eit-range-max-handle]'), maxSlider.get(0));
        });
    };

    Controller.prototype.updateOptionStates = function () {
        this.$root.find('.eit-option').each(function () {
            var input = this.querySelector('input');
            this.classList.toggle('is-active', Boolean(input && input.checked));
        });
    };

    Controller.prototype.setLoading = function (isLoading) {
        this.$root.toggleClass('is-loading', isLoading);

        if (this.target) {
            this.target.classList.toggle('eit-target-is-loading', isLoading);
        }
    };

    Controller.prototype.readUrlState = function () {
        if (!this.config.syncUrl || !window.URLSearchParams) {
            return;
        }

        var params = new URLSearchParams(window.location.search);
        var prefix = 'eit_' + this.instance + '_';
        var self = this;

        this.$root.find('[data-eit-control], [data-eit-sort]').each(function () {
            var $control = $(this);
            var type = $control.attr('data-eit-type') || 'sort';
            var key = stateParamKey(type, $control.attr('data-eit-key') || '');
            var param = params.get(prefix + type + '_' + key);

            if (!param) {
                return;
            }

            if ('checkbox' === this.type || 'radio' === this.type) {
                this.checked = param.split(',').indexOf(this.value) !== -1;
            } else if ('range' === type) {
                self.setRangeValue($control, safeJson(param, {}));
            } else if ('date' === type) {
                self.setDateValue($control, safeJson(param, {}));
            } else if ('range' !== type && 'date' !== type) {
                this.value = param;
            }
        });
    };

    Controller.prototype.writeUrlState = function (state) {
        if (!window.URLSearchParams || !window.history) {
            return;
        }

        var params = new URLSearchParams(window.location.search);
        var prefix = 'eit_' + this.instance + '_';

        Array.from(params.keys()).forEach(function (key) {
            if (0 === key.indexOf(prefix)) {
                params.delete(key);
            }
        });

        state.filters.forEach(function (filter) {
            var value = Array.isArray(filter.value) ? filter.value.join(',') : filter.value;

            if ('object' === typeof value) {
                value = JSON.stringify(value);
            }

            params.set(prefix + filter.type + '_' + stateParamKey(filter.type, filter.key), value);
        });

        if (state.sort && 'default' !== state.sort) {
            params.set(prefix + 'sort_sort', state.sort);
        }

        window.history.replaceState({}, '', window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash);
    };

    function appendPageButton($container, label, page, disabled, active) {
        $('<button/>', {
            type: 'button',
            class: 'eit-page-button' + (active ? ' is-active' : ''),
            text: label,
            disabled: disabled,
            'aria-current': active ? 'page' : null,
            'data-eit-page': page
        }).appendTo($container);
    }

    function getFilterLabel(key, type) {
        return key || type || 'Filter';
    }

    function stateParamKey(type, key) {
        if (key) {
            return key;
        }

        return 'sort' === type ? 'sort' : 'search';
    }

    function detectListings(doc, exclude) {
        var selectors = [
            '[data-eit-listing]',
            '.jet-listing-grid',
            '.elementor-posts-container',
            '.elementor-loop-container',
            '.products',
            '.elementor-widget-posts',
            '.elementor-widget-loop-grid',
            '.elementor-widget-woocommerce-products',
            '.elementor-widget-container'
        ];
        var found = [];

        selectors.forEach(function (selector) {
            Array.prototype.forEach.call(doc.querySelectorAll(selector), function (element) {
                if (exclude && (element === exclude || element.contains(exclude))) {
                    return;
                }

                var items = detectItems(element);

                if (items.length < 2) {
                    return;
                }

                if (found.some(function (entry) { return entry.element === element || entry.element.contains(element); })) {
                    return;
                }

                found.push({
                    element: element,
                    items: items
                });
            });
        });

        return found;
    }

    function detectItems(target) {
        var selectors = [
            '[data-eit-item]',
            '.jet-listing-grid__item',
            '.elementor-post',
            '.e-loop-item',
            '.product',
            '.elementor-grid-item',
            'article',
            'li'
        ];
        var best = [];

        selectors.some(function (selector) {
            var items = Array.prototype.filter.call(target.querySelectorAll(selector), function (item) {
                return (item.offsetParent !== null || item.hasAttribute('data-eit-client-id')) && !item.closest('.eit-filter-controller');
            });

            items = removeNested(items);

            if (items.length >= 2) {
                best = items;
                return true;
            }

            return false;
        });

        if (!best.length) {
            best = Array.prototype.filter.call(target.children, function (child) {
                return (child.offsetParent !== null || child.hasAttribute('data-eit-client-id')) && !child.classList.contains('eit-filter-controller');
            });
        }

        return best;
    }

    function removeNested(items) {
        return items.filter(function (item) {
            return !items.some(function (candidate) {
                return candidate !== item && candidate.contains(item);
            });
        });
    }

    function inferPostId(element) {
        var data = element.getAttribute('data-eit-post-id') || element.getAttribute('data-post-id') || element.getAttribute('data-id');

        if (data && /^[1-9][0-9]*$/.test(data)) {
            return data;
        }

        var classMatch = String(element.className || '').match(/(?:post|product)-(\d+)/);
        return classMatch ? classMatch[1] : 0;
    }

    function inferUrl(element) {
        var link = element.querySelector('a[href]');
        return link ? link.href : '';
    }

    function inferTitle(element) {
        var title = element.querySelector('[data-eit-title], .elementor-post__title, .woocommerce-loop-product__title, h1, h2, h3, h4');
        return title ? normalizeWhitespace(title.textContent || '') : '';
    }

    function collectData(element) {
        var data = {};

        Array.prototype.forEach.call(element.attributes || [], function (attribute) {
            if (0 !== attribute.name.indexOf('data-')) {
                return;
            }

            var key = attribute.name.replace(/^data-(eit-)?/, '').replace(/-/g, '_');
            data[key] = attribute.value;
        });

        Array.prototype.forEach.call(element.querySelectorAll('[data-eit-field]'), function (field) {
            var key = field.getAttribute('data-eit-field');
            var value = field.getAttribute('data-eit-value') || field.textContent || '';

            if (key) {
                data[slug(key)] = normalizeWhitespace(value);
            }
        });

        return data;
    }

    function normalizeWhitespace(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function cssEscape(value) {
        if (window.CSS && window.CSS.escape) {
            return window.CSS.escape(value);
        }

        return String(value).replace(/"/g, '\\"');
    }

    $(function () {
        $('.eit-filter-controller').each(function () {
            new Controller(this);
        });
    });
})(jQuery);
