/**
 * Lecturer Check-In / Check-Out — intercepts the Check In / Check Out forms
 * on lecturer/checkin.php and submits them via fetch() to
 * ajax/lecturer_checkin_action.php instead of a normal form POST, so the
 * page never reloads. Without this, every click bounced the browser back
 * to the top of the page (a plain redirect_to() after saving), forcing the
 * lecturer to scroll back down to wherever they were in a long session
 * list — this keeps them exactly where they are.
 *
 * Requires window.ADMAS_BASE_URL (set inline by lecturer/checkin.php,
 * same convention as qr_pair.js).
 */
(function () {
    const base = window.ADMAS_BASE_URL || '';
    const table = document.getElementById('checkinTable');
    const flash = document.getElementById('checkinFlash');
    if (!table) {
        return;
    }

    function showFlash(ok, message) {
        if (!flash) {
            return;
        }
        flash.innerHTML = '';
        const div = document.createElement('div');
        div.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger') + ' alert-dismissible fade show';
        div.setAttribute('role', 'alert');
        div.textContent = message;
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close';
        closeBtn.setAttribute('data-bs-dismiss', 'alert');
        closeBtn.setAttribute('aria-label', 'Close');
        div.appendChild(closeBtn);
        flash.appendChild(div);
        window.setTimeout(() => {
            div.classList.remove('show');
        }, 4000);
    }

    function adjustKpi(selector, delta) {
        const el = document.querySelector(selector);
        if (!el) {
            return;
        }
        const current = parseInt(el.textContent.replace(/,/g, ''), 10) || 0;
        el.textContent = (current + delta).toLocaleString();
    }

    table.addEventListener('submit', function (event) {
        const form = event.target.closest('.checkin-action-form');
        if (!form) {
            return;
        }
        event.preventDefault();

        const row = form.closest('tr');
        const actionType = form.dataset.action;
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
        }

        fetch(base + '/ajax/lecturer_checkin_action.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form),
        })
            .then((res) => res.json())
            .then((data) => {
                showFlash(!!data.ok, data.message || (data.ok ? 'Done.' : 'Something went wrong.'));

                if (!data.ok) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                    return;
                }

                if (!row) {
                    return;
                }

                if (actionType === 'check_in') {
                    const inCell = row.querySelector('.checkin-cell-in');
                    const actionCell = row.querySelector('.checkin-cell-action');
                    if (inCell) {
                        inCell.innerHTML = '<span class="badge-pill badge-active"><i class="bi bi-box-arrow-in-right"></i> ' + data.check_in_at + '</span>';
                    }
                    if (actionCell) {
                        actionCell.innerHTML =
                            '<form method="post" action="' + base + '/lecturer/checkin.php" class="d-inline checkin-action-form" data-action="check_out">' +
                            '<input type="hidden" name="action" value="check_out">' +
                            '<input type="hidden" name="checkin_id" value="' + data.checkin_id + '">' +
                            '<button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">' +
                            '<i class="bi bi-box-arrow-right"></i> Check Out</button></form>';
                    }
                    adjustKpi('#checkinKpiCheckedIn', 1);
                    adjustKpi('#checkinKpiPending', -1);
                } else if (actionType === 'check_out') {
                    const outCell = row.querySelector('.checkin-cell-out');
                    const actionCell = row.querySelector('.checkin-cell-action');
                    if (outCell) {
                        outCell.innerHTML = '<span class="badge-pill badge-neutral"><i class="bi bi-box-arrow-right"></i> ' + data.check_out_at + '</span>';
                    }
                    if (actionCell) {
                        actionCell.innerHTML = '<span class="badge-pill badge-active"><i class="bi bi-check2-circle"></i> Done</span>';
                    }
                }
            })
            .catch(() => {
                showFlash(false, 'Network error — please try again.');
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
            });
    });
})();
