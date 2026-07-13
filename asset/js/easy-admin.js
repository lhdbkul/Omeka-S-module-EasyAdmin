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
            + '</div><div class="task-actions" data-subject="' + key + '"></div></div>');
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

    var $sidebar = $('#cf-sidebar');
    var $help = $sidebar.find('.task-help');
    var $recap = $sidebar.find('.task-recap');
    var $recapActions = $sidebar.find('.task-recap-actions');
    var $recapOptions = $sidebar.find('.task-recap-options');
    // Hidden home for the action groups and option panels when not displayed in
    // the recap, so they remain findable globally (by value/subject) and the
    // recap can be rebuilt at each selection without losing nodes.
    var $stash = $('<div id="cf-stash" hidden></div>').appendTo($form);

    var showProcessTask = function (clicked) {
        var current = clicked
            ? $(clicked)
            : $('input.fieldset-process:checked').first();
        var value = current.val();

        // Stash everything currently in the recap, then rebuild it: nodes are
        // never lost and stay findable by value/subject wherever they sit.
        $stash.append($recapActions.children()).append($recapOptions.children());
        $('input.fieldset-process').prop('checked', false);
        $('.check-and-fix .task-subject').removeClass('selected');
        optionFieldsets().hide();
        $('.task-actions').hide();
        $entityField.hide();

        if (!value) {
            $recap.prop('hidden', true);
            $help.prop('hidden', false);
            return;
        }
        current.prop('checked', true);

        var meta = valueIndex[value];
        if (meta && subjects[meta.subject]) {
            $recap.find('.task-recap-name').text(subjects[meta.subject].name);
            $recap.find('.task-recap-desc').text(subjects[meta.subject].description);
            $('.task-subject[data-subject="' + meta.subject + '"]').addClass('selected');
            // The action group lives in the block, the stash or the recap: find
            // it by subject wherever it is, move it back into the recap.
            $recapActions.append($('.task-actions[data-subject="' + meta.subject + '"]').show());
        } else {
            // Fallback task (added by a module): use the radio label.
            $recap.find('.task-recap-name').text(current.closest('label').text().trim());
            $recap.find('.task-recap-desc').text('');
        }

        // Option panel(s) carry the value as a css class; find anywhere in
        // form.
        var $options = $form.find('fieldset.' + value);
        if ($options.length) {
            $recapOptions.append($options.show());
        }
        if (entityTypesTasks.includes(value)) {
            $recapOptions.append($entityField.show());
        }

        $help.prop('hidden', true);
        $recap.prop('hidden', false);
    };

    // Click a subject header: select it, preselecting its first action. The
    // first action radio is found by value (it may sit in the recap or stash).
    var firstActionValue = function (key) {
        var actions = (subjects[key] && subjects[key].actions) || {};
        return Object.keys(actions)[0];
    };
    var selectSubject = function () {
        var key = $(this).closest('.task-subject').attr('data-subject');
        var value = firstActionValue(key);
        // The radio may be in the block, the recap or the stash: find by value.
        var $radio = $form.find('input.fieldset-process[value="' + value + '"]').first();
        if ($radio.length) {
            showProcessTask($radio[0]);
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
        $filter.insertBefore('.check-and-fix fieldset.field-container:first');
        $filter.find('input').on('input', filterTasks);
    };

    /* Init */

    buildSubjects();
    optionFieldsets().hide();
    $('.check-and-fix .task-actions').hide();
    addFilter();

    // Reserve the space for the always-open sidebar (shrinks #content).
    if (window.Omeka && Omeka.reserveSidebarSpace) {
        Omeka.reserveSidebarSpace();
    }

    $('.check-and-fix').on('click', '.task-subject-head', selectSubject);
    $('.check-and-fix .fieldset-process').on('change', function () {
        showProcessTask(this);
    });
    $('input[name="toggle_tasks_with_warning"]').on('click', hideTasksWarning);

    hideTasksWarning();
    showProcessTask();

});
