/**
 * Generic "select rows (or none = everyone) -> Export" wiring for a
 * non-destructive bulk export button — sibling to bulk_delete.js's
 * admasInitBulkDelete(), but with no confirm dialog (exporting isn't
 * destructive) and a button label that reflects "export everyone" vs
 * "export just what's checked" instead of hiding entirely at zero
 * selected.
 */
function admasInitBulkExport(opts) {
    const {
        checkboxSelector,
        selectAllSelector,
        formSelector,
        hiddenContainerSelector,
        hiddenInputName,
        labelSelector,
        allLabel,
        selectedLabelPrefix,
    } = opts;

    const getCheckboxes = () => Array.from(document.querySelectorAll(checkboxSelector));
    const selectAll = selectAllSelector ? document.querySelector(selectAllSelector) : null;
    const form = document.querySelector(formSelector);
    const hiddenContainer = document.querySelector(hiddenContainerSelector);
    const label = labelSelector ? document.querySelector(labelSelector) : null;

    function updateLabel() {
        const checked = getCheckboxes().filter((cb) => cb.checked);
        const n = checked.length;

        if (label) {
            label.textContent = n === 0 ? allLabel : `${selectedLabelPrefix} (${n})`;
        }

        if (selectAll) {
            const all = getCheckboxes();
            selectAll.checked = n > 0 && n === all.length;
            selectAll.indeterminate = n > 0 && n < all.length;
        }
    }

    getCheckboxes().forEach((cb) => cb.addEventListener('change', updateLabel));

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            getCheckboxes().forEach((cb) => {
                cb.checked = selectAll.checked;
            });
            updateLabel();
        });
    }

    if (form && hiddenContainer) {
        form.addEventListener('submit', () => {
            const checked = getCheckboxes().filter((cb) => cb.checked);
            hiddenContainer.innerHTML = '';
            checked.forEach((cb) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = hiddenInputName;
                input.value = cb.value;
                hiddenContainer.appendChild(input);
            });
        });
    }

    updateLabel();
}
