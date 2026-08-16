<?php
/**
 * Paired-phone cookie handling for the QR Code Login feature. Assumes
 * includes/auth.php has already been required by the including page (for
 * BASE_URL), same convention as includes/profile_photo.php.
 */
declare(strict_types=1);

const DEVICE_TOKEN_COOKIE = 'admas_device_token';
const DEVICE_TOKEN_TTL_SECONDS = 90 * 24 * 60 * 60; // 90 days

/**
 * Issues the long-lived pairing cookie on the PHONE's browser after a
 * successful pairing confirm.
 */
function issue_device_token_cookie(string $rawToken): void
{
    setcookie(DEVICE_TOKEN_COOKIE, $rawToken, [
        'expires' => time() + DEVICE_TOKEN_TTL_SECONDS,
        'path' => BASE_URL . '/',
        'httponly' => true,
        // Secure is deliberately OFF: this app is served over plain HTTP
        // (LAN-only XAMPP, no TLS certificate). Secure=true would make the
        // browser silently refuse to ever send the cookie back, breaking
        // pairing entirely. Same plaintext-on-LAN exposure this app's own
        // PHP session cookie already has on every other page.
        'secure' => false,
        // Lax, not Strict: a QR scan opens the URL via a normal top-level
        // navigation (camera app -> browser), which Lax always allows.
        'samesite' => 'Lax',
    ]);
}

/**
 * Looks up the non-revoked paired_devices row for whatever phone made the
 * current request, based on its admas_device_token cookie. Returns null if
 * there's no cookie, it's malformed, or it doesn't match a live pairing.
 */
function paired_device_from_cookie(mysqli $conn): ?array
{
    $raw = (string) ($_COOKIE[DEVICE_TOKEN_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) {
        return null;
    }

    $hash = hash('sha256', $raw);
    $stmt = $conn->prepare(
        'SELECT pd.id, pd.user_id, pd.device_label
         FROM paired_devices pd
         WHERE pd.device_token_hash = ? AND pd.revoked_at IS NULL'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Best-effort, human-readable device label from a raw User-Agent string —
 * not a full UA-parsing library, just enough to tell devices apart in the
 * "Linked Devices" list (e.g. "iPhone · Safari", "Android · Chrome").
 */
function device_label_from_user_agent(string $ua): string
{
    $platform = 'Unknown Device';
    if (stripos($ua, 'iPhone') !== false) {
        $platform = 'iPhone';
    } elseif (stripos($ua, 'iPad') !== false) {
        $platform = 'iPad';
    } elseif (stripos($ua, 'Android') !== false) {
        $platform = 'Android';
    } elseif (stripos($ua, 'Windows') !== false) {
        $platform = 'Windows';
    } elseif (stripos($ua, 'Macintosh') !== false) {
        $platform = 'Mac';
    } elseif (stripos($ua, 'Linux') !== false) {
        $platform = 'Linux';
    }

    $browser = 'Browser';
    if (stripos($ua, 'Edg/') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'Chrome/') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'CriOS') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'Safari') !== false) {
        $browser = 'Safari';
    }

    return $platform . ' · ' . $browser;
}
