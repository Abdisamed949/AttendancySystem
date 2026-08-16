<?php
/**
 * Revokes one of the CURRENT user's own paired devices from the "Linked
 * Devices" list on Profile & Password. Soft-delete (revoked_at), never a
 * hard DELETE, so a revoked pairing stays auditable.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Method not allowed.');
}

$deviceId = (int) ($_POST['device_id'] ?? 0);
if ($deviceId <= 0) {
    respond(false, 'Invalid device.');
}

try {
    // user_id = ? is the ownership check — prevents revoking another
    // user's device by guessing/tampering with an id.
    $stmt = $conn->prepare(
        'UPDATE paired_devices SET revoked_at = NOW()
         WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
    );
    $stmt->bind_param('ii', $deviceId, $userId);
    $stmt->execute();
    $revoked = $stmt->affected_rows === 1;
    $stmt->close();

    if (!$revoked) {
        respond(false, 'Device not found.');
    }

    respond(true, 'Device unlinked.');
} catch (\Throwable $e) {
    error_log('[qr_device_revoke] ' . $e->getMessage());
    http_response_code(500);
    respond(false, 'Failed to unlink device. Please try again.');
}
