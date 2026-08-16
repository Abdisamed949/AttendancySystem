<?php
/**
 * Starts a new phone-pairing challenge for the CURRENTLY LOGGED IN user
 * (any of the 6 roles) from their own Profile & Password page. The
 * returned token is what gets encoded into the pairing QR image
 * (qr_image.php) and, once a phone scans + confirms it (qr_pair.php),
 * becomes a long-lived paired_devices row.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/qr_helpers.php';

require_login();

$conn = db();
$userId = (int) $_SESSION['user_id'];

header('Content-Type: application/json');

function respond(bool $ok, string $message, array $extra = []): never
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Method not allowed.');
}

// Opportunistic cleanup — this app has no cron infrastructure anywhere, so
// old challenge rows are swept once per new-challenge request instead of
// on a schedule. Every read/write path already filters expires_at > NOW(),
// so a stale row left behind for a day is inert, never a security issue.
$conn->query('DELETE FROM qr_login_challenges WHERE expires_at < (NOW() - INTERVAL 1 DAY)');

try {
    $token = qr_new_token();
    $stmt = $conn->prepare(
        "INSERT INTO qr_login_challenges
            (purpose, challenge_token, user_id, status, expires_at, requesting_ip, requesting_user_agent)
         VALUES ('pair', ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 3 MINUTE), ?, ?)"
    );
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $stmt->bind_param('siss', $token, $userId, $ip, $ua);
    $stmt->execute();
    $stmt->close();

    respond(true, 'Pairing code generated.', ['token' => $token, 'expires_in' => 180]);
} catch (\Throwable $e) {
    error_log('[qr_pair_start] ' . $e->getMessage());
    http_response_code(500);
    respond(false, 'Failed to generate a pairing code. Please try again.');
}
