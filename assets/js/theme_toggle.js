/**
 * Dark/Night mode toggle — persists per-browser via localStorage. The
 * actual theme is applied as early as possible by a small inline script
 * in includes/sidebar.php (before this file loads) to avoid a flash of
 * the wrong theme; this file just wires up the button and keeps the
 * icon/localStorage in sync with clicks.
 */
(function () {
    const STORAGE_KEY = 'admas-theme';
    const btn = document.getElementById('themeToggleBtn');
    const icon = document.getElementById('themeToggleIcon');

    function applyTheme(theme) {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            if (icon) {
                icon.className = 'bi bi-sun';
            }
        } else {
            document.documentElement.removeAttribute('data-theme');
            if (icon) {
                icon.className = 'bi bi-moon-stars';
            }
        }
    }

    applyTheme(localStorage.getItem(STORAGE_KEY) === 'dark' ? 'dark' : 'light');

    if (btn) {
        btn.addEventListener('click', () => {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const next = isDark ? 'light' : 'dark';
            localStorage.setItem(STORAGE_KEY, next);
            applyTheme(next);
        });
    }
})();
