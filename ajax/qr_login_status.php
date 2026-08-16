<?php
/**
 * Polled by the Login page's "QR Code Scan" tab (assets/js/qr_login.js)
 * every ~2s. Unauthenticated by definition (nobody is logged in on this
 * browser yet) — looked up by token only, since there's no session to
 * scope by. Must never leak WHO is logging in before the challenge is
 * fully 'completed'.
 *
 * This is the one place a successful QR login actually establishes the
 * desktop browser's session — qr_login_confirm.php (the page the PHONE
 * loads) only ever flips the challenge to 'confirmed'; it never touches
 * $_SESSION itself, since that page is running in the phone's browser,
 * not the desktop's.
 *
 * Replay-safety: the transition confirmed -> completed is a single atomic
 * UPDATE ... WHERE status = 'confirmed'. Only the poll request that wins
 * that race (affected_rows === 1) may establish a session; every other
 * poll — including a second tab, a refresh, or a re-poll of an
 * already-completed token — sees a non-'confirmed' status and gets back a
 * generic "expired" response with no session write and no user data.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$conn = db();

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
    "SELECT id, status, (expires_at < NOW()) AS is_expired FROM qr_login_challenges
     WHERE challenge_token = ? AND purpose = 'login'"
);
$stmt->bind_param('s', $token);
$stmt->execute();
$challenge = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$challenge) {
    respond(false, 'Invalid login code.', ['status' => 'invalid']);
}

if ($challenge['status'] === 'pending') {
    if ((int) $challenge['is_expired'] === 1) {
        respond(true, 'Login code expired.', ['status' => 'expired']);
    }
    respond(true, 'Waiting for scan.', ['status' => 'pending']);
}

if ($challenge['status'] !== 'confirmed') {
    // Already completed by an earlier poll, cancelled, or expired — never
    // re-authenticate and never leak anything further about this token.
    respond(true, 'Login code expired.', ['status' => 'expired']);
}

$roleToDashboard = [
    'university_rector' => 'admin/dashboard.php',
    'head_academic' => 'head_academic/dashboard.php',
    'registration' => 'registration/dashboard.php',
    'dean' => 'dean/dashboard.php',
    'lecturer' => 'lecturer/dashboard.php',
    'student' => 'student/dashboard.php',
];

$conn->begin_transaction();
try {
    $claimStmt = $conn->prepare(
        "UPDATE qr_login_challenges
         SET status = 'completed', completed_at = NOW()
         WHERE challenge_token = ? AND status = 'confirmed'"
    );
    $claimStmt->bind_param('s', $token);
    $claimStmt->execute();
    $claimed = $claimStmt->affected_rows === 1;
    $claimStmt->close();

    if (!$claimed) {
        // Lost the race to another poll hitting the same instant — the
        // other request is the one that gets to establish the session.
        $conn->commit();
        respond(true, 'Login code expired.', ['status' => 'expired']);
    }

    $userStmt = $conn->prepare(
        'SELECT u.id, u.full_name, u.faculty_id, u.must_change_password, r.name AS role_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.id = (SELECT user_id FROM qr_login_challenges WHERE challenge_token = ?)'
    );
    $userStmt->bind_param('s', $token);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if (!$user) {
        $conn->rollback();
        respond(false, 'Account no longer exists.', ['status' => 'expired']);
    }

    $dbRole = (string) $user['role_name'];
    $mustChangePassword = (int) $user['must_change_password'] === 1;

    $deviceStmt = $conn->prepare(
        "UPDATE paired_devices pd
         JOIN qr_login_challenges c ON c.device_id = pd.id
         SET pd.last_used_at = NOW()
         WHERE c.challenge_token = ?"
    );
    $deviceStmt->bind_param('s', $token);
    $deviceStmt->execute();
    $deviceStmt->close();

    $conn->commit();

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $dbRole;
    $_SESSION['full_name'] = (string) $user['full_name'];
    $_SESSION['faculty_id'] = $dbRole === 'dean' ? (int) $user['faculty_id'] : null;
    $_SESSION['must_change_password'] = $mustChangePassword;

    $redirect = $mustChangePassword
        ? role_folder($dbRole) . '/profile.php'
        : ($roleToDashboard[$dbRole] ?? 'index.php');

    respond(true, 'Signed in.', ['status' => 'completed', 'redirect' => $redirect]);
} catch (\Throwable $e) {
    $conn->rollback();
    error_log('[qr_login_status] ' . $e->getMessage());
    http_response_code(500);
    respond(false, 'Something went wrong. Please try again.');
}
