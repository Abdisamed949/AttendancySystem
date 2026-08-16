<?php
/**
 * Phone-side login confirmation page — reached by scanning the QR shown
 * on the (unauthenticated) Login page's "QR Code Scan" tab. Public: the
 * phone browser identifies itself only via its admas_device_token cookie
 * (issued during an earlier pairing on qr_pair.php).
 *
 * This page NEVER touches $_SESSION itself — it only flips the challenge
 * to 'confirmed'. The desktop's ajax/qr_login_status.php poller is what
 * actually establishes the desktop browser's session, since that's the
 * browser that needs the session, not this one.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/university_logo.php';
require_once __DIR__ . '/includes/device_helpers.php';

$conn = db();

$token = (string) ($_REQUEST['token'] ?? '');
$validToken = preg_match('/^[a-f0-9]{64}$/', $token) === 1;

$device = paired_device_from_cookie($conn);

$state = 'not_linked'; // not_linked | invalid | confirm | success
$ownerName = '';

if ($device === null) {
    $state = 'not_linked';
} elseif ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $deviceId = (int) $device['id'];
        $ownerUserId = (int) $device['user_id'];
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $stmt = $conn->prepare(
            "UPDATE qr_login_challenges
             SET status = 'confirmed', confirmed_at = NOW(), user_id = ?, device_id = ?, confirming_ip = ?, confirming_user_agent = ?
             WHERE challenge_token = ? AND purpose = 'login' AND status = 'pending' AND expires_at > NOW()"
        );
        $stmt->bind_param('iisss', $ownerUserId, $deviceId, $ip, $ua, $token);
        $stmt->execute();
        $state = $stmt->affected_rows === 1 ? 'success' : 'invalid';
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[qr_login_confirm] ' . $e->getMessage());
        $state = 'invalid';
    }
} elseif ($validToken) {
    // Expiry checked by MySQL itself (expires_at > NOW()), never via PHP's
    // strtotime()/time() — see qr_image.php's header comment for why.
    $stmt = $conn->prepare(
        "SELECT status FROM qr_login_challenges
         WHERE challenge_token = ? AND purpose = 'login' AND status = 'pending' AND expires_at > NOW()"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $state = $row ? 'confirm' : 'invalid';
}

if ($state === 'confirm') {
    $nameStmt = $conn->prepare('SELECT full_name FROM users WHERE id = ?');
    $ownerUserId = (int) $device['user_id'];
    $nameStmt->bind_param('i', $ownerUserId);
    $nameStmt->execute();
    $nameRow = $nameStmt->get_result()->fetch_assoc();
    $nameStmt->close();
    $ownerName = $nameRow ? (string) $nameRow['full_name'] : '';
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
    <title>Log In — <?= htmlspecialchars($universityName) ?></title>
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

        <?php if ($state === 'not_linked'): ?>
            <h2 class="fw-bold mb-2">Phone Not Linked</h2>
            <p class="text-muted mb-0">This phone isn't linked yet. Pair it first from Profile &amp; Password on a device where you're already signed in.</p>
        <?php elseif ($state === 'confirm'): ?>
            <h2 class="fw-bold mb-2">Confirm Sign In</h2>
            <p class="text-muted mb-4">
                Log in as <strong><?= htmlspecialchars($ownerName) ?></strong> on the other device?
            </p>
            <form method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <button type="submit" class="btn btn-primary w-100 py-2">Confirm Login</button>
            </form>
        <?php elseif ($state === 'success'): ?>
            <h2 class="fw-bold mb-2">Confirmed!</h2>
            <p class="text-muted mb-0">Check your other device — it should sign in automatically within a few seconds.</p>
        <?php else: ?>
            <h2 class="fw-bold mb-2">Invalid Code</h2>
            <p class="text-muted mb-0">This login code is invalid, expired, or already used. Go back to the Login page and try again.</p>
        <?php endif; ?>
    </div>
</body>
</html>
