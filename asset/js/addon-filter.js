/**
 * Filter the rows of the modules or themes table, in the browser, without
 * reload.
 *
 * Two filters are combined: a filter by name and description (search input) and
 * a filter by state (checkboxes, modules only). A row is hidden when it does
 * not match one of the filters. A hidden row is unchecked, so it is not
 * included in a batch process, and the batch actions are refreshed.
 */

(function () {
    'use strict';

    var table = document.querySelector('.addon-filter-target');
    if (!table) {
        return;
    }

    var searchInput = document.querySelector('.addon-filter-input');
    var stateInputs = document.querySelectorAll('.state-filter-input');

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
        var query = searchInput
            ? searchInput.value.trim().toLowerCase()
            : '';
        var states = [];
        Array.prototype.forEach.call(stateInputs, function (input) {
            if (input.checked) {
                states.push(input.value);
            }
        });

        var uncheckedSome = false;
        var rows = table.querySelectorAll('tbody tr');
        Array.prototype.forEach.call(rows, function (row) {
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

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }
    Array.prototype.forEach.call(stateInputs, function (input) {
        input.addEventListener('change', applyFilters);
    });

    // Apply once on load, in case a state is pre-checked, in particular after an
    // action that redirects to a given state).
    applyFilters();
})();
