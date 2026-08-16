<?php
/**
 * Polled by the Profile & Password page (assets/js/qr_pair.js) every ~2s
 * while a pairing QR is on screen. Deliberately GET, not POST, unlike the
 * rest of this app's ajax/ convention — this is a pure read (no state
 * mutation happens here; the phone-side confirm in qr_pair.php is what
 * actually flips the row to 'confirmed'), so GET is the correct verb.
 *
 * Scoped to the CURRENT session's own user_id — prevents one logged-in
 * user from polling (and learning the pairing status of) another user's
 * challenge token.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login();

$conn = db();
$userId = (int) $_SESSION['user_id'];

header('Content-Type: application/json');

function respond(bool $ok, string $message, array $extra = []): never
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    respond(false, 'Method not allowed.');
}

$token = (string) ($_GET['token'] ?? '');
if ($token === '') {
    respond(false, 'Missing token.');
}

// Expiry computed by MySQL (expires_at < NOW()), not PHP's strtotime()/
// time() — see qr_image.php's header comment for why.
$stmt = $conn->prepare(
    "SELECT status, (expires_at < NOW()) AS is_expired, device_id
     FROM qr_login_challenges
     WHERE challenge_token = ? AND purpose = 'pair' AND user_id = ?"
);
$stmt->bind_param('si', $token, $userId);
$stmt->execute();
$challenge = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$challenge) {
    respond(false, 'Invalid pairing code.', ['status' => 'invalid']);
}

if ($challenge['status'] === 'pending' && (int) $challenge['is_expired'] === 1) {
    respond(true, 'Pairing code expired.', ['status' => 'expired']);
}

if ($challenge['status'] === 'confirmed' && $challenge['device_id'] !== null) {
    $deviceStmt = $conn->prepare(
        'SELECT device_label, paired_at FROM paired_devices WHERE id = ?'
    );
    $deviceId = (int) $challenge['device_id'];
    $deviceStmt->bind_param('i', $deviceId);
    $deviceStmt->execute();
    $device = $deviceStmt->get_result()->fetch_assoc();
    $deviceStmt->close();

    respond(true, 'Phone linked.', [
        'status' => 'confirmed',
        'device' => $device ?: null,
    ]);
}

respond(true, 'Waiting for scan.', ['status' => $challenge['status']]);
