/**
 * "Link Your Phone (QR Login)" card on every role's profile.php. Starts a
 * pairing challenge on page load, renders its QR, and polls for the
 * phone's confirmation — this app's first setInterval + fetch() polling
 * pattern. Requires window.ADMAS_BASE_URL (set inline by the including
 * page, same convention attendance.php already uses for its own fetch()).
 */
(function () {
    const image = document.getElementById('qrPairImage');
    const loading = document.getElementById('qrPairLoading');
    const status = document.getElementById('qrPairStatus');
    const refreshBtn = document.getElementById('qrPairRefreshBtn');
    if (!image || !loading || !status || !refreshBtn) {
        return;
    }

    const base = window.ADMAS_BASE_URL || '';
    let pollTimer = null;

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

        fetch(base + '/ajax/qr_pair_start.php', { method: 'POST' })
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
                status.textContent = 'Scan with your phone’s camera.';
                pollStatus(data.token);
            })
            .catch(() => {
                loading.textContent = 'Could not reach the server.';
                refreshBtn.classList.remove('d-none');
            });
    }

    function pollStatus(token) {
        pollTimer = setInterval(() => {
            fetch(base + '/ajax/qr_pair_status.php?token=' + encodeURIComponent(token))
                .then((r) => r.json())
                .then((data) => {
                    const s = data.status;
                    if (s === 'confirmed') {
                        stopPolling();
                        status.textContent = 'Phone linked!';
                        window.location.reload();
                        return;
                    }
                    if (s === 'expired' || s === 'invalid' || s === 'cancelled') {
                        stopPolling();
                        image.style.display = 'none';
                        status.textContent = 'Code expired.';
                        refreshBtn.classList.remove('d-none');
                    }
                })
                .catch(() => {
                    // transient network hiccup — keep polling, don't give up
                });
        }, 2000);
    }

    refreshBtn.addEventListener('click', startChallenge);

    document.querySelectorAll('.qr-revoke-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!window.confirm('Unlink this phone? It will no longer be able to log in via QR scan.')) {
                return;
            }
            const deviceId = btn.getAttribute('data-device-id');
            const body = new URLSearchParams();
            body.set('device_id', deviceId);

            fetch(base + '/ajax/qr_device_revoke.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
                .then((r) => r.json())
                .then((data) => {
                    if (data.ok) {
                        window.location.reload();
                    } else {
                        alert(data.message || 'Could not unlink this device.');
                    }
                })
                .catch(() => {
                    alert('Could not reach the server.');
                });
        });
    });

    startChallenge();
})();
