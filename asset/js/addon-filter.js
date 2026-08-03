/**
 * Filter the rows of the modules or themes table, in the browser, without
 * reload.
 *
 * Two filters are combined: a filter by name and description (search input) and
 * a filter by state (checkboxes, modules only). A row is hidden when it does
 * not match one of the filters. A hidden row is unchecked, so it is not
 * included in a batch process, and the batch actions are refreshed.
 *
 * All handlers are delegated on the document, because the catalogue is loaded
 * asynchronously and swaps the content of the page (see addon-catalogue.js), so
 * handlers bound directly on the inputs would be lost after the swap.
 */

(function () {
    'use strict';

    var matchState = function (row, states) {
        // No checked state: all rows pass the state filter.
        if (!states.length) {
            return true;
        }
        // A row passes when it matches any checked state (union).
        for (var i = 0; i < states.length; i++) {
            var state = states[i];
            if (state === 'update_available') {
                if (row.getAttribute('data-update-available') === '1') {
                    return true;
                }
            } else if (state === 'error') {
                if (row.getAttribute('data-error') === '1') {
                    return true;
                }
            } else if (row.getAttribute('data-state') === state) {
                return true;
            }
        }
        return false;
    };

    var applyFilters = function () {
        var table = document.querySelector('.addon-filter-target');
        if (!table) {
            return;
        }
        var searchInput = document.querySelector('.addon-filter-input');
        var query = searchInput
            ? searchInput.value.trim().toLowerCase()
            : '';
        var states = [];
        document.querySelectorAll('.state-filter-input').forEach(function (input) {
            if (input.checked) {
                states.push(input.value);
            }
        });

        var uncheckedSome = false;
        table.querySelectorAll('tbody tr').forEach(function (row) {
            var matchText = !query
                || row.textContent.toLowerCase().indexOf(query) !== -1;
            var hidden = !matchText || !matchState(row, states);
            row.classList.toggle('addon-hidden-filter', hidden);
            // A hidden row must not stay in the selection of a batch process.
            // The selection checkbox is named "modules[]" or "themes[]": target
            // it by name, because the responsive table (tablesaw) injects a
            // copy of the header "select all" checkbox in each cell.
            if (hidden) {
                var checkbox = row.querySelector(
                    'input[name="modules[]"], input[name="themes[]"]'
                );
                if (checkbox && checkbox.checked) {
                    checkbox.checked = false;
                    uncheckedSome = true;
                }
            }
        });

        // Let the batch actions refresh their enabled state (addon-batch.js
        // listens to the change event on the checkboxes).
        if (uncheckedSome) {
            var anyCheckbox = table.querySelector(
                'tbody td input[type="checkbox"]'
            );
            if (anyCheckbox) {
                anyCheckbox.dispatchEvent(
                    new Event('change', { bubbles: true })
                );
            }
        }
    };

    document.addEventListener('input', function (event) {
        if (event.target.matches('.addon-filter-input')) {
            applyFilters();
        }
    });
    document.addEventListener('change', function (event) {
        if (event.target.matches('.state-filter-input')) {
            applyFilters();
        }
    });

    // Apply once on load, and after the catalogue swap, in case a state is
    // pre-checked (in particular after an action that redirects to a state).
    applyFilters();
    document.addEventListener('easy-admin:catalogue-loaded', applyFilters);
})();

/**
 * Quick filter of the catalogue list in the install sidebar (modules or
 * themes). An add-on whose name does not match the query is hidden.
 */
(function () {
    'use strict';

    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!input.matches('.addon-install-filter')) {
            return;
        }
        var sidebar = input.closest('.sidebar-content');
        if (!sidebar) {
            return;
        }
        var query = input.value.trim().toLowerCase();
        sidebar.querySelectorAll('.addon-check-item').forEach(function (item) {
            var nameNode = item.querySelector('.addon-check-name');
            var name = nameNode ? nameNode.textContent.toLowerCase() : '';
            item.style.display = !query || name.indexOf(query) !== -1
                ? ''
                : 'none';
        });
    });
})();
