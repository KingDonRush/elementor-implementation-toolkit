(function ($) {
    'use strict';

    var config = window.eitEditorConfig || {};
    var i18n = config.i18n || {};
    var currentTargets = [];
    var panelTimer = null;
    var filterTypeSyncTimer = null;
    var filterTypeFollowupTimer = null;
    var filterTypeHooksBound = false;
    var editorCompatFallbacks = {};
    var filterTypeStateControls = [
        'eit_filter_has_field_controls',
        'eit_filter_has_option_controls',
        'eit_filter_has_range_controls',
        'eit_filter_has_rating_controls'
    ];
    var styleCadenceControls = {
        options: [
            'section_option_style',
            'option_typography',
            'option_color',
            'option_background',
            'option_active_color',
            'option_active_background',
            'option_border',
            'option_radius',
            'option_padding'
        ],
        range: [
            'section_range_style',
            'range_orientation',
            'range_show_values',
            'range_show_ticks',
            'range_input_heading',
            'range_show_inputs',
            'range_input_flow',
            'range_input_position',
            'range_input_width',
            'range_input_gap',
            'range_input_height',
            'range_input_text_color',
            'range_input_background',
            'range_input_border_color',
            'range_input_radius',
            'range_input_padding',
            'range_track_style',
            'range_track_color',
            'range_track_base_color',
            'range_track_height',
            'range_vertical_height',
            'range_handle_size',
            'range_handle_shape',
            'range_handle_color',
            'range_handle_border_color',
            'range_handle_border_width',
            'range_handle_icon_heading',
            'range_handle_icon_enabled',
            'range_handle_icon',
            'range_handle_icon_size',
            'range_handle_icon_color',
            'range_value_color',
            'range_tick_color'
        ],
        rating: [
            'section_rating_style',
            'rating_color'
        ]
    };

    function isTruthy(value) {
        return true === value || 1 === value || '1' === value || 'yes' === value || 'on' === value || 'true' === value;
    }

    function getContainerSettings(container) {
        var settings = {};

        if (!container || !container.settings) {
            return settings;
        }

        if (container.settings.toJSON) {
            settings = container.settings.toJSON() || {};
        }

        return settings;
    }

    function getSetting(settings, key, fallback) {
        return undefined !== settings[key] && null !== settings[key] ? settings[key] : fallback;
    }

    function normalizeRepeaterRows(rows) {
        if (null === rows || undefined === rows) {
            return null;
        }

        if (rows.toJSON) {
            rows = rows.toJSON();
        } else if (rows.models) {
            rows = rows.models;
        }

        if (!Array.isArray(rows) && 'object' === typeof rows) {
            rows = Object.keys(rows).map(function (key) {
                return rows[key];
            });
        }

        if (!Array.isArray(rows)) {
            return null;
        }

        return rows.map(function (row) {
            if (!row) {
                return {};
            }

            if (row.toJSON) {
                return row.toJSON();
            }

            if (row.attributes) {
                return row.attributes;
            }

            return row;
        });
    }

    function sortValuePart(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9_-]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function sortOptionValue(option) {
        var source = sortValuePart(getSetting(option, 'source', 'default')) || 'default';
        var direction = 'desc' === sortValuePart(getSetting(option, 'direction', 'asc')) ? 'desc' : 'asc';
        var key = sortValuePart(getSetting(option, 'key', ''));
        var dataType = sortValuePart(getSetting(option, 'data_type', 'text')) || 'text';

        if ('default' === source) {
            return 'default';
        }

        if ('title' === source || 'date' === source) {
            return source + '_' + direction;
        }

        if ('numeric' === source) {
            key = key || 'sort';
            return 'sort' === key ? 'numeric_' + direction : 'data_' + key + '_number_' + direction;
        }

        if ('rating' === source) {
            key = key || 'rating';
            return 'rating' === key ? 'rating_' + direction : 'data_' + key + '_number_' + direction;
        }

        if ('data' === source && key) {
            if (['text', 'number', 'date'].indexOf(dataType) === -1) {
                dataType = 'text';
            }

            return 'data_' + key + '_' + dataType + '_' + direction;
        }

        return '';
    }

    function defaultSortLines() {
        return 'default|Default\ntitle_asc|Title A-Z\ntitle_desc|Title Z-A\ndate_desc|Newest\nnumeric_asc|Lowest value\nnumeric_desc|Highest value';
    }

    function normalizeSortLines(lines) {
        return String(lines || '')
            .split(/\r\n|\r|\n/)
            .map(function (line) {
                return line.trim();
            })
            .filter(Boolean)
            .join('\n');
    }

    function compileSortOptions(items, fallback) {
        var rows = normalizeRepeaterRows(items);
        var lines = [];
        var compiled;
        var normalizedFallback = normalizeSortLines(fallback);

        if (null === rows) {
            return fallback || '';
        }

        rows.forEach(function (option) {
            var label = getSetting(option, 'label', '');
            var value = sortOptionValue(option);

            if (!label || !value) {
                return;
            }

            lines.push(value + '|' + label);
        });

        compiled = lines.join('\n');

        if (normalizeSortLines(compiled) === normalizeSortLines(defaultSortLines()) && normalizedFallback && normalizedFallback !== normalizeSortLines(defaultSortLines())) {
            return normalizedFallback;
        }

        return compiled;
    }

    function mapWidgetFiltersToPreset(filters) {
        filters = normalizeRepeaterRows(filters);

        if (!Array.isArray(filters)) {
            return [];
        }

        return filters.map(function (filter) {
            filter = filter || {};

            return {
                enabled: true,
                label: getSetting(filter, 'label', 'Filter'),
                type: getSetting(filter, 'type', 'search'),
                key: getSetting(filter, 'key', ''),
                placeholder: getSetting(filter, 'placeholder', ''),
                options: getSetting(filter, 'options', ''),
                range_min: getSetting(filter, 'range_min', 0),
                range_max: getSetting(filter, 'range_max', 100),
                range_step: getSetting(filter, 'range_step', 1),
                show_label: isTruthy(getSetting(filter, 'show_label', 'yes'))
            };
        });
    }

    function buildPresetPayload(settings) {
        var showApply = isTruthy(getSetting(settings, 'show_apply', ''));

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
                sync_url: isTruthy(getSetting(settings, 'sync_url', 'yes')),
                per_page: getSetting(settings, 'per_page', 9),
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
                filters: mapWidgetFiltersToPreset(getSetting(settings, 'filters', []))
            },
            source_widget: {
                element_id: getSetting(settings, '_element_id', ''),
                document_id: window.elementor && elementor.config && elementor.config.document ? elementor.config.document.id || 0 : 0
            }
        };
    }

    function setEditorActionStatus($button, message, state) {
        var $status = $button.closest('[data-eit-editor-action]').find('[data-eit-action-status]');

        if (!$status.length) {
            $status = $button.closest('.eit-editor-save-preset').find('[data-eit-save-preset-status]');
        }

        $status
            .removeClass('is-error is-success is-loading')
            .addClass(state ? 'is-' + state : '')
            .text(message || '');
    }

    function editorCompatFallbackMessage() {
        return i18n.editorCompatFallback || 'Elementor editor compatibility fallback is active. The widget still works, but this panel is being synchronized by the toolkit because Elementor did not refresh it natively.';
    }

    function markEditorCompatFallback(reason) {
        if (!reason || editorCompatFallbacks[reason]) {
            return;
        }

        editorCompatFallbacks[reason] = true;

        if (window.console && console.warn) {
            console.warn('[EIT] ' + editorCompatFallbackMessage() + ' Reason: ' + reason);
        }

        renderEditorCompatWarning();
    }

    function renderEditorCompatWarning() {
        var reasons = Object.keys(editorCompatFallbacks);
        var $anchor;
        var $warning;

        if (!reasons.length) {
            return;
        }

        $anchor = $('.elementor-control-target_selector, .elementor-control-filters, .elementor-control-section_sort, .elementor-control').filter(':visible').first();

        if (!$anchor.length) {
            return;
        }

        $warning = $('.eit-editor-compat-warning').first();

        if (!$warning.length) {
            $warning = $('<div/>', {
                class: 'eit-editor-compat-warning',
                role: 'status'
            }).append(
                $('<strong/>', {
                    text: i18n.editorCompatFallbackTitle || 'Compatibility fallback active'
                }),
                $('<span/>', {
                    text: editorCompatFallbackMessage()
                })
            );
        }

        if (!$warning.parent().length || $warning.next().get(0) !== $anchor.get(0)) {
            $warning.insertBefore($anchor);
        }
    }

    function upsertPresetOption(preset) {
        if (!preset || !preset.id) {
            return;
        }

        $('select[data-setting="filter_preset"]').each(function () {
            var exists = Array.prototype.some.call(this.options, function (option) {
                return option.value === preset.id;
            });

            if (!exists) {
                this.add(new Option(preset.name || preset.id, preset.id));
            }
        });
    }

    function setEditorSettings(container, settings) {
        var shouldSyncFilterTypes = settings && Object.prototype.hasOwnProperty.call(settings, 'filters');

        if (!container || !settings) {
            return;
        }

        Object.keys(settings).forEach(function (controlId) {
            var input = document.querySelector('[data-setting="' + controlId + '"]');
            var value = settings[controlId];

            if (input && 'object' !== typeof value && input.value !== value) {
                input.value = value;
                $(input).trigger('input').trigger('change');
            }
        });

        if (window.$e && $e.run) {
            try {
                $e.run('document/elements/settings', {
                    container: container,
                    settings: settings,
                    options: {
                        render: false,
                        renderUI: true
                    }
                });
                if (shouldSyncFilterTypes) {
                    scheduleFilterTypeSync();
                }
                return;
            } catch (error) {
                markEditorCompatFallback('settings-command');
                // Fall through to the legacy model path when Elementor changes the command contract.
            }
        }

        if (container.settings && container.settings.set) {
            container.settings.set(settings);
        }

        if (shouldSyncFilterTypes) {
            scheduleFilterTypeSync();
        }
    }

    function handleSavePreset(event) {
        event.preventDefault();

        var $button = $(event.currentTarget);
        var container = getEditedFilterControllerContainer();
        var settings = getContainerSettings(container);
        var payload = buildPresetPayload(settings);

        if (!config.canManagePresets) {
            setEditorActionStatus($button, i18n.presetSaveFailed || 'Could not save preset.', 'error');
            return;
        }

        if (!payload.preset.name || !String(payload.preset.name).trim()) {
            setEditorActionStatus($button, i18n.presetNameRequired || 'Add a preset name before saving.', 'error');
            return;
        }

        $button.prop('disabled', true);
        setEditorActionStatus($button, i18n.presetSaving || 'Saving preset...', 'loading');

        $.ajax({
            url: config.presetSaveUrl || ((config.restUrl || '') + 'filter-presets'),
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            beforeSend: function (xhr) {
                if (config.restNonce) {
                    xhr.setRequestHeader('X-WP-Nonce', config.restNonce);
                }
            }
        }).done(function (response) {
            response = response || {};
            upsertPresetOption(response.preset);
            setEditorSettings(container, response.editor_update || {});
            setEditorActionStatus($button, i18n.presetSaved || 'Preset saved.', 'success');
        }).fail(function (xhr) {
            var response = xhr && xhr.responseJSON ? xhr.responseJSON : {};
            setEditorActionStatus($button, response.message || i18n.presetSaveFailed || 'Could not save preset.', 'error');
        }).always(function () {
            $button.prop('disabled', false);
        });
    }

    function handleImportPreset(event) {
        event.preventDefault();

        var $button = $(event.currentTarget);
        var container = getEditedFilterControllerContainer();
        var settings = getContainerSettings(container);
        var presetId = getSetting(settings, 'filter_preset', '');
        var localFilters = getSetting(settings, 'filters', []);

        if (!config.canManagePresets) {
            setEditorActionStatus($button, i18n.presetImportFailed || 'Could not import preset.', 'error');
            return;
        }

        if (!presetId) {
            setEditorActionStatus($button, i18n.presetSelectRequired || 'Select a preset first.', 'error');
            return;
        }

        if (Array.isArray(localFilters) && localFilters.length && !window.confirm(i18n.presetImportConfirm || 'Importing this preset will replace the current local widget filter controls. Continue?')) {
            return;
        }

        $button.prop('disabled', true);
        setEditorActionStatus($button, i18n.presetImporting || 'Importing preset...', 'loading');

        $.ajax({
            url: (config.restUrl || '') + 'filter-presets/' + encodeURIComponent(presetId),
            method: 'GET',
            beforeSend: function (xhr) {
                if (config.restNonce) {
                    xhr.setRequestHeader('X-WP-Nonce', config.restNonce);
                }
            }
        }).done(function (response) {
            response = response || {};
            setEditorSettings(container, response.widget_settings || {});
            setEditorActionStatus($button, i18n.presetImported || 'Preset imported as local widget controls.', 'success');
        }).fail(function (xhr) {
            var response = xhr && xhr.responseJSON ? xhr.responseJSON : {};
            setEditorActionStatus($button, response.message || i18n.presetImportFailed || 'Could not import preset.', 'error');
        }).always(function () {
            $button.prop('disabled', false);
        });
    }

    function getPreviewDocument() {
        var iframe = document.querySelector('#elementor-preview-iframe');
        return iframe && iframe.contentDocument ? iframe.contentDocument : null;
    }

    function ensurePreviewStyles(doc) {
        if (!doc || doc.getElementById('eit-editor-highlight-styles')) {
            return;
        }

        var style = doc.createElement('style');
        style.id = 'eit-editor-highlight-styles';
        style.textContent = '.eit-editor-highlight{outline:3px solid #ff2f92!important;outline-offset:4px!important;box-shadow:0 0 0 9999px rgba(255,47,146,.08)!important;position:relative!important;z-index:9999!important;}';
        doc.head.appendChild(style);
    }

    function scanTargets() {
        var doc = getPreviewDocument();

        if (!doc) {
            currentTargets = [];
            return currentTargets;
        }

        ensurePreviewStyles(doc);

        currentTargets = detectListings(doc).map(function (entry, index) {
            return {
                label: getListingLabel(entry.element, entry.items, index),
                selector: getStableSelector(entry.element),
                element: entry.element,
                count: entry.items.length
            };
        }).filter(function (entry) {
            return entry.selector;
        });

        return currentTargets;
    }

    function detectListings(doc) {
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
                if (element.closest('.eit-filter-controller')) {
                    return;
                }

                var items = detectItems(element);

                if (items.length < 2) {
                    return;
                }

                if (found.some(function (entry) {
                    return entry.element === element || entry.element.contains(element);
                })) {
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
                return item.offsetParent !== null;
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
                return child.offsetParent !== null;
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

    function getListingLabel(element, items, index) {
        var base = 'Listing';

        if (element.matches('.jet-listing-grid') || element.querySelector('.jet-listing-grid__item')) {
            base = 'JetEngine Listing';
        } else if (element.matches('.products') || element.querySelector('.product')) {
            base = 'Products';
        } else if (element.matches('.elementor-posts-container, .elementor-widget-posts') || element.querySelector('.elementor-post')) {
            base = 'Posts';
        }

        return base + ' #' + (index + 1) + ' (' + items.length + ')';
    }

    function getStableSelector(element) {
        if (element.id) {
            return '#' + element.id;
        }

        var elementorId = Array.prototype.find.call(element.classList || [], function (className) {
            return /^elementor-element-[a-z0-9]+$/.test(className);
        });

        if (elementorId) {
            return '.' + elementorId;
        }

        var parentElementor = element.closest('[data-id].elementor-element');

        if (parentElementor) {
            var dataId = parentElementor.getAttribute('data-id');
            return dataId ? '.elementor-element-' + dataId : '';
        }

        return '';
    }

    function renderPanelHelper() {
        var $control = $('.elementor-control-target_selector');
        var input = $control.find('input[data-setting="target_selector"], textarea[data-setting="target_selector"]').get(0);

        if (!$control.length || !input) {
            return;
        }

        var targets = scanTargets();
        var $helper = $control.find('.eit-editor-targets');

        if (!$helper.length) {
            $helper = $('<div/>', { class: 'eit-editor-targets' }).appendTo($control);
        }

        $helper.empty();
        $('<div/>', { class: 'eit-editor-targets__title', text: i18n.detectedTargets || 'Detected listings' }).appendTo($helper);

        if (!targets.length) {
            $('<p/>', { class: 'eit-editor-targets__empty', text: i18n.noTargets || 'No listings detected on this canvas yet.' }).appendTo($helper);
            return;
        }

        targets.forEach(function (target) {
            $('<button/>', {
                type: 'button',
                class: 'eit-editor-target',
                text: target.label,
                'data-selector': target.selector
            }).on('mouseenter', function () {
                highlight(target.element);
            }).on('mouseleave', function () {
                clearHighlights();
            }).on('click', function () {
                input.value = target.selector;
                $(input).trigger('input').trigger('change').trigger('keyup');
                clearHighlights();
            }).appendTo($helper);
        });

        $('<p/>', { class: 'eit-editor-targets__hint', text: i18n.fallback || 'Manual selector remains available for difficult cases.' }).appendTo($helper);
    }

    function getEditedElementView() {
        var panel;
        var page;

        if (!window.elementor || !elementor.getPanelView) {
            return null;
        }

        panel = elementor.getPanelView();
        page = panel && panel.getCurrentPageView ? panel.getCurrentPageView() : null;

        if (!page || !page.getOption) {
            return null;
        }

        return page.getOption('editedElementView') || null;
    }

    function getWidgetType(view) {
        if (view && view.model && view.model.get) {
            return view.model.get('widgetType');
        }

        if (view && view.container && view.container.model && view.container.model.get) {
            return view.container.model.get('widgetType');
        }

        return '';
    }

    function getEditedFilterControllerContainer() {
        var view = getEditedElementView();

        if (getWidgetType(view) !== 'eit-filter-controller') {
            return null;
        }

        if (view && view.getContainer) {
            return view.getContainer();
        }

        return view && view.container ? view.container : null;
    }

    function readModelValue(model, key) {
        if (!model) {
            return undefined;
        }

        if (model.get) {
            return model.get(key);
        }

        if (model.attributes && undefined !== model.attributes[key]) {
            return model.attributes[key];
        }

        return model[key];
    }

    function normalizeFilterRows(filters) {
        var rows = [];

        if (!filters) {
            return null;
        }

        if (filters.toJSON) {
            filters = filters.toJSON();
        } else if (filters.models) {
            filters = filters.models;
        }

        if (!Array.isArray(filters) && 'object' === typeof filters && undefined !== readModelValue(filters, 'type')) {
            filters = [filters];
        }

        if (!Array.isArray(filters) && 'object' === typeof filters) {
            filters = Object.keys(filters).map(function (key) {
                return filters[key];
            });
        }

        if (!Array.isArray(filters)) {
            return null;
        }

        filters.forEach(function (filter) {
            var type;

            if (!filter) {
                return;
            }

            if (filter.toJSON) {
                filter = filter.toJSON();
            } else if (filter.attributes) {
                filter = filter.attributes;
            }

            type = readModelValue(filter, 'type') || 'search';

            rows.push({
                type: type
            });
        });

        return rows;
    }

    function getFilterRows(container) {
        var panelRows;
        var filters;
        var normalized;

        panelRows = readFilterRowsFromPanel();

        if (null !== panelRows) {
            return panelRows;
        }

        filters = container && container.settings && container.settings.get ? container.settings.get('filters') : null;
        normalized = normalizeFilterRows(filters);

        if (normalized) {
            return normalized;
        }

        return null;
    }

    function readFilterRowsFromPanel() {
        var rows = [];
        var $control = $('.elementor-control-filters');

        if (!$control.length) {
            return null;
        }

        $control.find('select[data-setting="type"]').each(function () {
            rows.push({
                type: this.value || 'search'
            });
        });

        return rows;
    }

    function hasType(types, type) {
        return types.indexOf(type) !== -1;
    }

    function computeFilterTypeFlags(filters) {
        var types = [];
        var fieldTypes = ['search', 'select', 'range', 'date'];
        var optionTypes = ['checkbox', 'radio', 'chips', 'toggle', 'swatch', 'rating'];

        filters.forEach(function (filter) {
            var type = filter && filter.type ? filter.type : 'search';

            if (types.indexOf(type) === -1) {
                types.push(type);
            }
        });

        return {
            eit_filter_has_field_controls: types.some(function (type) {
                return hasType(fieldTypes, type);
            }) ? 'yes' : '',
            eit_filter_has_option_controls: types.some(function (type) {
                return hasType(optionTypes, type);
            }) ? 'yes' : '',
            eit_filter_has_range_controls: hasType(types, 'range') ? 'yes' : '',
            eit_filter_has_rating_controls: hasType(types, 'rating') ? 'yes' : ''
        };
    }

    function getCurrentFilterTypeFlags(container) {
        var current = {};

        filterTypeStateControls.forEach(function (controlId) {
            current[controlId] = container && container.settings && container.settings.get ? (container.settings.get(controlId) || '') : '';
        });

        return current;
    }

    function hasFilterTypeFlagChanges(current, next) {
        return filterTypeStateControls.some(function (controlId) {
            return (current[controlId] || '') !== (next[controlId] || '');
        });
    }

    function setHiddenFilterTypeInputs(flags, triggerEvents) {
        filterTypeStateControls.forEach(function (controlId) {
            var input = document.querySelector('[data-setting="' + controlId + '"]');

            if (input && input.value !== flags[controlId]) {
                input.value = flags[controlId];

                if (triggerEvents) {
                    $(input).trigger('input').trigger('change');
                }
            }
        });
    }

    function setPanelControlGroupVisible(controlIds, isVisible) {
        var didTouchControls = false;

        $('.elementor-control').each(function () {
            var element = this;
            var className = element.className || '';
            var matches = controlIds.some(function (controlId) {
                return className.indexOf('elementor-control-' + controlId) !== -1;
            });

            if (!matches) {
                return;
            }

            didTouchControls = true;
            element.style.display = isVisible ? '' : 'none';

            if (isVisible) {
                element.removeAttribute('aria-hidden');
            } else {
                element.setAttribute('aria-hidden', 'true');
            }
        });

        return didTouchControls;
    }

    function applyStylePanelCadence(flags) {
        setPanelControlGroupVisible(styleCadenceControls.options, 'yes' === flags.eit_filter_has_option_controls);
        setPanelControlGroupVisible(styleCadenceControls.range, 'yes' === flags.eit_filter_has_range_controls);
        setPanelControlGroupVisible(styleCadenceControls.rating, 'yes' === flags.eit_filter_has_rating_controls);
    }

    function syncFilterTypeState() {
        var container = getEditedFilterControllerContainer();
        var filters;
        var flags;
        var current;

        if (!container) {
            return;
        }

        filters = getFilterRows(container);

        if (!filters) {
            return;
        }

        flags = computeFilterTypeFlags(filters);
        current = getCurrentFilterTypeFlags(container);
        setHiddenFilterTypeInputs(flags, false);
        applyStylePanelCadence(flags);

        if (!hasFilterTypeFlagChanges(current, flags)) {
            return;
        }

        if (window.$e && $e.run) {
            try {
                $e.run('document/elements/settings', {
                    container: container,
                    settings: flags,
                    options: {
                        render: false,
                        renderUI: true
                    }
                });
                return;
            } catch (error) {
                markEditorCompatFallback('style-state-command');
                // Fall through to the legacy input/model path if Elementor changes the command contract.
            }
        }

        if (container.settings && container.settings.set) {
            container.settings.set(flags);
        }

        setHiddenFilterTypeInputs(flags, true);
    }

    function scheduleFilterTypeSync() {
        window.clearTimeout(filterTypeSyncTimer);
        window.clearTimeout(filterTypeFollowupTimer);
        filterTypeSyncTimer = window.setTimeout(syncFilterTypeState, 80);
        filterTypeFollowupTimer = window.setTimeout(syncFilterTypeState, 320);
    }

    function bindFilterTypeHooks() {
        if (filterTypeHooksBound || !window.elementor || !elementor.hooks || !elementor.hooks.addAction) {
            return;
        }

        filterTypeHooksBound = true;

        elementor.hooks.addAction('panel/open_editor/widget/eit-filter-controller', function () {
            scheduleFilterTypeSync();
        });
    }

    function highlight(element) {
        clearHighlights();

        if (element) {
            element.classList.add('eit-editor-highlight');
        }
    }

    function clearHighlights() {
        var doc = getPreviewDocument();

        if (!doc) {
            return;
        }

        Array.prototype.forEach.call(doc.querySelectorAll('.eit-editor-highlight'), function (element) {
            element.classList.remove('eit-editor-highlight');
        });
    }

    function startPanelLoop() {
        if (panelTimer) {
            return;
        }

        panelTimer = window.setInterval(function () {
            renderPanelHelper();
            renderEditorCompatWarning();
        }, 900);
        window.setInterval(syncFilterTypeState, 900);
        renderPanelHelper();
        renderEditorCompatWarning();
        bindFilterTypeHooks();
        scheduleFilterTypeSync();
    }

    $(document).on('input change click', '.elementor-control-filters', scheduleFilterTypeSync);
    $(document).on('click', '.elementor-panel-navigation-tab, .elementor-tab-control-content, .elementor-tab-control-style, .elementor-tab-control-advanced', scheduleFilterTypeSync);
    $(document).on('click', '[data-eit-save-preset]', handleSavePreset);
    $(document).on('click', '[data-eit-import-preset]', handleImportPreset);

    $(window).on('elementor:init', function () {
        bindFilterTypeHooks();
        startPanelLoop();
    });

    $(function () {
        startPanelLoop();
    });
})(jQuery);
