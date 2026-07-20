/**
 * Filter the rows of the modules or themes table by name and description, like
 * the task filter of the check and fix page.
 */

(function () {
    'use strict';

    var filterRows = function (input) {
        var query = input.value.trim().toLowerCase();
        var rows = document.querySelectorAll('.addon-filter-target tbody tr');
        Array.prototype.forEach.call(rows, function (row) {
            var match = !query || row.textContent.toLowerCase().indexOf(query) !== -1;
            row.classList.toggle('addon-hidden-filter', !match);
        });
    };

    var inputs = document.querySelectorAll('.addon-filter-input');
    Array.prototype.forEach.call(inputs, function (input) {
        input.addEventListener('input', function () {
            filterRows(input);
        });
    });
})();
