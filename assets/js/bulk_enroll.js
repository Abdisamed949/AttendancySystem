/**
 * Generic "select rows -> Enroll Selected (N) -> confirm -> submit" wiring
 * for admin/course_enrollments.php — same checkbox/select-all/hidden-form
 * mechanic as assets/js/bulk_delete.js (see that file's own header comment
 * for why the hidden form is kept separate from each row's own markup),
 * but not a literal reuse of it: that file's click handler hardcodes
 * delete-specific button text and confirm() wording, which don't fit a
 * non-destructive "add" action.
 */
function admasInitBulkEnroll(opts) {
    const {
        checkboxSelector,
        selectAllSelector,
        buttonSelector,
        formSelector,
        hiddenContainerSelector,
        hiddenInputName,
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
                btn.textContent = `Enroll Selected (${n})`;
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
            const noun = n === 1 ? 'student' : 'students';

            const confirmed = window.confirm(
                `Enroll ${n} ${noun} into this course?\n\n${sample}`
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
