'use strict';

/**
 * Add a button on the settings pages to enable the settings enhancements
 * (live filter and section navigation), shown only when they are disabled.
 *
 * @see \EasyAdmin\Module::appendSettingsEnhancementsButton()
 */
(function () {
    function init() {
        var cfg = window.EasyAdmin && window.EasyAdmin.enableEnhancements;
        if (!cfg || !cfg.url) {
            return;
        }
        var content = document.getElementById('content');
        var heading = document.querySelector('#content .fieldsets-heading');
        if (!content || !heading) {
            return;
        }
        if (getComputedStyle(content).position === 'static') {
            content.style.position = 'relative';
        }
        var form = document.createElement('form');
        form.method = 'post';
        form.action = cfg.url;
        form.className = 'easyadmin-enable-enhancements';
        form.style.cssText = 'position:absolute;right:.75rem;margin:0;';

        var csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf';
        csrf.value = cfg.csrf || '';
        form.appendChild(csrf);

        var button = document.createElement('button');
        button.type = 'submit';
        button.className = 'button';
        button.style.fontWeight = 'normal';
        button.textContent = cfg.label || 'Enable filters';
        if (cfg.title) {
            button.title = cfg.title;
        }
        form.appendChild(button);

        content.appendChild(form);
        var cRect = content.getBoundingClientRect();
        var hRect = heading.getBoundingClientRect();
        form.style.top = (hRect.top - cRect.top + content.scrollTop) + 'px';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
