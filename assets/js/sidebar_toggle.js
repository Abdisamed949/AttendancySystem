/**
 * Mobile sidebar open/close — below the 991.98px breakpoint the sidebar is
 * hidden off-screen by CSS (see app.css) and this hamburger button/backdrop
 * are the only way to bring it back. Toggles a class on <body> that the CSS
 * media query reacts to; no effect above that breakpoint since the sidebar
 * never transforms there.
 */
(function () {
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const backdrop = document.getElementById('sidebarBackdrop');

    function closeSidebar() {
        document.body.classList.remove('sidebar-open');
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-open');
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeSidebar();
        }
    });
})();
