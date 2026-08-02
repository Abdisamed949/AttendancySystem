<?php
/**
 * Single-cell AJAX save endpoint for attendance.php's interactive Xiiso
 * grid. Saves (or clears) exactly one attendance record and returns that
 * student's recomputed P/A/% for the course+semester, so the client can
 * reconcile its optimistic UI against the authoritative server state
 * rather than trusting its own running tally.
 *
 * Reuses the exact same validation and duplicate-prevention logic as
 * attendance.php's classic single-session save path via the shared
 * helpers in includes/attendance_helpers.php — no parallel implementation.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/semester_helpers.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['system_admin', 'dean', 'lecturer']);

$conn = db();
$currentUser = current_user();
$role = current_role();

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

$courseId = (int) ($_POST['course_id'] ?? 0);
$semesterId = (int) ($_POST['semester_id'] ?? 0);
$sessionId = (int) ($_POST['session_id'] ?? 0);
$studentId = (int) ($_POST['student_id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');

if (get_course_faculty_id($conn, $courseId) === null) {
    respond(false, 'Invalid course.');
}

// The Grid View lets an admin/dean/lecturer view and mark ANY semester
// this course has a real offering in — not just a semester belonging to
// the course's own catalog faculty, since a course can now be
// cross-listed into a DIFFERENT faculty's semester track (see the
// Multi-Faculty Course Offerings plan; matches
// attendance_import.php's own historical-backfill scope otherwise) — so
// this must validate the specific semester the client says it's editing
// actually has a course_offerings row, never silently substitute
// "whichever semester is current right now" or assume the course has
// only one faculty.
if (!course_offering_exists($conn, $courseId, $semesterId)) {
    respond(false, 'Invalid semester for this course.');
}

$semStmt = $conn->prepare(
    "SELECT s.id, s.academic_year_id, s.faculty_id, s.name, s.start_date, s.end_date, s.is_current,
            ay.label AS academic_year_label
     FROM semesters s
     JOIN academic_years ay ON ay.id = s.academic_year_id
     WHERE s.id = ?"
);
$semStmt->bind_param('i', $semesterId);
$semStmt->execute();
$semester = $semStmt->get_result()->fetch_assoc();
$semStmt->close();

if ($semester === null) {
    respond(false, 'Invalid semester for this course.');
}

$semesterSessions = get_sessions_for_semester($conn, (int) $semester['id']);
$sessionById = [];
foreach ($semesterSessions as $s) {
    $sessionById[(int) $s['id']] = $s;
}

if (!array_key_exists($sessionId, $sessionById)) {
    respond(false, 'Invalid Xiiso session.');
}

$sessionDate = (string) ($sessionById[$sessionId]['date'] ?? '');
if ($sessionDate === '') {
    respond(false, 'This Xiiso session has no calendar date assigned yet — ask an admin to assign one in Semesters.');
}

if ($status !== '' && !array_key_exists($status, GRID_STATUS_LABELS)) {
    respond(false, 'Invalid status.');
}

// Resolved before the write-permission check below, since that check must
// verify the lecturer is authorized for THIS student's own shift — not
// whatever shift filter the lecturer happens to have selected on screen
// (there is no filter selection available at this endpoint's scope
// anyway; the student's own shift is the only shift that matters here).
$stmt = $conn->prepare("SELECT shift, academic_year_id FROM students WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($student === null) {
    respond(false, 'Student not found.');
}

$shift = (string) $student['shift'];
$academicYearId = (int) $student['academic_year_id'];
$recordedBy = (int) $currentUser['id'];

if (!user_can_write_course_attendance($conn, $role, $currentUser, $courseId, (int) $semester['id'], $shift)) {
    http_response_code(403);
    respond(false, 'You do not have permission to edit attendance for this course.');
}

try {
    if ($status === '') {
        delete_attendance_record($conn, $studentId, $courseId, $sessionId);
    } else {
        save_attendance_record(
            $conn,
            $studentId,
            $courseId,
            $sessionId,
            $academicYearId,
            $shift,
            $sessionDate,
            $status,
            $recordedBy
        );
    }
} catch (\Throwable $e) {
    http_response_code(500);
    respond(false, 'Failed to save attendance. Please try again.');
}

// Recompute this student's P/A/% across the whole current semester for
// this course — same present/absent/total-non-empty logic as
// build_xiiso_grid_report(), so the client's row totals stay authoritative.
$presentCount = 0;
$absentCount = 0;
$totalMarks = 0;

if (!empty($semesterSessions)) {
    $sessionIds = array_map(static fn ($s) => (int) $s['id'], $semesterSessions);
    $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
    $stmt = $conn->prepare(
        "SELECT status FROM attendance WHERE course_id = ? AND student_id = ? AND session_id IN ({$placeholders})"
    );
    $stmt->bind_param('ii' . str_repeat('i', count($sessionIds)), $courseId, $studentId, ...$sessionIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $totalMarks++;
        if ($row['status'] === 'present') {
            $presentCount++;
        } elseif ($row['status'] === 'absent') {
            $absentCount++;
        }
    }
    $stmt->close();
}

respond(true, 'Saved.', [
    'student_id' => $studentId,
    'session_id' => $sessionId,
    'status' => $status,
    'present_count' => $presentCount,
    'absent_count' => $absentCount,
    'attendance_pct' => $totalMarks > 0 ? round(100 * $presentCount / $totalMarks, 1) : 0.0,
]);
