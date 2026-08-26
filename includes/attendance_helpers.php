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
];

/**
 * Same two statuses as STATUS_LABELS — kept as a separate constant since
 * the interactive Xiiso grid's click-cycle and the classic single-session
 * form validate against it independently (see attendance.php and
 * ajax/save_attendance_cell.php).
 */
const GRID_STATUS_LABELS = [
    'present' => 'Present',
    'absent' => 'Absent',
];

/**
 * Red below threshold, yellow near it (within 1 point), green at or above it —
 * matches the Chapter Four mockup's color-coded attendance percentage badges.
 * The 1-point buffer (not 10) matches the out-of-10 attendance scale: each
 * point is one Present regular Xiiso session (see ATTENDANCE_MAX_SCORE).
 */
function attendance_badge_class(float $pct, float $threshold): string
{
    if ($pct < $threshold - 1) {
        return 'badge-absent';
    }
    if ($pct < $threshold) {
        return 'badge-warning';
    }

    return 'badge-present';
}

/**
 * The attendance score is a raw capped count, not a ratio: 1 point per
 * Present *regular* Xiiso session (Midterm/Final never count), out of a
 * fixed 10-session semester — e.g. Present in 9 of 10 regular sessions =
 * "9%", not 90%, and unaffected by how many sessions have been held so far.
 * Shared by every SQL/PHP site that computes an attendance percentage.
 */
const ATTENDANCE_MAX_SCORE = 10;

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
 * Whether at least one real course_offerings row exists for this
 * course+semester (any shift) — the correct validity/authorization
 * boundary now that a semester's faculty no longer has to match the
 * course's own catalog faculty (a course can be cross-listed into a
 * different faculty's semester track — see
 * migrations/2026_08_course_offerings_roster_department.sql). Replaces
 * the old get_course_faculty_id()-vs-semesters.faculty_id scalar
 * comparison, which assumed a course could only ever have offerings in
 * one faculty. get_course_faculty_id() itself is unchanged and still the
 * right answer to "what is this course's catalog home faculty" (used for
 * CRUD ownership, e.g. admin/courses.php's Dean-scoped list) — this is a
 * separate question: "is this specific course+semester pairing real."
 */
function course_offering_exists(mysqli $conn, int $courseId, int $semesterId): bool
{
    if ($courseId <= 0 || $semesterId <= 0) {
        return false;
    }

    $stmt = $conn->prepare('SELECT 1 FROM course_offerings WHERE course_id = ? AND semester_id = ? LIMIT 1');
    $stmt->bind_param('ii', $courseId, $semesterId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row !== null;
}

/**
 * Resolves which department's students should be used as the roster
 * fallback for a specific course+semester(+shift) offering, per that
 * offering's own roster_department_id (see
 * migrations/2026_08_course_offerings_roster_department.sql) — set
 * explicitly for a guest-faculty/cross-listed offering, since the
 * course's own catalog department would otherwise pull the wrong
 * faculty's students. Returns null when no matching offering row sets
 * one, so callers fall back to the course's own catalog department
 * exactly as before this column existed. When $shift is given, prefers
 * an exact-shift match over a coexisting 'any'-shift row, same
 * precedence as get_offering_summary().
 */
function resolve_roster_department_id(mysqli $conn, int $courseId, int $semesterId, ?string $shift = null): ?int
{
    if ($shift !== null && $shift !== '') {
        $stmt = $conn->prepare(
            "SELECT roster_department_id FROM course_offerings
             WHERE course_id = ? AND semester_id = ? AND (shift = ? OR shift = 'any') AND roster_department_id IS NOT NULL
             ORDER BY (shift = ?) DESC
             LIMIT 1"
        );
        $stmt->bind_param('iiss', $courseId, $semesterId, $shift, $shift);
    } else {
        $stmt = $conn->prepare(
            'SELECT roster_department_id FROM course_offerings
             WHERE course_id = ? AND semester_id = ? AND roster_department_id IS NOT NULL
             LIMIT 1'
        );
        $stmt->bind_param('ii', $courseId, $semesterId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['roster_department_id'] : null;
}

/**
 * Human labels for course_offerings.shift, including the 'any' sentinel
 * (meaning "applies to every shift") — shared by every file that renders
 * or collects an offering's shift. Local per-file convention (matches the
 * existing SHIFT_LABELS constants already duplicated across
 * attendance.php/admin/courses.php/etc. for students.shift, which only has
 * 3 values and stays separate from this one).
 */
const OFFERING_SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
    'any' => 'Any/All Shifts',
];

/**
 * The "who teaches this course, and when" summary for one specific
 * course+semester — now potentially MULTIPLE course_offerings rows (one
 * per shift), so this returns a list, not a single row. For display
 * alongside render_scope_breadcrumb() wherever a course+semester
 * combination is already on screen (attendance.php's roster/Grid View,
 * reports.php's Xiiso grid) — the breadcrumb itself only ever describes
 * Course/Department/Faculty/Semester/Academic Year, never who's actually
 * teaching or the offering's date range, so this is a separate lookup
 * rather than a change to that helper's contract.
 *
 * $shift: null (default) = no filter, return every offering row for this
 * course+semester (0, 1, or many — e.g. reports.php has no shift context).
 * A real shift value resolves to the single best-matching offering: an
 * exact shift match if one exists, else the 'any' wildcard row — a
 * specific-shift row and an 'any' row can legitimately coexist for the
 * same course+semester (e.g. a catch-all offering left in place after a
 * specific shift was added later), so this can't just filter on "either
 * matches" without risking two rows for what should read as one answer.
 *
 * @return array<int, array{lecturer_name: ?string, shift: string, start_date: ?string, end_date: ?string}>
 */
function get_offering_summary(mysqli $conn, int $courseId, int $semesterId, ?string $shift = null): array
{
    if ($courseId <= 0 || $semesterId <= 0) {
        return [];
    }

    if ($shift !== null) {
        $stmt = $conn->prepare(
            'SELECT l.full_name AS lecturer_name, co.shift, co.start_date, co.end_date
             FROM course_offerings co
             LEFT JOIN lecturers l ON l.id = co.lecturer_id
             WHERE co.course_id = ? AND co.semester_id = ? AND (co.shift = ? OR co.shift = \'any\')
             ORDER BY (co.shift = ?) DESC
             LIMIT 1'
        );
        $stmt->bind_param('iiss', $courseId, $semesterId, $shift, $shift);
    } else {
        $stmt = $conn->prepare(
            'SELECT l.full_name AS lecturer_name, co.shift, co.start_date, co.end_date
             FROM course_offerings co
             LEFT JOIN lecturers l ON l.id = co.lecturer_id
             WHERE co.course_id = ? AND co.semester_id = ?'
        );
        $stmt->bind_param('ii', $courseId, $semesterId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * Renders get_offering_summary()'s result as the same small muted-text
 * line style used elsewhere (e.g. render_scope_breadcrumb()) — a no-op
 * (empty string) when there are no offering rows yet, so callers can use
 * it unconditionally right after a breadcrumb call. One line per offering
 * when there's more than one (e.g. "Morning: John Doe" / "Afternoon:
 * Unassigned"); the shift label is omitted when there's only a single
 * 'any'-shift offering, since naming "Any/All Shifts" explicitly adds
 * nothing for the common single-offering case.
 */
function render_offering_summary(array $offerings): string
{
    if (empty($offerings)) {
        return '';
    }

    $lines = [];
    $singleAny = count($offerings) === 1 && $offerings[0]['shift'] === 'any';
    foreach ($offerings as $offering) {
        $lecturer = $offering['lecturer_name'] ?: 'Unassigned';
        $dates = '';
        if ($offering['start_date'] || $offering['end_date']) {
            $dates = ' <span class="mx-1">&middot;</span> ' . htmlspecialchars(($offering['start_date'] ?? '?') . ' to ' . ($offering['end_date'] ?? '?'));
        }
        $prefix = $singleAny ? '' : htmlspecialchars(OFFERING_SHIFT_LABELS[$offering['shift']] ?? $offering['shift']) . ': ';
        $lines[] = $prefix . htmlspecialchars($lecturer) . $dates;
    }

    return '<div class="text-muted small mb-2"><i class="bi bi-person-badge"></i> '
        . implode('<br>', $lines) . '</div>';
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
 * already used by attendance.php/reports.php (university_rector: any course;
 * dean: only an offering that actually exists inside their own faculty's
 * semester track — via course_offerings/semesters.faculty_id, not the
 * course's own catalog department, so a Dean can write a cross-listed
 * guest-faculty offering in their faculty even when the course's catalog
 * home is elsewhere, per the Multi-Faculty Course Offerings plan; lecturer:
 * only courses they're currently assigned to teach, via course_offerings —
 * not the deprecated permanent courses.lecturer_id). Used by the AJAX save endpoint, which has
 * no server-rendered $courseById allowlist to lean on the way the
 * page-load flow does — this is that endpoint's actual security boundary.
 * $semesterId must be the *current* semester for this course's own
 * faculty (see get_current_semester()) — a lecturer's write access is
 * scoped to their current-semester offering, so being unassigned for a
 * later semester automatically revokes it, rather than lasting forever.
 *
 * $shift: since a course can now have one offering per shift within the
 * same semester, a lecturer assigned to only Morning must NOT be able to
 * write Afternoon students' attendance. Pass the shift of the specific
 * student/roster being written to (ajax/save_attendance_cell.php always
 * has a real value here — the student's own shift). null (default) skips
 * the shift check entirely (any offering row for this lecturer counts) —
 * used only where there's no specific shift in context yet (e.g.
 * attendance.php's page-level "can write" flag when no Shift filter is
 * selected — a UX convenience, not the real security boundary).
 */
function user_can_write_course_attendance(mysqli $conn, string $role, array $currentUser, int $courseId, int $semesterId, ?string $shift = null): bool
{
    // University Rector is a supervisory/oversight role — full VIEW access
    // everywhere, but never write access to attendance (or any other
    // day-to-day academic data). Previously this returned true (full CRUD,
    // matching the role's original "system_admin" scope); now it's always
    // denied, same as any role with no write authorization for this course.
    if ($role === 'university_rector') {
        return false;
    }

    // Dean converted from full CRUD to a faculty-scoped Viewer, per
    // explicit request (same conversion University Rector already had) —
    // never write access to attendance, regardless of faculty.
    if ($role === 'dean') {
        return false;
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

        if ($shift !== null) {
            $stmt = $conn->prepare('SELECT 1 FROM course_offerings WHERE course_id = ? AND semester_id = ? AND lecturer_id = ? AND (shift = ? OR shift = \'any\')');
            $stmt->bind_param('iiis', $courseId, $semesterId, $lecturerRecordId, $shift);
        } else {
            $stmt = $conn->prepare('SELECT 1 FROM course_offerings WHERE course_id = ? AND semester_id = ? AND lecturer_id = ?');
            $stmt->bind_param('iii', $courseId, $semesterId, $lecturerRecordId);
        }
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
 *
 * $shift is optional (null = every shift, unchanged from before this
 * parameter existed — reports.php doesn't pass it) — attendance.php's Grid
 * View passes the admin/lecturer's own Shift filter selection so a
 * department with multiple shifts sharing one course doesn't show them all
 * mixed into one roster.
 */
function get_xiiso_grid_data(mysqli $conn, int $courseId, int $semesterId, ?string $shift = null): array
{
    $sessions = get_sessions_for_semester($conn, $semesterId);

    if ($shift !== null && $shift !== '') {
        $stmt = $conn->prepare(
            "SELECT s.id, s.student_no, s.full_name, u.photo_path
             FROM course_enrollments ce
             JOIN students s ON s.id = ce.student_id
             LEFT JOIN users u ON u.id = s.user_id
             WHERE ce.course_id = ? AND s.status = 'active' AND s.shift = ?
             ORDER BY s.student_no"
        );
        $stmt->bind_param('is', $courseId, $shift);
    } else {
        $stmt = $conn->prepare(
            "SELECT s.id, s.student_no, s.full_name, u.photo_path
             FROM course_enrollments ce
             JOIN students s ON s.id = ce.student_id
             LEFT JOIN users u ON u.id = s.user_id
             WHERE ce.course_id = ? AND s.status = 'active'
             ORDER BY s.student_no"
        );
        $stmt->bind_param('i', $courseId);
    }
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($students)) {
        // Guest-faculty offerings set their own roster_department_id (the
        // course's own catalog department lives in the WRONG faculty for
        // those); fall back to the course's own department only when no
        // offering row sets one — unchanged default behavior.
        $rosterDepartmentId = resolve_roster_department_id($conn, $courseId, $semesterId, $shift);

        if ($rosterDepartmentId !== null) {
            if ($shift !== null && $shift !== '') {
                $stmt = $conn->prepare(
                    "SELECT s.id, s.student_no, s.full_name, u.photo_path FROM students s
                     LEFT JOIN users u ON u.id = s.user_id
                     WHERE s.department_id = ? AND s.status = 'active' AND s.shift = ?
                     ORDER BY s.student_no"
                );
                $stmt->bind_param('is', $rosterDepartmentId, $shift);
            } else {
                $stmt = $conn->prepare(
                    "SELECT s.id, s.student_no, s.full_name, u.photo_path FROM students s
                     LEFT JOIN users u ON u.id = s.user_id
                     WHERE s.department_id = ? AND s.status = 'active'
                     ORDER BY s.student_no"
                );
                $stmt->bind_param('i', $rosterDepartmentId);
            }
        } elseif ($shift !== null && $shift !== '') {
            $stmt = $conn->prepare(
                "SELECT s.id, s.student_no, s.full_name, u.photo_path
                 FROM students s
                 JOIN courses c ON c.department_id = s.department_id
                 LEFT JOIN users u ON u.id = s.user_id
                 WHERE c.id = ? AND s.status = 'active' AND s.shift = ?
                 ORDER BY s.student_no"
            );
            $stmt->bind_param('is', $courseId, $shift);
        } else {
            $stmt = $conn->prepare(
                "SELECT s.id, s.student_no, s.full_name, u.photo_path
                 FROM students s
                 JOIN courses c ON c.department_id = s.department_id
                 LEFT JOIN users u ON u.id = s.user_id
                 WHERE c.id = ? AND s.status = 'active'
                 ORDER BY s.student_no"
            );
            $stmt->bind_param('i', $courseId);
        }
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
 * Real roster — the student ids for a (course, semester[, shift])
 * combination — the exact same enrollment-then-department-fallback
 * resolution get_xiiso_grid_data() uses to build the actual grid, kept in
 * sync on purpose so a "Students" count shown anywhere else (e.g.
 * lecturer/courses.php's or lecturer/dashboard.php's summary tables) never
 * disagrees with what the Grid View itself would show for that same
 * course. A bare `course_enrollments` count alone understates this
 * whenever a course has no explicit enrollment rows yet and is only ever
 * reachable via the department-roster fallback.
 *
 * @return int[] student ids
 */
function get_course_roster_student_ids(mysqli $conn, int $courseId, int $semesterId, ?string $shift = null): array
{
    // When resolving the roster for the 'any' (catch-all) offering, a
    // student whose own shift already has its OWN dedicated offering for
    // this exact (course, semester) must be excluded here — otherwise that
    // student would be counted under both the 'any' offering AND their
    // specific-shift offering at once (double-counted "Enrolled Students").
    // This mirrors the exact-shift-wins-over-'any' precedence already used
    // by get_offering_summary(). No-op (empty exclusion set) for the
    // ordinary single-offering-per-course case.
    $excludedShifts = [];
    if (($shift === null || $shift === '') && $semesterId > 0) {
        $shiftStmt = $conn->prepare(
            "SELECT DISTINCT shift FROM course_offerings WHERE course_id = ? AND semester_id = ? AND shift != 'any'"
        );
        $shiftStmt->bind_param('ii', $courseId, $semesterId);
        $shiftStmt->execute();
        $excludedShifts = array_map(static fn ($r) => (string) $r['shift'], $shiftStmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $shiftStmt->close();
    }
    $excludedPlaceholders = !empty($excludedShifts) ? implode(',', array_fill(0, count($excludedShifts), '?')) : '';

    if ($shift !== null && $shift !== '') {
        $stmt = $conn->prepare(
            "SELECT s.id FROM course_enrollments ce
             JOIN students s ON s.id = ce.student_id
             WHERE ce.course_id = ? AND s.status = 'active' AND s.shift = ?"
        );
        $stmt->bind_param('is', $courseId, $shift);
    } elseif ($excludedPlaceholders !== '') {
        $stmt = $conn->prepare(
            "SELECT s.id FROM course_enrollments ce
             JOIN students s ON s.id = ce.student_id
             WHERE ce.course_id = ? AND s.status = 'active' AND s.shift NOT IN ({$excludedPlaceholders})"
        );
        $stmt->bind_param('i' . str_repeat('s', count($excludedShifts)), $courseId, ...$excludedShifts);
    } else {
        $stmt = $conn->prepare(
            "SELECT s.id FROM course_enrollments ce
             JOIN students s ON s.id = ce.student_id
             WHERE ce.course_id = ? AND s.status = 'active'"
        );
        $stmt->bind_param('i', $courseId);
    }
    $stmt->execute();
    $ids = array_map(static fn ($r) => (int) $r['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    if (!empty($ids)) {
        return $ids;
    }

    $rosterDepartmentId = resolve_roster_department_id($conn, $courseId, $semesterId, $shift);
    if ($rosterDepartmentId !== null) {
        if ($shift !== null && $shift !== '') {
            $stmt = $conn->prepare("SELECT id FROM students WHERE department_id = ? AND status = 'active' AND shift = ?");
            $stmt->bind_param('is', $rosterDepartmentId, $shift);
        } elseif ($excludedPlaceholders !== '') {
            $stmt = $conn->prepare("SELECT id FROM students WHERE department_id = ? AND status = 'active' AND shift NOT IN ({$excludedPlaceholders})");
            $stmt->bind_param('i' . str_repeat('s', count($excludedShifts)), $rosterDepartmentId, ...$excludedShifts);
        } else {
            $stmt = $conn->prepare("SELECT id FROM students WHERE department_id = ? AND status = 'active'");
            $stmt->bind_param('i', $rosterDepartmentId);
        }
    } elseif ($shift !== null && $shift !== '') {
        $stmt = $conn->prepare(
            "SELECT s.id FROM students s JOIN courses c ON c.department_id = s.department_id
             WHERE c.id = ? AND s.status = 'active' AND s.shift = ?"
        );
        $stmt->bind_param('is', $courseId, $shift);
    } elseif ($excludedPlaceholders !== '') {
        $stmt = $conn->prepare(
            "SELECT s.id FROM students s JOIN courses c ON c.department_id = s.department_id
             WHERE c.id = ? AND s.status = 'active' AND s.shift NOT IN ({$excludedPlaceholders})"
        );
        $stmt->bind_param('i' . str_repeat('s', count($excludedShifts)), $courseId, ...$excludedShifts);
    } else {
        $stmt = $conn->prepare(
            "SELECT s.id FROM students s JOIN courses c ON c.department_id = s.department_id
             WHERE c.id = ? AND s.status = 'active'"
        );
        $stmt->bind_param('i', $courseId);
    }
    $stmt->execute();
    $ids = array_map(static fn ($r) => (int) $r['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    return $ids;
}

/**
 * Real roster SIZE for a (course, semester[, shift]) combination — see
 * get_course_roster_student_ids() above (this is just its count()).
 */
function get_course_roster_count(mysqli $conn, int $courseId, int $semesterId, ?string $shift = null): int
{
    return count(get_course_roster_student_ids($conn, $courseId, $semesterId, $shift));
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

/**
 * Groups a semester's sessions (as returned by get_sessions_for_semester(),
 * already ordered by session_number) into fixed-size bands by position
 * (Xiiso 1-4, 5-8, 9-12), independent of each session's calendar date —
 * used to add a sky-blue divider every 4 Xiiso columns on the grid views,
 * matching the university's own paper/Excel tracker's banded layout.
 */
/**
 * A (course_id, session_id) pair is only ever a legitimate Lecturer
 * Check-In/Check-Out target if it belongs to one of this lecturer's own
 * CURRENT-semester offerings — the real security/correctness boundary for
 * both the check_in and check_out actions, shared by lecturer/checkin.php
 * (page load + no-JS form fallback) and ajax/lecturer_checkin_action.php
 * (the AJAX path), so the two can never drift on what counts as "owns this
 * session."
 */
function lecturer_owns_current_session(mysqli $conn, int $lecturerId, int $courseId, int $sessionId): bool
{
    $stmt = $conn->prepare(
        "SELECT sess.id
         FROM sessions sess
         JOIN semesters se ON se.id = sess.semester_id AND se.status = 'current'
         JOIN course_offerings co ON co.semester_id = se.id AND co.course_id = ? AND co.lecturer_id = ?
         WHERE sess.id = ?"
    );
    $stmt->bind_param('iii', $courseId, $lecturerId, $sessionId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $found !== null;
}

function build_xiiso_chunks(array $sessions, int $chunkSize = 4): array
{
    $chunks = [];

    foreach (array_values($sessions) as $index => $session) {
        $chunkIndex = intdiv($index, $chunkSize);
        if (!isset($chunks[$chunkIndex])) {
            $startNumber = $chunkIndex * $chunkSize + 1;
            $endNumber = $startNumber + $chunkSize - 1;
            $chunks[$chunkIndex] = [
                'label' => 'Xiiso ' . $startNumber . '–' . $endNumber,
                'span' => 0,
                'session_ids' => [],
            ];
        }

        $chunks[$chunkIndex]['span']++;
        $chunks[$chunkIndex]['session_ids'][] = (int) $session['id'];
    }

    return array_values($chunks);
}
