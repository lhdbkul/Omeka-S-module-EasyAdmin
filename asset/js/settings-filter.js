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

    // Highlight the query in a container, resetting any previous highlight.
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
    var statuses = i18n.status || {};

    // Setting name of a field, from the name of its control.
    var baseKey = function (field) {
        var el = field.querySelector('input[name], select[name], textarea[name]');
        var base = el && el.name ? el.name.replace(/\[.*$/, '') : '';
        return base && base.charAt(0) !== '_' ? base : '';
    };

    // The kind of a field (structural | literal | manual) comes from the type
    // of its form element, classified server-side like the SiteHub module. A
    // field is a text field when its kind is "literal".
    var kindCache = new WeakMap();
    var isTextField = function (field) {
        if (kindCache.has(field)) {
            return kindCache.get(field);
        }
        var base = baseKey(field);
        // Ambiguous fields default to text, as in the classifier.
        var kind = base && kinds[base] ? kinds[base] : 'literal';
        var text = kind === 'literal';
        kindCache.set(field, text);
        return text;
    };

    // Status of a field: default, modified or unknown, computed server-side
    // from the module defaults declared in module.config.php.
    var fieldStatus = function (field) {
        var base = baseKey(field);
        return base && statuses[base] ? statuses[base] : 'unknown';
    };

    // Current textual value of a field's controls, to search within the values.
    var fieldValueText = function (field) {
        var parts = [];
        field.querySelectorAll('input, select, textarea').forEach(function (el) {
            var type = (el.type || '').toLowerCase();
            if (type === 'hidden' || type === 'submit' || type === 'button'
                || type === 'password'
                || el.classList.contains('setting-filter-input')
            ) {
                return;
            }
            if (type === 'checkbox' || type === 'radio') {
                if (el.checked) {
                    parts.push(el.value || '');
                }
            } else if (el.tagName === 'SELECT') {
                Array.prototype.forEach.call(el.selectedOptions || [], function (o) {
                    parts.push(o.textContent);
                });
            } else if (el.value) {
                parts.push(el.value);
            }
        });
        return parts.join(' ').toLowerCase();
    };

    var apply = function () {
        var query = input.value.trim().toLowerCase();
        var wantText = textToggle.checked;
        var wantNonText = nonTextToggle.checked;
        var wantStatus = {
            modified: modifiedToggle.checked,
            'default': defaultToggle.checked,
            unknown: unknownToggle.checked,
        };
        var includeValues = valuesToggle.checked;
        // Each box includes its category; a field must match a checked box in
        // both groups. So unchecking every box of a group hides everything.
        var valueChecked = (wantStatus.modified ? 1 : 0)
            + (wantStatus['default'] ? 1 : 0)
            + (wantStatus.unknown ? 1 : 0);
        var typeChecked = (wantText ? 1 : 0) + (wantNonText ? 1 : 0);
        // The list is restricted (count shown, markers hidden) unless every box
        // is checked and no query is typed.
        var restricted = !!query || valueChecked < 3 || typeChecked < 2;
        var visible = 0;
        fields.forEach(function (field) {
            var meta = field.querySelector('.field-meta');
            // Structural markers (e.g. per-module anchors) carry no meta: hide
            // them whenever the list is restricted, and never count them.
            if (!meta) {
                field.classList.toggle('setting-hidden', restricted);
                return;
            }
            var typePass = isTextField(field) ? wantText : wantNonText;
            var valuePass = !!wantStatus[fieldStatus(field)];
            var hay = meta.textContent.toLowerCase();
            if (includeValues) {
                hay += ' ' + fieldValueText(field);
            }
            var match = typePass && valuePass
                && (!query || hay.indexOf(query) !== -1);
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
        count.textContent = restricted
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
        var text = document.createElement('span');
        text.className = 'setting-filter-label';
        text.textContent = label;
        wrap.appendChild(box);
        wrap.appendChild(text);
        return { label: wrap, box: box, text: text };
    };

    var controls = document.createElement('div');
    controls.className = 'setting-filter-controls';
    var valuesCtl = makeToggle(
        'setting-filter-values',
        i18n.includeValues || 'Include values'
    );
    var defaultCtl = makeToggle('setting-filter-default', i18n['default'] || 'Default');
    var modifiedCtl = makeToggle(
        'setting-filter-modified',
        i18n.modified || 'Modified'
    );
    var unknownCtl = makeToggle('setting-filter-unknown', i18n.unknown || 'Unknown');
    var textCtl = makeToggle('setting-filter-text', i18n.textFields || 'Text fields');
    var nonTextCtl = makeToggle(
        'setting-filter-nontext',
        i18n.nonTextFields || 'Non-text fields'
    );
    var valuesToggle = valuesCtl.box;
    var defaultToggle = defaultCtl.box;
    var modifiedToggle = modifiedCtl.box;
    var unknownToggle = unknownCtl.box;
    var textToggle = textCtl.box;
    var nonTextToggle = nonTextCtl.box;
    // A coloured status marker between the checkbox and the text label of the
    // status filters.
    defaultCtl.text.classList.add('setting-status', 'setting-status-default');
    modifiedCtl.text.classList.add('setting-status', 'setting-status-modified');
    unknownCtl.text.classList.add('setting-status', 'setting-status-unknown');
    var count = document.createElement('span');
    count.className = 'setting-filter-count';
    count.setAttribute('aria-live', 'polite');
    // The checkboxes are grouped in two labelled sections, separated by a
    // divider: the value status and the field type.
    var makeGroup = function (label, ctls) {
        var group = document.createElement('div');
        group.className = 'setting-filter-group';
        var title = document.createElement('span');
        title.className = 'setting-filter-group-label';
        title.textContent = label;
        group.appendChild(title);
        ctls.forEach(function (ctl) {
            group.appendChild(ctl.label);
        });
        return group;
    };
    var separator = document.createElement('span');
    separator.className = 'setting-filter-sep';
    separator.setAttribute('aria-hidden', 'true');

    var toggles = document.createElement('div');
    toggles.className = 'setting-filter-toggles';
    toggles.appendChild(makeGroup(
        i18n.groupValue || 'Value',
        [defaultCtl, modifiedCtl, unknownCtl]
    ));
    toggles.appendChild(separator);
    toggles.appendChild(makeGroup(
        i18n.groupType || 'Type',
        [textCtl, nonTextCtl]
    ));
    controls.appendChild(toggles);
    controls.appendChild(count);

    // All the filters are enabled by default (they only narrow when unchecked
    // or when a query is typed); "include values" sits next to the field.
    [
        valuesToggle, defaultToggle, modifiedToggle, unknownToggle,
        textToggle, nonTextToggle,
    ].forEach(function (box) {
        box.checked = true;
    });

    wrapper.appendChild(input);
    wrapper.appendChild(valuesCtl.label);
    wrapper.appendChild(controls);
    root.insertBefore(wrapper, root.firstChild);

    // A coloured status marker (default / modified / unknown) at the start of
    // each field label.
    fields.forEach(function (field) {
        var meta = field.querySelector('.field-meta');
        if (!meta) {
            return;
        }
        var st = fieldStatus(field);
        var label = i18n[st] || st;
        var dot = document.createElement('span');
        dot.className = 'setting-status-dot setting-status-' + st;
        // Accessible: the colour also has a name, so it is not conveyed by
        // colour alone (a distinct shape is added in CSS for colour blindness).
        dot.title = label;
        dot.setAttribute('role', 'img');
        dot.setAttribute('aria-label', label);
        meta.insertBefore(dot, meta.firstChild);
    });

    var timer = null;
    input.addEventListener('input', function () {
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(apply, 120);
    });
    valuesToggle.addEventListener('change', apply);
    defaultToggle.addEventListener('change', apply);
    modifiedToggle.addEventListener('change', apply);
    unknownToggle.addEventListener('change', apply);
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
