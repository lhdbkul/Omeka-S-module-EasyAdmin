/**
 * Enable the batch actions of the modules and themes tables.
 *
 * The core function Omeka.manageSelectedActions() only enables the options
 * "update-selected" and "delete-selected", that are the ones of the resource
 * browse pages, so the options of the addons are managed here.
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

    // The core handlers are bound directly on the inputs, so this delegated one
    // runs after them, in particular after "select all" checked the rows.
    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target.matches('.select-all')
            || target.matches('.batch-edit input[type="checkbox"]')
        ) {
            updateBatchActions();
        }
    });

    updateBatchActions();
})();
