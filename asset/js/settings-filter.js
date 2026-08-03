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

    // Strings and the field classification are injected by the module (see
    // appendSettingsFilterAssets), with an English fallback for the strings.
    var i18n = (window.EasyAdmin && window.EasyAdmin.settingsFilter) || {};
    var placeholder = i18n.placeholder || 'Filter settings…';
    var countTemplate = i18n.count || '%s settings';
    var kinds = i18n.kinds || {};

    // The kind of a field (structural | literal | manual) comes from the type
    // of its form element, classified server-side like the SiteHub module. A
    // field is a text field when its kind is "literal".
    var kindCache = new WeakMap();
    var isTextField = function (field) {
        if (kindCache.has(field)) {
            return kindCache.get(field);
        }
        var el = field.querySelector('input[name], select[name], textarea[name]');
        var base = el && el.name ? el.name.replace(/\[.*$/, '') : '';
        // Ambiguous fields default to text, as in the classifier.
        var kind = base && base.charAt(0) !== '_' && kinds[base]
            ? kinds[base]
            : 'literal';
        var text = kind === 'literal';
        kindCache.set(field, text);
        return text;
    };

    var apply = function () {
        var query = input.value.trim().toLowerCase();
        var wantText = textToggle.checked;
        var wantNonText = nonTextToggle.checked;
        // Both or neither checked means no restriction on the field type.
        var typeFilter = wantText !== wantNonText;
        var filtering = !!query || typeFilter;
        var visible = 0;
        fields.forEach(function (field) {
            var meta = field.querySelector('.field-meta');
            // Structural markers (e.g. per-module anchors) carry no meta: hide
            // them whenever a filter is active, and never count them.
            if (!meta) {
                field.classList.toggle('setting-hidden', filtering);
                return;
            }
            var typePass = !typeFilter
                || (wantText ? isTextField(field) : !isTextField(field));
            var hay = (originals.get(meta) || meta.textContent).toLowerCase();
            var match = typePass && (!query || hay.indexOf(query) !== -1);
            field.classList.toggle('setting-hidden', !match);
            highlight(meta, query && match ? query : '');
            if (match) {
                visible++;
            }
        });
        groups.forEach(function (group) {
            var hasVisible =
                group.querySelector('.field:not(.setting-hidden) .field-meta');
            group.classList.toggle('setting-hidden', !hasVisible);
        });
        count.textContent = filtering
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

    var makeToggle = function (className, label) {
        var wrap = document.createElement('label');
        wrap.className = 'setting-filter-toggle';
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.className = className;
        wrap.appendChild(box);
        wrap.appendChild(document.createTextNode(' ' + label));
        return { label: wrap, box: box };
    };

    var controls = document.createElement('div');
    controls.className = 'setting-filter-controls';
    var textCtl = makeToggle('setting-filter-text', i18n.textFields || 'Text fields');
    var nonTextCtl = makeToggle(
        'setting-filter-nontext',
        i18n.nonTextFields || 'Non-text fields'
    );
    var textToggle = textCtl.box;
    var nonTextToggle = nonTextCtl.box;
    var count = document.createElement('span');
    count.className = 'setting-filter-count';
    count.setAttribute('aria-live', 'polite');
    var toggles = document.createElement('div');
    toggles.className = 'setting-filter-toggles';
    toggles.appendChild(textCtl.label);
    toggles.appendChild(nonTextCtl.label);
    controls.appendChild(toggles);
    controls.appendChild(count);

    wrapper.appendChild(input);
    wrapper.appendChild(controls);
    root.insertBefore(wrapper, root.firstChild);

    var timer = null;
    input.addEventListener('input', function () {
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(apply, 120);
    });
    textToggle.addEventListener('change', apply);
    nonTextToggle.addEventListener('change', apply);

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
