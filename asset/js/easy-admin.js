'use strict';

$(document).ready(function () {

    /**
     * Derive the "subject" of a task from its process value, by stripping the
     * trailing action verb. So files_hash_check and files_hash_fix share the
     * subject files_hash and can be grouped visually (check/fix pairing).
     */
    const subjectOf = function (value) {
        return (value || '').replace(
            /_(check_full|check|fix_all|fix_db|fix|clean|move|recreate|index|save)$/,
            ''
        );
    };

    /**
     * (a) Pairing: wrap consecutive radios sharing a subject in a group box, so
     * check/fix (and other actions of the same subject) read as one unit.
     */
    const groupTasks = function () {
        // The process radios render as a sequence of contiguous <label> (the
        // "Tasks" label is removed, so there is no .inputs wrapper). Group them
        // per section by subject.
        $('.check-and-fix fieldset.field-container').each(function () {
            const labels = $(this).find('input.fieldset-process')
                .map(function () {
                    return $(this).closest('label')[0];
                });
            let i = 0;
            while (i < labels.length) {
                const subject = subjectOf($(labels[i]).find('input.fieldset-process').val());
                let j = i + 1;
                while (j < labels.length
                    && subjectOf($(labels[j]).find('input.fieldset-process').val()) === subject
                ) {
                    j++;
                }
                $(labels.slice(i, j)).wrapAll('<div class="task-group"></div>');
                i = j;
            }
        });
    };

    /**
     * Hide the dangerous tasks unless the dedicated checkbox is ticked.
     */
    const hideTasksWarning = function () {
        const tasksWarning = ($('#check-and-fix-form').data('tasks-warning') || '').split(',');
        $('.check-and-fix .fieldset-process')
            .filter((index, el) => tasksWarning.includes($(el).val()))
            .each(function () {
                $(this).prop('disabled', !$(this).prop('disabled'));
                $(this).closest('label').css('opacity', $(this).prop('disabled') ? '0.5' : '1');
                $(this).closest('label').toggle();
            });
    };

    /**
     * All option panels (sub-fieldsets), wherever they currently are (they get
     * moved around by showProcessTask).
     */
    const optionFieldsets = function () {
        return $('.check-and-fix fieldset.field-container').find('fieldset');
    };

    /**
     * (b) Show the selected task and move its option panel (and the shared
     * entity_types field, when relevant) right under the selected radio,
     * instead of leaving them at the bottom of the section.
     */
    const showProcessTask = function (clicked) {
        // Sections are separate radio groups, so several radios may be checked
        // at once. Keep a single task: the clicked one, else the first checked.
        // Using a single element as anchor below is required, otherwise
        // insertAfter() clones the moved panel for each anchor (duplication).
        const currentTask = clicked
            ? $(clicked)
            : $('input[type=radio].fieldset-process:checked').first();
        const currentTaskVal = currentTask.val();

        // Only one task at a time across all sections.
        $('input[type=radio].fieldset-process').prop('checked', false);
        optionFieldsets().hide();

        const entityTypesTasks = ($('#check-and-fix-form').data('entity-types-tasks') || '').split(',');
        const $entityField = $('#files_checkfix-entity_types_field');
        $entityField.hide();

        if (!currentTaskVal) {
            return;
        }

        currentTask.prop('checked', true);
        const $label = currentTask.closest('label').first();

        // Option panel(s) of this task carry its process value as a css class.
        const $options = $('.check-and-fix fieldset.field-container')
            .find('fieldset.' + currentTaskVal);
        let $anchor = $label;
        if ($options.length) {
            $options.insertAfter($anchor).show();
            $anchor = $options.last();
        }
        if (entityTypesTasks.includes(currentTaskVal)) {
            $entityField.insertAfter($anchor).show();
        }
    };

    /**
     * (c) Live filter: hide the task groups that do not match the query.
     */
    const filterTasks = function () {
        const query = $(this).val().trim().toLowerCase();
        // Filter by group when grouping applied, else fall back to single radio
        // labels, so the feature works whatever the exact radio markup.
        let units = $('.check-and-fix .task-group');
        if (!units.length) {
            units = $('.check-and-fix .fieldset-process').map(function () {
                return $(this).closest('label')[0];
            });
        }
        units.each(function () {
            const match = !query || $(this).text().toLowerCase().indexOf(query) !== -1;
            $(this).toggleClass('task-hidden-filter', !match);
        });
    };

    const addFilter = function () {
        const placeholder = $('#check-and-fix-form').data('filter-placeholder') || 'Filter tasks…';
        const $filter = $('<div class="task-filter">'
            + '<input type="search" class="task-filter-input" placeholder="'
            + placeholder + '" aria-label="' + placeholder + '"></div>');
        $filter.insertAfter('#page-actions');
        $filter.find('input').on('input', filterTasks);
    };

    /* Init */

    if (!$('body').hasClass('check-and-fix')) {
        return;
    }

    groupTasks();
    $('.check-and-fix fieldset.field-container').find('fieldset').hide();
    addFilter();

    $('.check-and-fix .fieldset-process').on('click', function () {
        showProcessTask(this);
    });
    $('input[name="toggle_tasks_with_warning"]').on('click', hideTasksWarning);

    hideTasksWarning();
    showProcessTask();

});
