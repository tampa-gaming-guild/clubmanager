/**
 * Light / Dark / System theme switcher.
 * Pairs with partials/theme_init.php (which sets data-theme before first
 * paint to avoid a flash) and partials/theme_toggle.php (the icon button
 * group UI).
 */
(function () {
    var STORAGE_KEY = 'tgg-theme';
    var darkMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    var systemListenerAttached = false;

    function getPreference() {
        try {
            return localStorage.getItem(STORAGE_KEY) || 'light';
        } catch (e) {
            return 'light';
        }
    }

    function resolveTheme(pref) {
        return pref === 'system' ? (darkMediaQuery.matches ? 'dark' : 'light') : pref;
    }

    function applyResolvedTheme(resolved) {
        if (resolved === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        updateFlatpickrTheme(resolved);
    }

    function onSystemChange() {
        applyResolvedTheme(resolveTheme('system'));
    }

    function syncSystemListener(pref) {
        if (pref === 'system' && !systemListenerAttached) {
            darkMediaQuery.addEventListener('change', onSystemChange);
            systemListenerAttached = true;
        } else if (pref !== 'system' && systemListenerAttached) {
            darkMediaQuery.removeEventListener('change', onSystemChange);
            systemListenerAttached = false;
        }
    }

    function updateActiveButton(group, pref) {
        if (!group) return;
        group.querySelectorAll('.theme-toggle-btn').forEach(function (btn) {
            var isActive = btn.dataset.themeChoice === pref;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-pressed', String(isActive));
        });
    }

    function setPreference(pref) {
        try {
            localStorage.setItem(STORAGE_KEY, pref);
        } catch (e) {}
        syncSystemListener(pref);
        applyResolvedTheme(resolveTheme(pref));
        updateActiveButton(document.getElementById('themeToggleGroup'), pref);
    }

    // Swap flatpickr's stylesheet to match the resolved theme, on pages that
    // load flatpickr and mark their light <link> with this id (see
    // admin/checkins.php).
    function updateFlatpickrTheme(resolved) {
        var lightLink = document.getElementById('flatpickr-css');
        if (!lightLink) return;
        var darkHref = lightLink.getAttribute('data-dark-href');
        if (!darkHref) return;
        var darkLink = document.getElementById('flatpickr-theme-link');
        if (resolved === 'dark') {
            if (!darkLink) {
                darkLink = document.createElement('link');
                darkLink.id = 'flatpickr-theme-link';
                darkLink.rel = 'stylesheet';
                darkLink.href = darkHref;
                lightLink.insertAdjacentElement('afterend', darkLink);
            }
        } else if (darkLink) {
            darkLink.remove();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var pref = getPreference();
        syncSystemListener(pref);
        applyResolvedTheme(resolveTheme(pref));

        var group = document.getElementById('themeToggleGroup');
        if (!group) return;
        updateActiveButton(group, pref);
        group.querySelectorAll('.theme-toggle-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setPreference(btn.dataset.themeChoice);
            });
        });
    });
})();
