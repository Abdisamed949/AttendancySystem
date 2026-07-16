<?php
/**
 * Small presentation helpers shared by notifications.php and
 * student/notifications.php, plus the attendance save/read helpers shared
 * by attendance.php, reports.php, and ajax/save_attendance_cell.php.
 */
declare(strict_types=1);

const STATUS_LABELS = [
    'present' => 'Present',
    'absent' => 'Absent',
    'late' => 'Late',
    'excused' => 'Excused',
];

/**
 * The subset of STATUS_LABELS reachable via the interactive Xiiso grid's
 * click-cycle (empty -> Present -> Absent -> Excused -> empty). "Late" is
 * intentionally excluded — it remains settable only via the classic
 * single-session form.
 */
const GRID_STATUS_LABELS = [
    'present' => 'Present',
    'absent' => 'Absent',
    'excused' => 'Excused',
];

/**
 * Red below threshold, yellow near it (within 10 points), green at or above it —
 * matches the Chapter Four mockup's color-coded attendance percentage badges.
 */
function attendance_badge_class(float $pct, float $threshold): string
{
    if ($pct < $threshold - 10) {
        return 'badge-absent';
    }
    if ($pct < $threshold) {
        return 'badge-late';
    }

    return 'badge-present';
}

/**
 * The small "Course › Department › Faculty › Semester › Academic Year"
 * scope line used wherever a course/semester view could otherwise be
 * ambiguous about which faculty's semester it's showing — attendance.php
 * and reports.php's Xiiso grid, since "current semester" is now per
 * faculty rather than a single global one. Pass raw (unescaped) label
 * strings in the order you want them shown; null/empty segments are
 * skipped rather than rendered as a placeholder, so callers can omit
 * whichever segments don't apply (e.g. no semester chosen yet).
 */
function render_scope_breadcrumb(array $segments): string
{
    $parts = array_map(
        static fn ($s) => htmlspecialchars((string) $s),
        array_filter($segments, static fn ($s) => $s !== null && $s !== '')
    );

    if (empty($parts)) {
        return '';
    }

    return '<div class="text-muted small mb-2"><i class="bi bi-signpost-split"></i> '
        . implode(' <span class="mx-1">&rsaquo;</span> ', $parts) . '</div>';
}

/**
 * A course's faculty, via department_id -> departments.faculty_id (courses
 * have no faculty_id of their own). Returns null if the course doesn't
 * exist. Used to resolve which faculty's current semester applies to a
 * given course — see includes/semester_helpers.php's get_current_semester().
 */
function get_course_faculty_id(mysqli $conn, int $courseId): ?int
{
    $stmt = $conn->prepare(
        'SELECT d.faculty_id FROM courses c JOIN departments d ON d.id = c.department_id WHERE c.id = ?'
    );
    $stmt->bind_param('i', $courseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['faculty_id'] : null;
}

/**
 * Inserts or updates exactly one attendance record. This is the single
 * source of truth for the duplicate-prevention logic relied on by both
 * attendance.php's classic single-session form and the Xiiso grid's
 * per-cell AJAX save endpoint — do not re-implement this SQL elsewhere.
 * Relies on the DB unique key uq_attendance_once_per_session
 * (student_id, course_id, session_id); on conflict only status and
 * recorded_by_user_id are updated (attendance_date/shift/academic_year_id
 * are treated as immutable after the first insert for that key).
 */
function save_attendance_record(
    mysqli $conn,
    int $studentId,
    int $courseId,
    int $sessionId,
    int $academicYearId,
    string $shift,
    string $attendanceDate,
    string $status,
    int $recordedByUserId
): void {
    $stmt = $conn->prepare(
        'INSERT INTO attendance (student_id, course_id, session_id, academic_year_id, shift, attendance_date, status, recorded_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), recorded_by_user_id = VALUES(recorded_by_user_id)'
    );
    $stmt->bind_param(
        'iiiisssi',
        $studentId,
        $courseId,
        $sessionId,
        $academicYearId,
        $shift,
        $attendanceDate,
        $status,
        $recordedByUserId
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * Clears one attendance record (the Xiiso grid's fourth click state,
 * "empty", isn't a valid status ENUM value, so clearing a cell deletes the
 * row rather than writing a blank status). Deleting zero matching rows
 * (e.g. clearing a cell that was never saved) is not an error.
 */
function delete_attendance_record(mysqli $conn, int $studentId, int $courseId, int $sessionId): void
{
    $stmt = $conn->prepare('DELETE FROM attendance WHERE student_id = ? AND course_id = ? AND session_id = ?');
    $stmt->bind_param('iii', $studentId, $courseId, $sessionId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Single-course write-permission check, modeled on the course-list scoping
 * already used by attendance.php/reports.php (system_admin: any course;
 * dean: only courses in their own faculty; lecturer: only courses they're
 * currently assigned to teach, via course_offerings — not the deprecated
 * permanent courses.lecturer_id). Used by the AJAX save endpoint, which has
 * no server-rendered $courseById allowlist to lean on the way the
 * page-load flow does — this is that endpoint's actual security boundary.
 * $semesterId must be the *current* semester for this course's own
 * faculty (see get_current_semester()) — a lecturer's write access is
 * scoped to their current-semester offering, so being unassigned for a
 * later semester automatically revokes it, rather than lasting forever.
 */
function user_can_write_course_attendance(mysqli $conn, string $role, array $currentUser, int $courseId, int $semesterId): bool
{
    if ($role === 'system_admin') {
        return true;
    }

    if ($role === 'dean') {
        $facultyId = (int) ($_SESSION['faculty_id'] ?? 0);
        if ($facultyId <= 0) {
            return false;
        }
        $stmt = $conn->prepare(
            'SELECT 1 FROM courses c JOIN departments d ON d.id = c.department_id WHERE c.id = ? AND d.faculty_id = ?'
        );
        $stmt->bind_param('ii', $courseId, $facultyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null;
    }

    if ($role === 'lecturer') {
        $lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
        $lecStmt->bind_param('i', $currentUser['id']);
        $lecStmt->execute();
        $lecRow = $lecStmt->get_result()->fetch_assoc();
        $lecStmt->close();
        $lecturerRecordId = $lecRow ? (int) $lecRow['id'] : 0;
        if ($lecturerRecordId <= 0) {
            return false;
        }

        $stmt = $conn->prepare('SELECT 1 FROM course_offerings WHERE course_id = ? AND semester_id = ? AND lecturer_id = ?');
        $stmt->bind_param('iii', $courseId, $semesterId, $lecturerRecordId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null;
    }

    return false;
}

/**
 * Fetches the raw ingredients of the full-semester Xiiso grid for one
 * course: sessions, roster (enrolled students, falling back to a
 * department/status match if the course has no course_enrollments rows
 * yet), and each student's raw attendance status per session. Shared by
 * reports.php's build_xiiso_grid_report() (which formats these into
 * display codes '1'/'0'/'L'/'E' and computes P/A/%) and attendance.php's
 * interactive Grid View (which renders raw statuses into clickable cells)
 * so the two can never drift on roster-selection logic.
 */
function get_xiiso_grid_data(mysqli $conn, int $courseId, int $semesterId): array
{
    $sessions = get_sessions_for_semester($conn, $semesterId);

    $stmt = $conn->prepare(
        "SELECT s.id, s.student_no, s.full_name
         FROM course_enrollments ce
         JOIN students s ON s.id = ce.student_id
         WHERE ce.course_id = ? AND s.status = 'active'
         ORDER BY s.student_no"
    );
    $stmt->bind_param('i', $courseId);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($students)) {
        $stmt = $conn->prepare(
            "SELECT s.id, s.student_no, s.full_name
             FROM students s
             JOIN courses c ON c.department_id = s.department_id
             WHERE c.id = ? AND s.status = 'active'
             ORDER BY s.student_no"
        );
        $stmt->bind_param('i', $courseId);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $marksByStudentSession = [];
    if (!empty($sessions) && !empty($students)) {
        $sessionIds = array_map(static fn ($s) => (int) $s['id'], $sessions);
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmt = $conn->prepare(
            "SELECT student_id, session_id, status FROM attendance WHERE course_id = ? AND session_id IN ({$placeholders})"
        );
        $stmt->bind_param('i' . str_repeat('i', count($sessionIds)), $courseId, ...$sessionIds);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $marksByStudentSession[(int) $row['student_id']][(int) $row['session_id']] = $row['status'];
        }
        $stmt->close();
    }

    return [
        'sessions' => $sessions,
        'students' => $students,
        'marks' => $marksByStudentSession,
    ];
}

/**
 * Groups a semester's sessions (as returned by get_sessions_for_semester(),
 * already ordered by session_number) into consecutive same-month bands for
 * the Xiiso grid's two-row <thead>. Sessions with no date assigned yet are
 * grouped into a trailing/interleaved "Unscheduled" band rather than
 * breaking the grid. Does not assume exactly 12 sessions.
 */
function build_month_groups(array $sessions): array
{
    $groups = [];
    $currentKey = null;

    foreach ($sessions as $session) {
        $date = $session['date'] ?? null;
        $key = $date ? date('F Y', strtotime((string) $date)) : '__unscheduled__';

        if ($currentKey !== $key) {
            $groups[] = [
                'month_label' => $key === '__unscheduled__' ? 'Unscheduled' : $key,
                'span' => 0,
                'session_ids' => [],
            ];
            $currentKey = $key;
        }

        $lastIndex = count($groups) - 1;
        $groups[$lastIndex]['span']++;
        $groups[$lastIndex]['session_ids'][] = (int) $session['id'];
    }

    return $groups;
}
