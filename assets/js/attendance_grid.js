/**
 * Interactive Xiiso grid on attendance.php: click a cell to cycle its
 * status ('' -> present -> absent -> ''), saving each change via AJAX with
 * an optimistic UI that rolls back on failure.
 */
document.addEventListener('DOMContentLoaded', function () {
    var table = document.getElementById('xiisoGridTable');
    if (!table) {
        return;
    }

    var tbody = table.querySelector('tbody');
    var inFlight = new Set();

    var NEXT_STATUS = {
        '': 'present',
        'present': 'absent',
        'absent': '',
    };

    var GLYPHS = {
        '': '',
        'present': 'P',
        'absent': 'A',
    };

    function cellKey(button) {
        return button.dataset.studentId + ':' + button.dataset.sessionId;
    }

    function recomputeRowLocally(row) {
        var buttons = row.querySelectorAll('.grid-cell');
        var present = 0;
        var absent = 0;
        var total = 0;

        buttons.forEach(function (btn) {
            var status = btn.dataset.status;
            if (status !== '') {
                total++;
                if (status === 'present') {
                    present++;
                } else if (status === 'absent') {
                    absent++;
                }
            }
        });

        var pct = total > 0 ? Math.round((100 * present / total) * 10) / 10 : 0;
        row.querySelector('[data-role="present-count"]').textContent = present;
        row.querySelector('[data-role="absent-count"]').textContent = absent;
        row.querySelector('[data-role="pct"]').textContent = pct;
    }

    function showGridError(message) {
        var existing = document.getElementById('gridCellError');
        if (existing) {
            existing.remove();
        }

        var alertEl = document.createElement('div');
        alertEl.id = 'gridCellError';
        alertEl.className = 'alert alert-danger alert-dismissible fade show mt-2';
        alertEl.setAttribute('role', 'alert');
        alertEl.textContent = message;

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close';
        closeBtn.setAttribute('data-bs-dismiss', 'alert');
        closeBtn.setAttribute('aria-label', 'Close');
        alertEl.appendChild(closeBtn);

        table.parentElement.insertBefore(alertEl, table.parentElement.firstChild);
        window.setTimeout(function () {
            alertEl.remove();
        }, 6000);
    }

    tbody.addEventListener('click', function (event) {
        var button = event.target.closest('.grid-cell');
        if (!button || button.disabled) {
            return;
        }

        var key = cellKey(button);
        if (inFlight.has(key)) {
            return;
        }

        var row = button.closest('tr');
        var previousStatus = button.dataset.status;
        var previousGlyph = button.textContent;
        var previousPresent = row.querySelector('[data-role="present-count"]').textContent;
        var previousAbsent = row.querySelector('[data-role="absent-count"]').textContent;
        var previousPct = row.querySelector('[data-role="pct"]').textContent;

        var nextStatus = Object.prototype.hasOwnProperty.call(NEXT_STATUS, previousStatus)
            ? NEXT_STATUS[previousStatus]
            : 'present';

        button.dataset.status = nextStatus;
        button.textContent = GLYPHS[nextStatus];
        recomputeRowLocally(row);

        inFlight.add(key);
        button.classList.add('is-loading');

        var body = new URLSearchParams({
            course_id: button.dataset.courseId,
            semester_id: table.dataset.semesterId,
            session_id: button.dataset.sessionId,
            student_id: button.dataset.studentId,
            status: nextStatus,
        });

        fetch(window.ADMAS_BASE_URL + '/ajax/save_attendance_cell.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.ok) {
                    row.querySelector('[data-role="present-count"]').textContent = data.present_count;
                    row.querySelector('[data-role="absent-count"]').textContent = data.absent_count;
                    row.querySelector('[data-role="pct"]').textContent = data.attendance_pct;
                    return;
                }

                button.dataset.status = previousStatus;
                button.textContent = previousGlyph;
                row.querySelector('[data-role="present-count"]').textContent = previousPresent;
                row.querySelector('[data-role="absent-count"]').textContent = previousAbsent;
                row.querySelector('[data-role="pct"]').textContent = previousPct;
                showGridError(data.message || 'Failed to save attendance. Please try again.');
            })
            .catch(function () {
                button.dataset.status = previousStatus;
                button.textContent = previousGlyph;
                row.querySelector('[data-role="present-count"]').textContent = previousPresent;
                row.querySelector('[data-role="absent-count"]').textContent = previousAbsent;
                row.querySelector('[data-role="pct"]').textContent = previousPct;
                showGridError('Network error — could not save attendance. Please try again.');
            })
            .finally(function () {
                inFlight.delete(key);
                button.classList.remove('is-loading');
            });
    });
});
