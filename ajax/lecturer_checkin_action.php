<?php
/**
 * AJAX Check In / Check Out for lecturer/checkin.php — same two actions and
 * exact same authorization/uniqueness rules as that page's own POST
 * handler (kept in sync via the shared lecturer_owns_current_session()
 * helper in includes/attendance_helpers.php), just returning JSON instead
 * of a redirect so the page can update the one affected row in place
 * without a full reload — that reload was what threw the lecturer's scroll
 * position back to the top of a long session list on every click.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['lecturer']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$conn = db();
$currentUser = current_user();

$lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
$lecStmt->bind_param('i', $currentUser['id']);
$lecStmt->execute();
$lecRow = $lecStmt->get_result()->fetch_assoc();
$lecStmt->close();
$lecturerId = $lecRow ? (int) $lecRow['id'] : 0;

$action = (string) ($_POST['action'] ?? '');

if ($action === 'check_in') {
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $sessionId = (int) ($_POST['session_id'] ?? 0);

    if (!lecturer_owns_current_session($conn, $lecturerId, $courseId, $sessionId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Invalid course/session — you can only check in to a session you currently teach.']);
        exit;
    }

    $existsStmt = $conn->prepare('SELECT id FROM lecturer_checkins WHERE lecturer_id = ? AND course_id = ? AND session_id = ?');
    $existsStmt->bind_param('iii', $lecturerId, $courseId, $sessionId);
    $existsStmt->execute();
    $exists = $existsStmt->get_result()->fetch_assoc();
    $existsStmt->close();

    if ($exists) {
        echo json_encode(['ok' => false, 'message' => 'You have already checked in for this session.']);
        exit;
    }

    $insStmt = $conn->prepare('INSERT INTO lecturer_checkins (lecturer_id, course_id, session_id, check_in_at) VALUES (?, ?, ?, NOW())');
    $insStmt->bind_param('iii', $lecturerId, $courseId, $sessionId);
    $insStmt->execute();
    $checkinId = $insStmt->insert_id;
    $insStmt->close();

    echo json_encode([
        'ok' => true,
        'message' => 'Checked in at ' . date('g:i A') . '.',
        'checkin_id' => $checkinId,
        'check_in_at' => date('g:i A'),
        'check_out_at' => null,
        'status' => 'checked_in',
    ]);
    exit;
}

if ($action === 'check_out') {
    $checkinId = (int) ($_POST['checkin_id'] ?? 0);

    // Scoped by lecturer_id in the same UPDATE — a crafted checkin_id
    // belonging to another lecturer can never be checked out from here.
    $updStmt = $conn->prepare(
        'UPDATE lecturer_checkins SET check_out_at = NOW()
         WHERE id = ? AND lecturer_id = ? AND check_out_at IS NULL'
    );
    $updStmt->bind_param('ii', $checkinId, $lecturerId);
    $updStmt->execute();
    $affected = $updStmt->affected_rows;
    $updStmt->close();

    if ($affected === 0) {
        echo json_encode(['ok' => false, 'message' => 'Could not check out — already checked out, or not your session.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Checked out at ' . date('g:i A') . '.',
        'checkin_id' => $checkinId,
        'check_out_at' => date('g:i A'),
        'status' => 'done',
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
