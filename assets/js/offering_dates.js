// Auto-fills a course_offerings "End Date" field 3 months (minus a day) after
// its "Start Date" field, mirroring semester_end_date_from_start()'s exact
// math server-side (12 Xiiso sessions ~= one 3-month semester). Only ever
// overwrites a value it filled in itself — a hand-typed End Date is left
// alone once the user has edited it directly.
function admasWireOfferingDateAutoFill(startInputId, endInputId) {
    const startInput = document.getElementById(startInputId);
    const endInput = document.getElementById(endInputId);
    if (!startInput || !endInput) return;

    endInput.addEventListener('input', () => {
        endInput.dataset.autofilled = '';
    });

    startInput.addEventListener('change', () => {
        if (!startInput.value) return;
        if (endInput.value && endInput.dataset.autofilled !== 'true') return;

        const start = new Date(startInput.value + 'T00:00:00');
        if (isNaN(start.getTime())) return;

        const end = new Date(start);
        end.setMonth(end.getMonth() + 3);
        end.setDate(end.getDate() - 1);

        const yyyy = end.getFullYear();
        const mm = String(end.getMonth() + 1).padStart(2, '0');
        const dd = String(end.getDate()).padStart(2, '0');
        endInput.value = `${yyyy}-${mm}-${dd}`;
        endInput.dataset.autofilled = 'true';
    });
}
