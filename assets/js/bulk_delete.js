/**
 * Generic "select rows -> Delete Selected (N) -> confirm -> submit" wiring,
 * shared by every admin/*.php list page that supports bulk delete. Kept
 * framework-free (no build step in this project) and reusable across pages
 * with different entity types via the options object.
 *
 * Checkboxes are intentionally NOT inside the table's own per-row forms
 * (each row already has its own tiny <form> for Edit/Reset/Delete — nesting
 * a table-wide <form> around those would be invalid HTML). Instead this
 * copies the checked values into a separate hidden form right before
 * submitting it.
 */
function admasInitBulkDelete(opts) {
    const {
        checkboxSelector,
        selectAllSelector,
        buttonSelector,
        formSelector,
        hiddenContainerSelector,
        hiddenInputName,
        entityLabel,
        entityLabelPlural,
    } = opts;

    const getCheckboxes = () => Array.from(document.querySelectorAll(checkboxSelector));
    const selectAll = selectAllSelector ? document.querySelector(selectAllSelector) : null;
    const btn = document.querySelector(buttonSelector);
    const form = document.querySelector(formSelector);
    const hiddenContainer = document.querySelector(hiddenContainerSelector);

    function updateButton() {
        const checked = getCheckboxes().filter((cb) => cb.checked);
        const n = checked.length;

        if (btn) {
            if (n === 0) {
                btn.classList.add('d-none');
            } else {
                btn.classList.remove('d-none');
                btn.textContent = `Delete Selected (${n})`;
            }
        }

        if (selectAll) {
            const all = getCheckboxes();
            selectAll.checked = n > 0 && n === all.length;
            selectAll.indeterminate = n > 0 && n < all.length;
        }
    }

    getCheckboxes().forEach((cb) => cb.addEventListener('change', updateButton));

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            getCheckboxes().forEach((cb) => {
                cb.checked = selectAll.checked;
            });
            updateButton();
        });
    }

    if (btn && form && hiddenContainer) {
        btn.addEventListener('click', () => {
            const checked = getCheckboxes().filter((cb) => cb.checked);
            const n = checked.length;
            if (n === 0) {
                return;
            }

            const labels = checked.slice(0, 5).map((cb) => cb.dataset.label || '').filter(Boolean);
            let sample = labels.join(', ');
            if (n > labels.length) {
                sample += `, and ${n - labels.length} more`;
            }
            const noun = n === 1 ? entityLabel : entityLabelPlural;

            const confirmed = window.confirm(
                `Delete ${n} ${noun}?\n\n${sample}\n\n`
                + 'Rows with linked records (attendance, enrollments, offerings, etc.) will be skipped automatically. '
                + 'This cannot be undone for the rest.'
            );
            if (!confirmed) {
                return;
            }

            hiddenContainer.innerHTML = '';
            checked.forEach((cb) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = hiddenInputName;
                input.value = cb.value;
                hiddenContainer.appendChild(input);
            });
            form.submit();
        });
    }

    updateButton();
}
