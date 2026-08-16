<?php
/**
 * Phone-side pairing confirmation page — reached by scanning the "Link
 * Your Phone" QR shown on an already-authenticated Profile & Password
 * page. Public/unauthenticated: the phone has no session of its own here.
 * Scanning + tapping Confirm is itself the proof of trust (the phone
 * could only have seen this QR by being physically pointed at a screen
 * where the account was already logged in) — no password re-entry.
 *
 * GET renders the confirm screen (or an invalid/expired message). POST
 * does the actual pairing: creates a new paired_devices row, issues the
 * long-lived admas_device_token cookie on THIS (the phone's) browser, and
 * atomically flips the challenge to 'confirmed' so it can never be reused.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/university_logo.php';
require_once __DIR__ . '/includes/qr_helpers.php';
require_once __DIR__ . '/includes/device_helpers.php';

$conn = db();

$token = (string) ($_REQUEST['token'] ?? '');
$validToken = preg_match('/^[a-f0-9]{64}$/', $token) === 1;

$state = 'invalid'; // invalid | confirm | success
$pairingName = '';

if ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        $sel = $conn->prepare(
            "SELECT user_id FROM qr_login_challenges
             WHERE challenge_token = ? AND purpose = 'pair' AND status = 'pending' AND expires_at > NOW()
             FOR UPDATE"
        );
        $sel->bind_param('s', $token);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();

        if (!$row) {
            $conn->rollback();
            $state = 'invalid';
        } else {
            $pairUserId = (int) $row['user_id'];
            $rawDeviceToken = qr_new_token();
            $deviceHash = hash('sha256', $rawDeviceToken);
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $label = device_label_from_user_agent($ua);
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

            $insert = $conn->prepare(
                'INSERT INTO paired_devices (user_id, device_token_hash, device_label, user_agent)
                 VALUES (?, ?, ?, ?)'
            );
            $insert->bind_param('isss', $pairUserId, $deviceHash, $label, $ua);
            $insert->execute();
            $deviceId = (int) $conn->insert_id;
            $insert->close();

            $update = $conn->prepare(
                "UPDATE qr_login_challenges
                 SET status = 'confirmed', confirmed_at = NOW(), device_id = ?, confirming_ip = ?, confirming_user_agent = ?
                 WHERE challenge_token = ? AND status = 'pending' AND expires_at > NOW()"
            );
            $update->bind_param('isss', $deviceId, $ip, $ua, $token);
            $update->execute();
            $confirmed = $update->affected_rows === 1;
            $update->close();

            if (!$confirmed) {
                $conn->rollback();
                $state = 'invalid';
            } else {
                $conn->commit();
                issue_device_token_cookie($rawDeviceToken);
                $state = 'success';
            }
        }
    } catch (\Throwable $e) {
        $conn->rollback();
        error_log('[qr_pair] ' . $e->getMessage());
        $state = 'invalid';
    }
} elseif ($validToken) {
    $stmt = $conn->prepare(
        "SELECT u.full_name FROM qr_login_challenges c
         JOIN users u ON u.id = c.user_id
         WHERE c.challenge_token = ? AND c.purpose = 'pair' AND c.status = 'pending' AND c.expires_at > NOW()"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $state = 'confirm';
        $pairingName = (string) $row['full_name'];
    }
}

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$universityName = $settings['university_name'] ?? 'ADMAS University';
$logoRelativePath = get_university_logo_relative_path($settings);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Your Phone — <?= htmlspecialchars($universityName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #0ea5e9 0%, #38bdf8 45%, #7dd3fc 100%);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .reset-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 20px;
            padding: 2.25rem;
            box-shadow: 0 25px 60px rgba(11, 31, 58, 0.35);
            text-align: center;
        }
        .reset-brand img {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
            margin-bottom: 0.5rem;
        }
        .reset-brand .reset-brand-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: #0b1f3a;
            margin-bottom: 1.25rem;
        }
        .btn-primary {
            background-color: #0ea5e9;
            border-color: #0ea5e9;
        }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="reset-brand">
            <img src="<?= htmlspecialchars(BASE_URL . '/' . $logoRelativePath) ?>" alt="<?= htmlspecialchars($universityName) ?> logo">
            <div class="reset-brand-name"><?= htmlspecialchars($universityName) ?></div>
        </div>

        <?php if ($state === 'confirm'): ?>
            <h2 class="fw-bold mb-2">Link This Phone?</h2>
            <p class="text-muted mb-4">
                Link this phone to <strong><?= htmlspecialchars($pairingName) ?></strong>'s account?
                You'll be able to use it to log in with just a scan — no password needed.
            </p>
            <form method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <button type="submit" class="btn btn-primary w-100 py-2">Confirm</button>
            </form>
        <?php elseif ($state === 'success'): ?>
            <h2 class="fw-bold mb-2">Phone Linked!</h2>
            <p class="text-muted mb-0">You can close this tab and return to your computer.</p>
        <?php else: ?>
            <h2 class="fw-bold mb-2">Invalid Code</h2>
            <p class="text-muted mb-0">This pairing code is invalid, expired, or already used. Go back to Profile &amp; Password and generate a new one.</p>
        <?php endif; ?>
    </div>
</body>
</html>
