'use strict';

/**
 * Asynchronous load of the modules/themes catalogue.
 *
 * On the first load (empty cache), the page is rendered immediately without the
 * catalogue, then this script fetches it in the background and swaps the page
 * content, so the slow external http requests (omeka.org, github) do not block
 * the initial render. The trigger element carries the request url and is
 * present only when the catalogue is pending.
 */
(function () {
    function init() {
        var trigger = document.getElementById('catalogue-loading');
        if (!trigger) {
            return;
        }
        var url = trigger.getAttribute('data-catalogue-url');
        if (!url) {
            return;
        }
        fetch(url, {
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.ok ? response.text() : Promise.reject(response.status);
            })
            .then(function (html) {
                var content = document.getElementById('content');
                if (!content) {
                    return;
                }
                content.innerHTML = html;
                // innerHTML does not execute scripts: re-inject them so any
                // behaviour bound inline (state filter, selection filter) is
                // rebound.
                content.querySelectorAll('script').forEach(function (old) {
                    var script = document.createElement('script');
                    if (old.src) {
                        script.src = old.src;
                    } else {
                        script.textContent = old.textContent;
                    }
                    old.parentNode.replaceChild(script, old);
                });
            })
            .catch(function () {
                trigger.className = 'messages warning';
                // Connection problem: flag the refresh button with a warning
                // triangle and re-enable it so the user can retry.
                var button = document.querySelector('.refresh-form button[type="submit"]');
                if (!button) {
                    return;
                }
                button.removeAttribute('disabled');
                var title = trigger.getAttribute('data-error-title');
                if (title) {
                    button.title = title;
                }
                var icon = button.querySelector('.fa-spin');
                if (icon) {
                    icon.className = 'fas fa-exclamation-triangle';
                } else {
                    icon = document.createElement('span');
                    icon.className = 'fas fa-exclamation-triangle';
                    icon.style.marginRight = '.4em';
                    icon.setAttribute('aria-hidden', 'true');
                    button.insertBefore(icon, button.firstChild);
                }
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
