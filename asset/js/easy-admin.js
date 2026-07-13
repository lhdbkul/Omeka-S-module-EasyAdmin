'use strict';

$(document).ready(function() {

    const hideTasksWarning = function () {
        const tasksWarning = $('#check-and-fix-form').data('tasks-warning').split(',');
        $('.check-and-fix .fieldset-process')
            .filter((index, el) =>  tasksWarning.includes($(el).val()))
            .each(function () {
                $(this).prop('disabled', !$(this).prop('disabled'));
                $(this).closest('label').css('opacity', $(this).prop('disabled') ? '0.5' : '1');
                $(this).closest('label').toggle();
            });
    };

    const showProcessTask = function () {
        const currentTask = $('input[type=radio].fieldset-process:checked');
        const currentTaskVal = currentTask.val();
        // Get the current value and reset and hide all of them.
        const radioTasks = $('input[type=radio].fieldset-process');
        radioTasks.prop('checked', false);
        $('fieldset.field-container > fieldset').hide();
        // Show the selected container if any.
        if (currentTaskVal && currentTaskVal !== '') {
            currentTask.prop('checked', true);
            $('fieldset.field-container > fieldset.' + currentTaskVal).show();
        }
        // The "entity_types" option is a plain field (not a sub-fieldset), so
        // toggle it explicitly: shown only for the tasks that support it.
        const entityTypesTasks = ($('#check-and-fix-form').data('entity-types-tasks') || '').split(',');
        $('#files_checkfix-entity_types_field').toggle(
            !!currentTaskVal && entityTypesTasks.includes(currentTaskVal)
        );
    }

    $('.check-and-fix fieldset.field-container > fieldset').hide();

    $('.check-and-fix .fieldset-process').on('click', showProcessTask);

    $('input[name="toggle_tasks_with_warning"]').on('click', hideTasksWarning);

    /* Init */

    hideTasksWarning();

    showProcessTask();

});
