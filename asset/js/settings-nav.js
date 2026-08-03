/**
 * Group navigation (table of contents) of the global settings page.
 *
 * The settings are rendered in element-group fieldsets. This adds a row of
 * chips per group that scroll to it and highlight current one on scroll.
 */

(function () {
    'use strict';

    if (!document.body.classList.contains('settings')) {
        return;
    }

    var form = document.querySelector('#content form');
    if (!form) {
        return;
    }

    var groups = Array.prototype.slice
        .call(form.querySelectorAll('fieldset'))
        .filter(function (fieldset) {
            return fieldset.querySelector('.fieldsets-heading');
        });
    // A table of contents is pointless for a single group.
    if (groups.length < 2) {
        return;
    }

    var nav = document.createElement('nav');
    nav.className = 'setting-nav';
    nav.setAttribute('aria-label', (window.EasyAdmin
        && window.EasyAdmin.settingsFilter
        && window.EasyAdmin.settingsFilter.nav) || 'Settings sections');

    var chips = new Map();
    groups.forEach(function (group, index) {
        if (!group.id) {
            group.id = 'setting-group-' + index;
        }
        var heading = group.querySelector('.fieldsets-heading');
        var chip = document.createElement('a');
        chip.className = 'setting-nav-chip';
        chip.href = '#' + group.id;
        chip.textContent = heading.textContent.trim();
        chip.addEventListener('click', function (event) {
            event.preventDefault();
            group.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        nav.appendChild(chip);
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
    var bar = form.querySelector('.setting-filter');
    if (bar) {
        bar.appendChild(nav);
    } else {
        bar = document.createElement('div');
        bar.className = 'setting-filter setting-filter-navonly';
        bar.appendChild(nav);
        form.insertBefore(bar, form.firstChild);
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
