(function () {
    'use strict';

    function closest(element, selector) {
        while (element && element !== document) {
            if (element.matches(selector)) {
                return element;
            }

            element = element.parentElement;
        }

        return null;
    }

    function addRow(button) {
        var type = button.getAttribute('data-eit-add-row');
        var scope = closest(button, '[data-eit-repeat-scope]') || document;
        var template = scope.querySelector('[data-eit-template="' + type + '"]');
        var repeater = scope.querySelector('[data-eit-repeater="' + type + '"]');

        if (!template || !repeater) {
            return;
        }

        var index = parseInt(repeater.getAttribute('data-next-index'), 10) || 0;
        var html = template.innerHTML.replace(/__index__/g, String(index));
        var container = document.createElement('div');

        container.innerHTML = html.trim();

        if (container.firstElementChild) {
            repeater.querySelectorAll('.eit-empty-node').forEach(function (node) {
                node.parentElement.removeChild(node);
            });
            repeater.appendChild(container.firstElementChild);
            repeater.setAttribute('data-next-index', String(index + 1));
        }
    }

    function removeRow(button) {
        var row = closest(button, '.eit-repeater-row');

        if (row) {
            row.parentElement.removeChild(row);
        }
    }

    function updateRowTitle(input) {
        var row = closest(input, '.eit-node, .eit-filter-card');
        var title = row ? row.querySelector('.eit-node__top strong, .eit-filter-card__top input[name$="[label]"]') : null;

        if (!title || !input.value.trim()) {
            return;
        }

        if (title !== input) {
            title.textContent = input.value.trim();
        }
        row.setAttribute('data-eit-builder-title', input.value.trim());
    }

    function selectBuilderItem(element) {
        var layout = closest(element, '.eit-builder-layout');
        var titleTarget = layout ? layout.querySelector('[data-eit-inspector-title]') : null;
        var typeTarget = layout ? layout.querySelector('[data-eit-inspector-type]') : null;

        if (!layout || !titleTarget || !typeTarget) {
            return;
        }

        layout.querySelectorAll('.is-selected').forEach(function (selected) {
            selected.classList.remove('is-selected');
        });

        element.classList.add('is-selected');
        titleTarget.textContent = element.getAttribute('data-eit-builder-title') || 'Selected item';
        typeTarget.textContent = element.getAttribute('data-eit-builder-type') || 'Builder item';
    }

    /* ── Modal helpers ── */

    function openModal(overlay) {
        if (!overlay) { return; }
        overlay.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(overlay) {
        if (!overlay) { return; }
        overlay.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    function closeAllModals() {
        document.querySelectorAll('.eit-modal-overlay.is-open').forEach(function (m) {
            closeModal(m);
        });
    }

    /* ── Source-of-truth layout states ── */

    function selectSotState(button) {
        var workbench = closest(button, '[data-eit-sot]');
        var target = button.getAttribute('data-eit-sot-target');
        var titleTarget = workbench ? workbench.querySelector('[data-eit-sot-inspector-title]') : null;
        var titles = {
            root: 'Filter Preset (Root)',
            provider: 'Provider Contract',
            modules: 'Price Range Module',
            output: 'Controller Output'
        };

        if (!workbench || !target) {
            return;
        }

        workbench.querySelectorAll('[data-eit-sot-target]').forEach(function (item) {
            item.classList.toggle('is-active', item.getAttribute('data-eit-sot-target') === target);
        });

        workbench.querySelectorAll('[data-eit-sot-panel]').forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-eit-sot-panel') === target);
        });

        workbench.querySelectorAll('[data-eit-sot-inspector-panel]').forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-eit-sot-inspector-panel') === target);
        });

        if (titleTarget) {
            titleTarget.textContent = titles[target] || 'Selected object';
        }
    }

    /* ── Filter preview builder ── */

    function readPresetFilters(form) {
        var filters = {};

        form.querySelectorAll('[name^="preset[filters]"]').forEach(function (input) {
            var name = input.getAttribute('name') || '';
            var match = name.match(/^preset\[filters\]\[([^\]]+)\]\[([^\]]+)\]/);

            if (!match || name.indexOf('[options_items]') !== -1) {
                return;
            }

            if (!filters[match[1]]) {
                filters[match[1]] = {};
            }

            if (input.type === 'checkbox') {
                filters[match[1]][match[2]] = input.checked ? '1' : '';
                return;
            }

            filters[match[1]][match[2]] = input.value;
        });

        return Object.keys(filters).sort(function (a, b) {
            return parseInt(a, 10) - parseInt(b, 10);
        }).map(function (key) {
            return filters[key];
        }).filter(function (filter) {
            return filter.enabled !== '' && filter.enabled !== '0';
        });
    }

    function optionLabels(options) {
        var labels = [];

        String(options || '').split(/\r?\n/).forEach(function (line) {
            var parts = line.split('|');
            var label = (parts[1] || parts[0] || '').trim();

            if (label) {
                labels.push(label);
            }
        });

        return labels;
    }

    function buildFilterPreview() {
        var form = document.getElementById('eit-filter-preset-form');
        if (!form) { return ''; }

        var filters = readPresetFilters(form);
        var html = '';
        var sortCheck = form.querySelector('input[name="preset[show_sort]"]');
        var activeModules = filters.length + (sortCheck && sortCheck.checked ? 1 : 0);

        html += '<div class="eit-preview-notice">Preview uses current form values. No save required.</div>';
        html += '<div class="eit-preview-device-tabs"><span class="is-active">Desktop</span><span>Tablet</span><span>Mobile</span></div>';
        html += '<div class="eit-preview-modal-grid">';
        html += '<section class="eit-preview-controller-surface">';

        filters.forEach(function (filter) {
            if (filter.type !== 'search') {
                return;
            }

            var placeholder = filter.placeholder || 'Search products...';
            html += '<div class="eit-preview-search"><span>Search</span><input type="text" placeholder="' + escHtml(placeholder) + '" readonly /></div>';
        });

        html += '<div class="eit-preview-filter-columns">';
        filters.forEach(function (filter) {
            var label = filter.label || 'Filter';
            var type = filter.type || 'search';
            var labels = optionLabels(filter.options);

            if (type === 'search') { return; }

            html += '<div class="eit-preview-filter-item">';
            html += '<strong>' + escHtml(label) + ' <small>' + escHtml(type.replace('_', ' ')) + '</small></strong>';

            if (type === 'range') {
                var mn = filter.range_min || '0';
                var mx = filter.range_max || '100';
                html += '<div class="eit-preview-range"><span>' + escHtml(mn) + '</span><i><b></b></i><span>' + escHtml(mx) + '</span></div>';
                html += '<div class="eit-preview-range-inputs"><span>' + escHtml(mn) + '</span><span>' + escHtml(mx) + '</span></div>';
            } else if (type === 'checkbox' || type === 'radio' || type === 'chips') {
                html += '<div class="eit-preview-check-list">';
                (labels.length ? labels.slice(0, 4) : ['All Categories', 'Accessories', 'Clothing']).forEach(function (option, index) {
                    html += '<label><input type="' + (type === 'radio' ? 'radio' : 'checkbox') + '" ' + (index === 0 ? 'checked' : '') + ' disabled /> <span>' + escHtml(option) + '</span></label>';
                });
                html += '</div>';
            } else if (type === 'select') {
                html += '<div class="eit-preview-select">Select...</div>';
            } else if (type === 'toggle') {
                html += '<div class="eit-preview-toggle"><i></i><span>Enabled</span></div>';
            } else if (type === 'swatch') {
                html += '<div class="eit-preview-swatches"><span style="background:#111111"></span><span style="background:#176db8"></span><span style="background:#e5484d"></span><span style="background:#f0c419"></span></div>';
            } else if (type === 'rating') {
                html += '<div class="eit-preview-rating">5 stars and up<br />4 stars and up<br />3 stars and up</div>';
            } else {
                html += '<div class="eit-preview-select">Configured control</div>';
            }

            html += '</div>';
        });
        html += '</div>';

        var chipsCheck = form.querySelector('input[name="preset[show_active_chips]"]');
        if (chipsCheck && chipsCheck.checked) {
            html += '<div class="eit-preview-chips-row">';
            html += '<span class="eit-preview-chip">Accessories <span class="remove">x</span></span>';
            html += '<span class="eit-preview-chip">Price: $10 - $250 <span class="remove">x</span></span>';
            html += '<span class="eit-preview-chip">Blue <span class="remove">x</span></span>';
            html += '</div>';
        }

        var rcCheck = form.querySelector('input[name="preset[show_result_count]"]');
        var rcText = form.querySelector('input[name="preset[result_count_text]"]');
        var countDisplay = rcText ? rcText.value.replace('{count}', '128') : '128 results';
        var applyInput = form.querySelector('input[name="preset[apply_text]"]');
        var resetInput = form.querySelector('input[name="preset[reset_text]"]');
        var applyText = applyInput ? applyInput.value || 'Apply filters' : 'Apply filters';
        var resetText = resetInput ? resetInput.value || 'Reset' : 'Reset';

        html += '<div class="eit-preview-bar">';
        html += rcCheck && rcCheck.checked ? '<strong>' + escHtml(countDisplay) + '</strong>' : '<span></span>';
        if (sortCheck && sortCheck.checked) {
            var sortLabel = form.querySelector('input[name="preset[sort_label]"]');
            html += '<span>' + escHtml(sortLabel ? sortLabel.value || 'Sort by' : 'Sort by') + ': Default</span>';
        }
        html += '</div>';

        html += '<div class="eit-preview-actions">';
        html += '<button type="button">' + escHtml(resetText) + '</button>';
        html += '<button type="button" class="primary">' + escHtml(applyText) + '</button>';
        html += '</div>';

        var perPage = form.querySelector('input[name="preset[per_page]"]');
        var countNumber = parseInt(String(countDisplay).replace(/[^\d]/g, ''), 10) || 128;
        var total = perPage ? Math.max(1, Math.ceil(countNumber / (parseInt(perPage.value, 10) || 9))) : 1;
        var pages = [];
        var page;

        if (total <= 5) {
            for (page = 1; page <= total; page += 1) {
                pages.push(String(page));
            }
        } else {
            pages = ['1', '2', '3', '...', String(total)];
        }

        html += '<div class="eit-preview-pagination-mock">';
        pages.forEach(function (pageLabel, index) {
            html += '<span class="' + (index === 0 ? 'active' : '') + '">' + escHtml(pageLabel) + '</span>';
        });
        html += '</div>';
        html += '</section>';

        html += '<aside class="eit-preview-state-panel">';
        html += '<h4>State Summary</h4>';
        html += row('URL Sync', form.querySelector('input[name="preset[sync_url]"]') && form.querySelector('input[name="preset[sync_url]"]').checked ? 'On' : 'Off');
        html += row('Apply Mode', escHtml((form.querySelector('[name="preset[apply_mode]"]') || {}).value || 'auto'));
        html += row('Items Per Page', escHtml((perPage || {}).value || '9'));
        html += row('Active Modules', String(activeModules));
        html += row('Controller ID', escHtml((form.querySelector('[name="preset[slug]"]') || {}).value || 'product-archive'));
        html += '<div class="eit-preview-info">Controller preview only. No listing content is rendered.</div>';
        html += '</aside>';
        html += '</div>';

        return html;
    }

    /* ── CPT preview builder ── */

    function buildCptPreview() {
        var form = document.getElementById('eit-cpt-form');
        if (!form) { return ''; }

        function val(name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el ? el.value || '' : '';
        }
        function chk(name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el ? el.checked : false;
        }

        var html = '';

        html += row('Slug', val('cpt[slug]') || '\u2014');
        html += row('Singular', val('cpt[singular]') || '\u2014');
        html += row('Plural', val('cpt[plural]') || '\u2014');
        html += row('Rewrite', val('cpt[rewrite_slug]') || '\u2014');
        html += row('Public', chk('cpt[public]') ? '\u2705 Yes' : '\u274c No');
        html += row('REST / Editor', chk('cpt[show_in_rest]') ? '\u2705 Yes' : '\u274c No');
        html += row('Archive', chk('cpt[has_archive]') ? '\u2705 Yes' : '\u274c No');
        html += row('Hierarchical', chk('cpt[hierarchical]') ? '\u2705 Yes' : '\u274c No');
        html += row('Menu Icon', val('cpt[menu_icon]') || 'dashicons-admin-post');

        // Supports
        var supports = [];
        form.querySelectorAll('input[name="cpt[supports][]"]:checked').forEach(function (cb) {
            supports.push(cb.parentElement.querySelector('span').textContent.trim());
        });
        html += row('Supports', supports.length ? supports.map(function (s) { return '<span class="eit-preview-chip">' + escHtml(s) + '</span>'; }).join(' ') : '\u2014');

        // Taxonomies
        var taxNames = [];
        form.querySelectorAll('input[name^="cpt[taxonomies]"][name$="[plural]"]').forEach(function (input) {
            if (input.value.trim()) { taxNames.push(input.value.trim()); }
        });
        html += row('Taxonomies', taxNames.length ? taxNames.map(function (t) { return '<span class="eit-preview-chip">' + escHtml(t) + '</span>'; }).join(' ') : 'None');

        // Meta fields
        var metaNames = [];
        form.querySelectorAll('input[name^="cpt[meta_fields]"][name$="[label]"]').forEach(function (input) {
            if (input.value.trim()) { metaNames.push(input.value.trim()); }
        });
        html += row('Meta Fields', metaNames.length ? metaNames.map(function (m) { return '<span class="eit-preview-chip">' + escHtml(m) + '</span>'; }).join(' ') : 'None');

        return html;
    }

    /* ── Integration preview builder ── */

    function buildIntegrationPreview() {
        var form = document.getElementById('eit-integration-form');
        if (!form) { return ''; }

        var title = form.getAttribute('data-eit-pattern-title') || 'Integration module';
        var description = form.getAttribute('data-eit-pattern-description') || '';
        var statusSelect = form.querySelector('select[name="pattern[status]"]');
        var status = statusSelect ? statusSelect.options[statusSelect.selectedIndex].text : 'Draft';
        var html = '';

        html += row('Module', escHtml(title));
        html += row('Status', escHtml(status));
        if (description) {
            html += row('Purpose', escHtml(description));
        }

        var fields = [];
        form.querySelectorAll('[name^="pattern[values]"]').forEach(function (input) {
            var node = closest(input, '.eit-arch-node, .eit-rt-node');
            var strong = node ? node.querySelector('strong') : null;
            var label = strong ? strong.textContent.trim() : input.name.replace(/^pattern\[values\]\[|\]$/g, '');
            var value = '';

            if (input.type === 'checkbox') {
                value = input.checked ? 'Enabled' : 'Disabled';
            } else if (input.tagName === 'SELECT') {
                value = input.options[input.selectedIndex] ? input.options[input.selectedIndex].text : input.value;
            } else {
                value = input.value || '—';
            }

            fields.push({ label: label, value: value });
        });

        if (fields.length) {
            html += '<div class="eit-preview-section-title">Saved contract</div>';
            fields.forEach(function (field) {
                html += row(field.label, escHtml(field.value));
            });
        }

        html += '<div class="eit-preview-actions">';
        html += '<button type="button">Admin contract only</button>';
        html += '<button type="button" class="primary">Future adapter boundary</button>';
        html += '</div>';

        return html;
    }

    function row(label, value) {
        return '<dl class="eit-reg-preview-row"><dt>' + escHtml(label) + '</dt><dd>' + value + '</dd></dl>';
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /* ── Event delegation ── */

    document.addEventListener('click', function (event) {
        var addButton = event.target.closest('[data-eit-add-row]');
        var removeButton = event.target.closest('[data-eit-remove-row]');
        var deleteLink = event.target.closest('.eit-admin-wrap .submitdelete');
        var previewBtn = event.target.closest('[data-eit-preview]');
        var modalClose = event.target.closest('[data-eit-modal-close]');
        var sotButton = event.target.closest('[data-eit-sot-target]');
        var builderItem = event.target.closest('.eit-arch-column, .eit-runtime-rail, .eit-filter-card, .eit-builder-block, .eit-node, .eit-rt-node');

        if (addButton) {
            event.preventDefault();
            addRow(addButton);
            return;
        }

        if (removeButton) {
            event.preventDefault();
            removeRow(removeButton);
            return;
        }

        if (deleteLink && !window.confirm('Delete this definition? Existing posts or option data outside this definition will not be removed.')) {
            event.preventDefault();
            return;
        }

        if (sotButton) {
            event.preventDefault();
            selectSotState(sotButton);
            return;
        }

        if (previewBtn) {
            event.preventDefault();
            var filterModal = document.getElementById('eit-preview-modal');
            var cptModal = document.getElementById('eit-cpt-preview-modal');
            var integrationModal = document.getElementById('eit-integration-preview-modal');

            if (filterModal) {
                var content = document.getElementById('eit-preview-content');
                if (content) { content.innerHTML = buildFilterPreview(); }
                openModal(filterModal);
            } else if (cptModal) {
                var cptContent = document.getElementById('eit-cpt-preview-content');
                if (cptContent) { cptContent.innerHTML = buildCptPreview(); }
                openModal(cptModal);
            } else if (integrationModal) {
                var integrationContent = document.getElementById('eit-integration-preview-content');
                if (integrationContent) { integrationContent.innerHTML = buildIntegrationPreview(); }
                openModal(integrationModal);
            }
            return;
        }

        if (modalClose) {
            var overlay = closest(modalClose, '.eit-modal-overlay');
            closeModal(overlay);
            return;
        }

        // Click outside modal panel closes it
        if (event.target.classList && event.target.classList.contains('eit-modal-overlay')) {
            closeModal(event.target);
            return;
        }

        if (builderItem && !event.target.closest('input, select, textarea, button, a')) {
            selectBuilderItem(builderItem);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAllModals();
        }
    });

    document.addEventListener('input', function (event) {
        if (
            event.target.matches('input[name$="[label]"]') ||
            event.target.matches('input[name$="[plural]"]') ||
            event.target.matches('input[name$="[singular]"]')
        ) {
            updateRowTitle(event.target);
        }
    });
})();
