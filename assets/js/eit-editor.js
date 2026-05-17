(function ($) {
    'use strict';

    var config = window.eitEditorConfig || {};
    var i18n = config.i18n || {};
    var currentTargets = [];
    var panelTimer = null;

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

        panelTimer = window.setInterval(renderPanelHelper, 900);
        renderPanelHelper();
    }

    $(window).on('elementor:init', function () {
        startPanelLoop();
    });

    $(function () {
        startPanelLoop();
    });
})(jQuery);
