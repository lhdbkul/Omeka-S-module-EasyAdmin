/**
 * Enable the batch actions of the modules and themes tables.
 *
 * The core function Omeka.manageSelectedActions() only enables the options
 * "update-selected" and "delete-selected", that are the ones of the resource
 * browse pages, so the options of the addons are managed here.
 *
 * All handlers are delegated on the document, because the catalogue is loaded
 * asynchronously and swaps the content of the page (see addon-catalogue.js), so
 * handlers bound directly on the checkboxes would be lost after the swap.
 */

(function () {
    'use strict';

    var updateBatchActions = function () {
        var select = document.querySelector('.batch-actions-select');
        if (!select) {
            return;
        }
        var checked = document.querySelectorAll(
            '.batch-edit tbody input[type="checkbox"]:checked'
        ).length;
        Array.prototype.forEach.call(select.options, function (option) {
            if (option.value !== 'default') {
                option.disabled = !checked;
            }
        });
    };

    document.addEventListener('change', function (event) {
        var target = event.target;
        // The core handlers, bound directly on the elements at load, are lost
        // when the catalogue swaps the page content, so they are re-applied
        // here through delegation. They are idempotent with the core ones on
        // the initial (non-swapped) load.
        if (target.matches('.select-all')) {
            var rows = document.querySelectorAll(
                '.batch-edit td input[type="checkbox"]:not(:disabled)'
            );
            Array.prototype.forEach.call(rows, function (checkbox) {
                checkbox.checked = target.checked;
            });
            updateBatchActions();
            return;
        }
        if (target.matches('.batch-edit td input[type="checkbox"]')) {
            var selectAll = document.querySelector('.select-all:checked');
            if (selectAll) {
                selectAll.checked = false;
            }
            updateBatchActions();
            return;
        }
        if (target.matches('.batch-actions-select')) {
            var actions = document.querySelectorAll('.batch-actions .active');
            Array.prototype.forEach.call(actions, function (node) {
                node.classList.remove('active');
            });
            var selected = document.querySelectorAll('.' + target.value);
            Array.prototype.forEach.call(selected, function (node) {
                node.classList.add('active');
            });
        }
    });
})();
