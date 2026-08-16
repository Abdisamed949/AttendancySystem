<?php
/**
 * Starts a new login challenge from the UNAUTHENTICATED Login page's "QR
 * Code Scan" tab. Deliberately does NOT call require_login()/require_role()
 * — by definition nobody is logged in yet on this browser. user_id stays
 * NULL until a paired phone scans + confirms it (qr_login_confirm.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/qr_helpers.php';

$conn = db();

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

// Opportunistic cleanup, same as qr_pair_start.php — no cron infrastructure
// exists in this app, so this runs once per new-challenge request instead.
$conn->query('DELETE FROM qr_login_challenges WHERE expires_at < (NOW() - INTERVAL 1 DAY)');

try {
    $token = qr_new_token();
    $stmt = $conn->prepare(
        "INSERT INTO qr_login_challenges
            (purpose, challenge_token, user_id, status, expires_at, requesting_ip, requesting_user_agent)
         VALUES ('login', ?, NULL, 'pending', DATE_ADD(NOW(), INTERVAL 3 MINUTE), ?, ?)"
    );
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $stmt->bind_param('sss', $token, $ip, $ua);
    $stmt->execute();
    $stmt->close();

    respond(true, 'Login code generated.', ['token' => $token, 'expires_in' => 180]);
} catch (\Throwable $e) {
    error_log('[qr_login_start] ' . $e->getMessage());
    http_response_code(500);
    respond(false, 'Failed to generate a login code. Please try again.');
}
