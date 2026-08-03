/**
 * Live filter of the global settings page, in the browser, without reload.
 *
 * The settings page concatenates the fieldsets of every module in a single long
 * form. A sticky search box filters the fields on their label and description
 * (whatever the input type), highlights the matches, and hides the element
 * groups (fieldsets) that have no visible field. The form itself is not
 * altered, so hidden fields are still submitted and the page keeps working when
 * javascript is disabled.
 */

(function () {
    'use strict';

    // Scope: the whole form on the global settings page, only the settings
    // section on the site edit page (its info section must not be filtered).
    var root = document.body.classList.contains('settings')
        ? document.querySelector('#content form')
        : document.getElementById('site-settings');
    if (!root) {
        return;
    }

    var fields = Array.prototype.slice.call(root.querySelectorAll('.field'));
    if (!fields.length) {
        return;
    }

    // Only fieldsets that carry an element-group heading are hidden when empty.
    var groups = Array.prototype.slice
        .call(root.querySelectorAll('fieldset'))
        .filter(function (fieldset) {
            return fieldset.querySelector('.fieldsets-heading');
        });

    // Save the original markup of each highlighted container, so highlighting
    // is reset before each new query without corrupting inner markup (links in
    // descriptions).
    var highlight = function (container, query) {
        if (!container) {
            return;
        }
        // Remove previous highlights by unwrapping the <mark> nodes. This
        // preserves sibling nodes and their event listeners (e.g. the per-field
        // buttons injected by other modules such as SiteHub), unlike a
        // container.innerHTML rewrite, which destroys them.
        container.querySelectorAll('mark.setting-filter-hit').forEach(function (mark) {
            mark.replaceWith(document.createTextNode(mark.textContent));
        });
        container.normalize();
        if (!query) {
            return;
        }
        var walker = document.createTreeWalker(
            container,
            NodeFilter.SHOW_TEXT,
            null
        );
        var nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }
        nodes.forEach(function (node) {
            var text = node.nodeValue;
            var lower = text.toLowerCase();
            var index = lower.indexOf(query);
            if (index === -1) {
                return;
            }
            var fragment = document.createDocumentFragment();
            var last = 0;
            while (index !== -1) {
                fragment.appendChild(
                    document.createTextNode(text.slice(last, index))
                );
                var mark = document.createElement('mark');
                mark.className = 'setting-filter-hit';
                mark.textContent = text.slice(index, index + query.length);
                fragment.appendChild(mark);
                last = index + query.length;
                index = lower.indexOf(query, last);
            }
            fragment.appendChild(document.createTextNode(text.slice(last)));
            node.parentNode.replaceChild(fragment, node);
        });
    };

    // Strings are injected by the module (see appendSettingsFilterAssets), with
    // an English fallback when they are not available.
    var i18n = (window.EasyAdmin && window.EasyAdmin.settingsFilter) || {};
    var placeholder = i18n.placeholder || 'Filter settings…';
    var countTemplate = i18n.count || '%s settings';

    var apply = function () {
        var query = input.value.trim().toLowerCase();
        var visible = 0;
        fields.forEach(function (field) {
            var meta = field.querySelector('.field-meta');
            // Structural markers (e.g. per-module anchors) carry no meta: hide
            // them whenever a query is active, and never count them.
            if (!meta) {
                field.classList.toggle('setting-hidden', !!query);
                return;
            }
            var hay = (originals.get(meta) || meta.textContent).toLowerCase();
            var match = !query || hay.indexOf(query) !== -1;
            field.classList.toggle('setting-hidden', !match);
            highlight(meta, match ? query : '');
            if (match) {
                visible++;
            }
        });
        groups.forEach(function (group) {
            var hasVisible =
                group.querySelector('.field:not(.setting-hidden) .field-meta');
            group.classList.toggle('setting-hidden', !hasVisible);
        });
        count.textContent = query
            ? countTemplate.replace('%s', visible)
            : '';
    };

    var wrapper = document.createElement('div');
    wrapper.className = 'setting-filter';
    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'setting-filter-input';
    input.setAttribute('placeholder', placeholder);
    input.setAttribute('aria-label', placeholder);
    var count = document.createElement('span');
    count.className = 'setting-filter-count';
    count.setAttribute('aria-live', 'polite');
    wrapper.appendChild(input);
    wrapper.appendChild(count);
    root.insertBefore(wrapper, root.firstChild);

    var timer = null;
    input.addEventListener('input', function () {
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(apply, 120);
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            input.value = '';
            apply();
        } else if (event.key === 'Enter') {
            event.preventDefault();
        }
    });

    // Global shortcut "/" to focus the filter, unless already typing.
    document.addEventListener('keydown', function (event) {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        var active = document.activeElement;
        if (active && active.matches('input, textarea, select, [contenteditable]')) {
            return;
        }
        event.preventDefault();
        input.focus();
    });

    // Focus the filter on load, without scrolling the page to the input.
    input.focus({ preventScroll: true });
})();
