/**
 * Login page's "QR Code Scan" tab. Starts a login challenge the first
 * time that tab is shown (not on page load, so a normal password login
 * never creates an unused row), renders its QR, and polls for the
 * phone's confirmation — on success, redirects this browser straight to
 * the signed-in dashboard with no password ever typed.
 */
(function () {
    const tabBtn = document.getElementById('qrTabBtn');
    const image = document.getElementById('qrLoginImage');
    const loading = document.getElementById('qrLoginLoading');
    const status = document.getElementById('qrLoginStatus');
    const refreshBtn = document.getElementById('qrLoginRefreshBtn');
    if (!tabBtn || !image || !loading || !status || !refreshBtn) {
        return;
    }

    const base = window.ADMAS_BASE_URL || '';
    let pollTimer = null;
    let started = false;

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function startChallenge() {
        stopPolling();
        image.style.display = 'none';
        refreshBtn.classList.add('d-none');
        loading.style.display = '';
        loading.textContent = 'Generating code...';
        status.textContent = '';

        fetch(base + '/ajax/qr_login_start.php', { method: 'POST' })
            .then((r) => r.json())
            .then((data) => {
                if (!data.ok) {
                    loading.textContent = data.message || 'Could not generate a code.';
                    refreshBtn.classList.remove('d-none');
                    return;
                }
                image.src = base + '/qr_image.php?token=' + encodeURIComponent(data.token);
                image.style.display = '';
                loading.style.display = 'none';
                status.textContent = 'Scan with your linked phone’s camera.';
                pollStatus(data.token);
            })
            .catch(() => {
                loading.textContent = 'Could not reach the server.';
                refreshBtn.classList.remove('d-none');
            });
    }

    function pollStatus(token) {
        pollTimer = setInterval(() => {
            fetch(base + '/ajax/qr_login_status.php?token=' + encodeURIComponent(token))
                .then((r) => r.json())
                .then((data) => {
                    const s = data.status;
                    if (s === 'completed') {
                        stopPolling();
                        status.textContent = 'Signed in! Redirecting…';
                        window.location.assign(base + '/' + data.redirect);
                        return;
                    }
                    if (s === 'expired' || s === 'invalid' || s === 'cancelled') {
                        stopPolling();
                        image.style.display = 'none';
                        status.textContent = 'Code expired.';
                        refreshBtn.classList.remove('d-none');
                    }
                    // 'pending' / 'confirmed' -> keep polling silently
                })
                .catch(() => {
                    // transient network hiccup — keep polling, don't give up
                });
        }, 2000);
    }

    refreshBtn.addEventListener('click', startChallenge);

    tabBtn.addEventListener('shown.bs.tab', () => {
        if (!started) {
            started = true;
            startChallenge();
        }
    });
})();
