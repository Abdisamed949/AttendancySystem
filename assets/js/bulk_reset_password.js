/**
 * Generic "select rows -> Reset Password Selected (N) -> confirm -> submit"
 * wiring — sibling to bulk_delete.js's admasInitBulkDelete(), reusing the
 * same checkbox/hidden-form pattern, but with its own (non-destructive but
 * still consequential — it invalidates the current login for every
 * selected account) confirm wording and button label.
 */
function admasInitBulkResetPassword(opts) {
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
                btn.textContent = `Reset Password Selected (${n})`;
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
                `Reset the password for ${n} ${noun}?\n\n${sample}\n\n`
                + 'Each account will get a brand-new temporary username and password — their current login will '
                + 'stop working immediately. The new credentials will be shown once on the next page.'
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
