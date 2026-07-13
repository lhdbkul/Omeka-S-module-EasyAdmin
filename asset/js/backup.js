'use strict';

(function() {

    document.addEventListener('DOMContentLoaded', function() {
        initDeleteBackup();
        initMasterDetail();
    });

    /**
     * Initialize delete backup sidebar functionality.
     */
    function initDeleteBackup() {
        document.querySelectorAll('.delete-backup').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var url = this.dataset.urlSidebarContent;
                var sidebar = document.getElementById('sidebar');
                var content = sidebar.querySelector('.sidebar-content');

                fetch(url)
                    .then(function(response) {
                        return response.text();
                    })
                    .then(function(html) {
                        content.innerHTML = html;
                        Omeka.openSidebar(jQuery(sidebar));
                    })
                    .catch(function(error) {
                        console.error('Failed to load sidebar content:', error);
                    });
            });
        });
    }

    /**
     * Master-detail: a list of backup tasks on the left, the selected task's
     * options moved into the always-open sidebar with a pinned submit that
     * targets the task's own action (like the check-and-fix page).
     */
    function initMasterDetail() {
        var sidebar = document.getElementById('backup-sidebar');
        if (!sidebar) {
            return;
        }
        var form = document.getElementById('backup-form');
        var help = sidebar.querySelector('.task-help');
        var recap = sidebar.querySelector('.task-recap');
        var recapName = recap.querySelector('.task-recap-name');
        var recapDesc = recap.querySelector('.task-recap-desc');
        var recapConfig = recap.querySelector('.task-recap-options');
        var submit = sidebar.querySelector('.backup-submit');
        var submitDefault = submit.textContent;

        if (window.Omeka && Omeka.reserveSidebarSpace) {
            Omeka.reserveSidebarSpace();
        }

        // Move the config currently shown in the recap back to its task block.
        var stashRecap = function() {
            var current = recapConfig.querySelector('.backup-task-config');
            if (current) {
                var owner = document.querySelector('.backup-task[data-task="' + current.getAttribute('data-task') + '"]');
                if (owner) {
                    owner.appendChild(current);
                }
            }
        };

        var deselect = function() {
            document.querySelectorAll('.backup-task').forEach(function(t) {
                t.classList.remove('selected');
            });
            document.querySelectorAll('.backup-task-head').forEach(function(h) {
                h.setAttribute('aria-pressed', 'false');
            });
        };

        // Return to the default help view.
        var showDefault = function() {
            stashRecap();
            deselect();
            recap.hidden = true;
            help.hidden = false;
            sidebar.classList.remove('has-selection');
            submit.textContent = submitDefault;
        };

        var selectTask = function(head) {
            var block = head.closest('.backup-task');
            // Clicking the open task closes it and shows the default view.
            if (block.classList.contains('selected')) {
                showDefault();
                return;
            }
            stashRecap();
            deselect();

            recapName.textContent = block.querySelector('.backup-task-name').textContent;
            recapDesc.textContent = block.querySelector('.backup-task-desc').textContent;
            recapConfig.appendChild(block.querySelector('.backup-task-config'));

            block.classList.add('selected');
            head.setAttribute('aria-pressed', 'true');
            form.setAttribute('action', block.getAttribute('data-action'));
            submit.textContent = block.getAttribute('data-submit');
            sidebar.classList.add('has-selection');
            help.hidden = true;
            recap.hidden = false;
        };

        document.querySelectorAll('.backup-task-head').forEach(function(head) {
            head.addEventListener('click', function() {
                selectTask(head);
            });
        });
    }

})();
