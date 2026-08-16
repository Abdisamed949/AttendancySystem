<?php
/**
 * Streams a QR code PNG for a live qr_login_challenges token — used as the
 * <img src="..."> on both the Profile & Password pairing card and the
 * Login page's QR Code Scan tab. Public/unauthenticated: this is loaded
 * by an ordinary <img> tag, and the pairing flow's own confirm page
 * (qr_pair.php) is reached by the PHONE, which has no session either.
 *
 * The QR payload is always an ABSOLUTE URL (derived from the CURRENT
 * request via qr_absolute_url() — see includes/qr_helpers.php), since a
 * phone scanning the code has no "current host" to resolve a relative URL
 * against. The purpose (pair vs login) is read from the challenge row
 * itself, never trusted from the query string, so the target page can't
 * be spoofed by a tampered request.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/qr_helpers.php';

$conn = db();

$token = (string) ($_GET['token'] ?? '');

function qr_image_blank(): never
{
    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    http_response_code(404);
    // 1x1 transparent PNG — no existence/validity information leaked
    // through the image itself.
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    exit;
}

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    qr_image_blank();
}

// Expiry is computed by MySQL itself (expires_at < NOW()), never compared
// via PHP's strtotime()/time() — PHP's date.timezone can legitimately
// differ from MySQL's own SYSTEM timezone, which would otherwise make a
// perfectly valid, unexpired row look expired (or vice versa).
$stmt = $conn->prepare(
    "SELECT purpose, status, (expires_at < NOW()) AS is_expired
     FROM qr_login_challenges WHERE challenge_token = ?"
);
$stmt->bind_param('s', $token);
$stmt->execute();
$challenge = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$challenge || (int) $challenge['is_expired'] === 1) {
    qr_image_blank();
}

$targetPage = $challenge['purpose'] === 'pair' ? 'qr_pair.php' : 'qr_login_confirm.php';
$payload = qr_absolute_url($targetPage . '?token=' . $token);

header('Content-Type: image/png');
header('Cache-Control: no-store');
echo qr_render_png($payload);
