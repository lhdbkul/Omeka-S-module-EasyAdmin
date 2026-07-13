'use strict';

$(document).ready(function () {

    if (!$('body').hasClass('check-and-fix')) {
        return;
    }

    var $form = $('#check-and-fix-form');
    var subjects = $form.data('subjects') || {};
    var entityTypesTasks = ($form.data('entity-types-tasks') || '').split(',');

    // value -> {subject, verb} index from the subjects metadata (core tasks).
    var valueIndex = {};
    Object.keys(subjects).forEach(function (key) {
        var actions = subjects[key].actions || {};
        Object.keys(actions).forEach(function (value) {
            valueIndex[value] = {subject: key, verb: actions[value]};
        });
    });

    // Derive a subject from a process value for tasks not in the metadata
    // (added by other modules through the "form.add_elements" event).
    var subjectOf = function (value) {
        return (value || '').replace(
            /_(check_full|check|fix_all|fix_db|fix|clean|move|recreate|index|save)$/, ''
        );
    };

    // Replace the visible text of a radio label while keeping its input.
    var relabel = function ($label, text) {
        $label.contents().filter(function () {
            return this.nodeType === 3;
        }).remove();
        $label.append(document.createTextNode(' ' + text));
    };

    // Option panels carry their process value as a css class, wherever they
    // are.
    var optionFieldsets = function () {
        return $('.check-and-fix fieldset.field-container').find('fieldset');
    };

    var $entityField = $('#files_checkfix-entity_types_field');

    /**
     * Build subject blocks (known tasks) and legacy groups (others), per
     * section, so tasks added by modules still appear.
     */
    var buildSubjects = function () {
        $('.check-and-fix fieldset.field-container').each(function () {
            var entries = $(this).find('input.fieldset-process').map(function () {
                return {value: $(this).val(), label: $(this).closest('label')[0]};
            }).get();

            var i = 0;
            while (i < entries.length) {
                var meta = valueIndex[entries[i].value];
                if (meta) {
                    var key = meta.subject;
                    var members = [];
                    while (i < entries.length
                        && valueIndex[entries[i].value]
                        && valueIndex[entries[i].value].subject === key
                    ) {
                        members.push(entries[i]);
                        i++;
                    }
                    buildSubjectBlock(key, members);
                } else {
                    var subj = subjectOf(entries[i].value);
                    var group = [];
                    while (i < entries.length
                        && !valueIndex[entries[i].value]
                        && subjectOf(entries[i].value) === subj
                    ) {
                        group.push(entries[i].label);
                        i++;
                    }
                    $(group).wrapAll('<div class="task-group"></div>');
                }
            }
        });
    };

    var buildSubjectBlock = function (key, members) {
        var meta = subjects[key];
        var $block = $('<div class="task-subject" data-subject="' + key + '">'
            + '<div class="task-subject-head">'
            + '<span class="task-subject-name"></span> '
            + '<span class="task-subject-desc"></span>'
            + '</div><div class="task-actions"></div></div>');
        $block.find('.task-subject-name').text(meta.name);
        $block.find('.task-subject-desc').text(meta.description);
        var $actions = $block.find('.task-actions');
        $block.insertBefore(members[0].label);
        members.forEach(function (e) {
            var $label = $(e.label);
            relabel($label, (meta.actions && meta.actions[e.value]) || e.value);
            $actions.append($label);
        });
    };

    var hideTasksWarning = function () {
        var warn = ($form.data('tasks-warning') || '').split(',');
        $('.check-and-fix .fieldset-process')
            .filter(function () {
                return warn.includes($(this).val());
            })
            .each(function () {
                $(this).prop('disabled', !$(this).prop('disabled'));
                $(this).closest('label').css('opacity', $(this).prop('disabled') ? '0.5' : '1');
                $(this).closest('label').toggle();
            });
    };

    var showProcessTask = function (clicked) {
        // Sections are separate radio groups, so keep a single active task.
        var current = clicked
            ? $(clicked)
            : $('input.fieldset-process:checked').first();
        var value = current.val();

        $('input.fieldset-process').prop('checked', false);
        optionFieldsets().hide();
        $entityField.hide();
        $('.check-and-fix .task-subject').removeClass('selected');
        $('.check-and-fix .task-actions').hide();

        if (!value) {
            return;
        }
        current.prop('checked', true);

        var $label = current.closest('label').first();
        var $block = $label.closest('.task-subject');
        $block.addClass('selected');
        $block.find('.task-actions').show();

        // Move the option panel and the entity_types field under the action.
        var $anchor = $label;
        var $options = $('.check-and-fix fieldset.field-container').find('fieldset.' + value);
        if ($options.length) {
            $options.insertAfter($anchor).show();
            $anchor = $options.last();
        }
        if (entityTypesTasks.includes(value)) {
            $entityField.insertAfter($anchor).show();
        }
    };

    // Click a subject header: select it, preselecting its first action.
    var selectSubject = function () {
        var $first = $(this).closest('.task-subject').find('input.fieldset-process').first();
        if ($first.length) {
            showProcessTask($first[0]);
        }
    };

    var filterTasks = function () {
        var query = $(this).val().trim().toLowerCase();
        $('.check-and-fix .task-subject, .check-and-fix .task-group').each(function () {
            var match = !query || $(this).text().toLowerCase().indexOf(query) !== -1;
            $(this).toggleClass('task-hidden-filter', !match);
        });
    };

    var addFilter = function () {
        var placeholder = $form.data('filter-placeholder') || 'Filter tasks…';
        var $filter = $('<div class="task-filter"><input type="search" class="task-filter-input"></div>');
        $filter.find('input').attr('placeholder', placeholder).attr('aria-label', placeholder);
        $filter.insertAfter('#page-actions');
        $filter.find('input').on('input', filterTasks);
    };

    /* Init */

    buildSubjects();
    optionFieldsets().hide();
    $('.check-and-fix .task-actions').hide();
    addFilter();

    $('.check-and-fix').on('click', '.task-subject-head', selectSubject);
    $('.check-and-fix .fieldset-process').on('change', function () {
        showProcessTask(this);
    });
    $('input[name="toggle_tasks_with_warning"]').on('click', hideTasksWarning);

    hideTasksWarning();
    showProcessTask();

});
