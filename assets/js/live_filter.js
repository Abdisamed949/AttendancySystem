// Restores the exact scroll position from right before the last live-filter
// auto-submit, so reloading the page to apply a filter never visibly jumps
// the user back to the top. Runs as top-level code (not inside
// DOMContentLoaded) because this file's <script> tag sits at the bottom of
// <body>, after all the page's real content has already been parsed — by
// the time this line runs, the document is already tall enough to actually
// scroll to the saved position, which would not be true yet at
// DOMContentLoaded-time on some pages.
//
// includes/sidebar.php (the very first thing rendered after <body> opens)
// already hid the whole page the instant it saw this same pending key, so
// nothing has been visibly painted yet at the wrong (top) scroll position —
// this only has to set the real position and reveal the page again, never
// visibly correct a wrong one.
(function admasRestoreFilterScroll() {
    const scrollKey = 'admasFilterScroll:' + window.location.pathname;
    const saved = sessionStorage.getItem(scrollKey);
    if (saved !== null) {
        sessionStorage.removeItem(scrollKey);
        const y = parseInt(saved, 10);
        if (!Number.isNaN(y)) {
            window.scrollTo(0, y);
        }
    }
    document.documentElement.style.visibility = '';
})();

// Makes a GET filter form "live": every <select> inside it re-submits the
// page the instant its value changes (no more needing to click a separate
// Search button after picking a dropdown), and any input carrying
// [data-live-search] re-submits a short debounce after the user stops
// typing. The existing submit button and native Enter-to-submit both keep
// working untouched — this only adds automatic submission on top of them.
//
// Any select-cascade JS already wired via inline onchange="..." attributes
// (e.g. rebuilding a Department/Semester list when Faculty changes) still
// runs first, since it was registered at parse time — this listener is
// attached after, so it only fires once the cascade has already updated
// the field's options/value for the change actually made by the user.
function admasInitLiveFilter(formSelector, options) {
    const form = typeof formSelector === 'string' ? document.querySelector(formSelector) : formSelector;
    if (!form) {
        return;
    }

    const debounceMs = (options && options.debounceMs) || 500;
    const scrollKey = 'admasFilterScroll:' + window.location.pathname;

    const submitLive = () => {
        sessionStorage.setItem(scrollKey, String(window.scrollY));
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    };

    form.querySelectorAll('select').forEach((select) => {
        select.addEventListener('change', submitLive);
    });

    let debounceTimer = null;
    form.querySelectorAll('[data-live-search]').forEach((input) => {
        input.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(submitLive, debounceMs);
        });
    });
}
