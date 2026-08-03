/**
 * Group navigation (table of contents) of the global settings page.
 *
 * The settings are rendered in element-group fieldsets. This adds a row of
 * chips per group that scroll to it and highlight current one on scroll.
 */

(function () {
    'use strict';

    // Same scope as settings-filter.js: the whole form on the global settings
    // page, only the settings section on the site edit page.
    var root = document.body.classList.contains('settings')
        ? document.querySelector('#content form')
        : document.getElementById('site-settings');
    if (!root) {
        return;
    }

    var groups = Array.prototype.slice
        .call(root.querySelectorAll('fieldset'))
        .filter(function (fieldset) {
            return fieldset.querySelector('.fieldsets-heading');
        });
    // A table of contents is pointless for a single group.
    if (groups.length < 2) {
        return;
    }

    var label = (window.EasyAdmin
        && window.EasyAdmin.settingsFilter
        && window.EasyAdmin.settingsFilter.nav) || 'Sections';
    // The chips are wrapped in a details/summary, collapsed by default, so the
    // list can be shown on demand.
    var nav = document.createElement('details');
    nav.className = 'setting-nav';
    var summary = document.createElement('summary');
    summary.className = 'setting-nav-summary';
    summary.textContent = label;
    nav.appendChild(summary);
    var chipList = document.createElement('div');
    chipList.className = 'setting-nav-chips';
    nav.appendChild(chipList);

    var chips = new Map();
    groups.forEach(function (group, index) {
        if (!group.id) {
            group.id = 'setting-group-' + index;
        }
        // Offset the scroll target below the sticky bar (see CSS).
        group.classList.add('setting-nav-target');
        var heading = group.querySelector('.fieldsets-heading');
        var chip = document.createElement('a');
        chip.className = 'setting-nav-chip';
        chip.href = '#' + group.id;
        chip.textContent = heading.textContent.trim();
        chip.addEventListener('click', function (event) {
            event.preventDefault();
            group.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        chipList.appendChild(chip);
        chips.set(group, chip);

        // Mirror the filter visibility of the group onto its chip.
        var observer = new MutationObserver(function () {
            chip.classList.toggle(
                'setting-nav-hidden',
                group.classList.contains('setting-hidden')
            );
        });
        observer.observe(group, {
            attributes: true,
            attributeFilter: ['class'],
        });
    });

    // Integrate the chips into the sticky filter bar when present, else create
    // a minimal sticky bar of its own.
    var bar = root.querySelector('.setting-filter');
    if (bar) {
        bar.appendChild(nav);
    } else {
        bar = document.createElement('div');
        bar.className = 'setting-filter setting-filter-navonly';
        bar.appendChild(nav);
        root.insertBefore(bar, root.firstChild);
    }

    // Scroll spy: highlight the chip of the group currently near the top.
    var setActive = function (group) {
        chips.forEach(function (chip, key) {
            chip.classList.toggle('setting-nav-active', key === group);
        });
    };
    var spy = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                setActive(entry.target);
            }
        });
    }, {
        // Offset for the sticky bar, so a group is active once its heading
        // reaches just below the bar.
        rootMargin: '-96px 0px -70% 0px',
    });
    groups.forEach(function (group) {
        spy.observe(group);
    });
})();
