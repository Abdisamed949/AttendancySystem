<?php
/**
 * Reports screen — shared by University Rector, Head of Academic Affairs,
 * Dean, Registration Office, and Lecturer, each scoped to a different slice
 * of the data (same scoping pattern as attendance.php). Lives at the app
 * root because it is reused by five roles rather than owned by one folder.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/semester_helpers.php';
require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/includes/avatar_helpers.php';
require_once __DIR__ . '/includes/university_logo.php';
require_once __DIR__ . '/vendor/autoload.php';

require_role(['university_rector', 'head_academic', 'dean', 'registration', 'lecturer']);

use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

const REPORT_TYPE_LABELS = [
    'course_attendance' => 'Course Attendance Summary',
    'department_summary' => 'Department Summary',
    'faculty_summary' => 'Faculty Summary',
    'xiiso_grid' => 'Xiiso Attendance Grid',
    'at_risk_students' => 'At-Risk Students',
    'lecturer_recording_rate' => 'Lecturer Recording Rate',
];

const REPORT_TYPES_BY_ROLE = [
    // At-Risk Students / Lecturer Recording Rate share the exact same
    // Faculty/Department/Semester filter bar as course_attendance below —
    // no new UI/JS was needed to add them, only these two entries plus a
    // builder function and a match() arm — so their audience mirrors
    // notifications.php's own (the other "catch it before it's official"
    // surface): university_rector/head_academic/dean, not lecturer
    // (about their colleagues, not their own record) or registration (no
    // attendance access at all).
    'university_rector' => ['course_attendance', 'department_summary', 'faculty_summary', 'xiiso_grid', 'at_risk_students', 'lecturer_recording_rate'],
    'head_academic' => ['course_attendance', 'department_summary', 'faculty_summary', 'xiiso_grid', 'at_risk_students', 'lecturer_recording_rate'],
    'dean' => ['course_attendance', 'department_summary', 'xiiso_grid', 'at_risk_students', 'lecturer_recording_rate'],
    'registration' => ['department_summary', 'faculty_summary'],
    'lecturer' => ['course_attendance', 'xiiso_grid'],
];

// Same 3-value student-shift set already used by attendance.php/students.php
// etc. — the Xiiso Attendance Grid report's own optional Shift filter, so a
// course with more than one shift's roster (Multi-Shift Course Offerings)
// can be viewed one shift at a time here too, matching attendance.php's own
// Grid View.
const SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

// ---------------------------------------------------------------------
// Report data builders — one function per report type. Each returns
// [$columns, $rows] where $columns is a list of ['key' => .., 'label' => ..]
// and $rows is a list of assoc arrays keyed the same way, so the HTML
// table, the Excel export, and the PDF export can all share one render
// loop instead of three separate hand-written tables.
// ---------------------------------------------------------------------

/**
 * Per-student attendance score for a semester: LEAST(10, SUM(present)),
 * counting only *regular* sessions (Midterm/Final never count) — see
 * ATTENDANCE_MAX_SCORE in includes/attendance_helpers.php. Shared derived-
 * table shape reused by all three summary report builders below, each
 * rolling it up to its own dimension (course / department / faculty).
 */
function attendance_score_subquery(): string
{
    return "SELECT a.student_id, a.course_id,
                    LEAST(10, SUM(a.status = 'present')) AS present_score,
                    LEAST(10, SUM(a.status = 'absent')) AS absent_score
             FROM attendance a
             JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
             WHERE sess.semester_id = ?";
}

function build_course_attendance_report(mysqli $conn, string $role, int $facultyId, int $departmentId, int $lecturerRecordId, int $semesterId): array
{
    $conditions = [];
    $params = [$semesterId, $semesterId];
    $types = 'ii';

    if ($role === 'lecturer') {
        // Reports are historical — a lecturer can report on any course
        // they've ever been assigned to teach (any offering, any
        // semester), not just their current one.
        $conditions[] = 'EXISTS (SELECT 1 FROM course_offerings co WHERE co.course_id = c.id AND co.lecturer_id = ?)';
        $params[] = $lecturerRecordId;
        $types .= 'i';
    } else {
        if ($facultyId > 0) {
            $conditions[] = 'd.faculty_id = ?';
            $params[] = $facultyId;
            $types .= 'i';
        }
        if ($departmentId > 0) {
            $conditions[] = 'c.department_id = ?';
            $params[] = $departmentId;
            $types .= 'i';
        }
    }

    $whereExtra = empty($conditions) ? '' : (' AND ' . implode(' AND ', $conditions));
    $scoreSql = attendance_score_subquery();

    $sql = "SELECT c.code, c.name, d.name AS department_name, f.name AS faculty_name,
                   COUNT(DISTINCT t.student_id) AS student_count,
                   COALESCE(ROUND(AVG(t.present_score), 1), 0) AS avg_present_pct,
                   COALESCE(ROUND(AVG(t.absent_score), 1), 0) AS avg_absent_pct,
                   COALESCE(MAX(u.sessions_recorded), 0) AS sessions_recorded
            FROM courses c
            JOIN departments d ON d.id = c.department_id
            JOIN faculties f ON f.id = d.faculty_id
            LEFT JOIN ({$scoreSql} GROUP BY a.student_id, a.course_id) t ON t.course_id = c.id
            LEFT JOIN (
                SELECT a.course_id, COUNT(DISTINCT a.session_id) AS sessions_recorded
                FROM attendance a
                JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
                WHERE sess.semester_id = ?
                GROUP BY a.course_id
            ) u ON u.course_id = c.id
            WHERE 1 = 1{$whereExtra}
            GROUP BY c.id, c.code, c.name, d.name, f.name
            ORDER BY f.name, d.name, c.code";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $columns = [
        ['key' => 'course', 'label' => 'Course'],
        ['key' => 'department', 'label' => 'Department'],
        ['key' => 'faculty', 'label' => 'Faculty'],
        ['key' => 'sessions_recorded', 'label' => 'Sessions Recorded (of 10)'],
        ['key' => 'avg_present_pct', 'label' => 'Avg Attendance (of 10)'],
        ['key' => 'avg_absent_pct', 'label' => 'Avg Absent (of 10)'],
    ];

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'course' => $r['code'] . ' — ' . $r['name'],
            'department' => $r['department_name'],
            'faculty' => $r['faculty_name'],
            'sessions_recorded' => (int) $r['sessions_recorded'],
            'avg_present_pct' => (float) $r['avg_present_pct'],
            'avg_absent_pct' => (float) $r['avg_absent_pct'],
        ];
    }

    return [$columns, $data];
}

/**
 * Students who are NOT yet below the attendance threshold, but sit close
 * enough to it (within a 2-point buffer above it, out of 10) that they're
 * worth flagging before notifications.php's own below-threshold alert ever
 * fires. Ranked closest-to-the-line first. "Sessions Remaining" is the
 * course's own regular-session count still unmarked for this semester —
 * context for how much room is left to recover (or to fall further).
 */
function build_at_risk_students_report(mysqli $conn, int $facultyId, int $departmentId, int $semesterId, float $minAttendancePct): array
{
    $conditions = [];
    $params = [$semesterId, $semesterId, $semesterId, $minAttendancePct, $minAttendancePct + 2];
    $types = 'iiidd';

    if ($facultyId > 0) {
        $conditions[] = 'f.id = ?';
        $params[] = $facultyId;
        $types .= 'i';
    }
    if ($departmentId > 0) {
        $conditions[] = 'c.department_id = ?';
        $params[] = $departmentId;
        $types .= 'i';
    }

    $whereExtra = empty($conditions) ? '' : (' AND ' . implode(' AND ', $conditions));
    $scoreSql = attendance_score_subquery();

    $sql = "SELECT s.student_no, s.full_name, u.photo_path, c.code, c.name AS course_name,
                   d.name AS department_name, f.name AS faculty_name, t.present_score AS score,
                   GREATEST(0, (SELECT COUNT(*) FROM sessions sx WHERE sx.semester_id = ? AND sx.type = 'regular')
                       - COALESCE(rec.recorded_count, 0)) AS sessions_remaining
            FROM ({$scoreSql} GROUP BY a.student_id, a.course_id) t
            JOIN students s ON s.id = t.student_id
            JOIN users u ON u.id = s.user_id
            JOIN courses c ON c.id = t.course_id
            JOIN departments d ON d.id = c.department_id
            JOIN faculties f ON f.id = d.faculty_id
            LEFT JOIN (
                SELECT a.course_id, COUNT(DISTINCT a.session_id) AS recorded_count
                FROM attendance a
                JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
                WHERE sess.semester_id = ?
                GROUP BY a.course_id
            ) rec ON rec.course_id = c.id
            WHERE t.present_score >= ? AND t.present_score < ?{$whereExtra}
            ORDER BY t.present_score ASC, sessions_remaining ASC, s.full_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $columns = [
        ['key' => 'student_no', 'label' => 'Student No'],
        ['key' => 'full_name', 'label' => 'Full Name'],
        ['key' => 'course', 'label' => 'Course'],
        ['key' => 'department', 'label' => 'Department'],
        ['key' => 'faculty', 'label' => 'Faculty'],
        ['key' => 'score', 'label' => 'Score (of 10)'],
        ['key' => 'sessions_remaining', 'label' => 'Sessions Remaining'],
    ];

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'student_no' => $r['student_no'],
            'full_name' => $r['full_name'],
            'photo_path' => $r['photo_path'],
            'course' => $r['code'] . ' — ' . $r['course_name'],
            'department' => $r['department_name'],
            'faculty' => $r['faculty_name'],
            'score' => (int) $r['score'],
            'sessions_remaining' => (int) $r['sessions_remaining'],
        ];
    }

    return [$columns, $data];
}

/**
 * Of the regular sessions a semester actually expects, how many has each
 * lecturer holding a real course_offerings row that semester actually
 * marked attendance for — a per-(lecturer, course) pair, deduplicated so a
 * lecturer holding two shift-offerings of the same course is never counted
 * twice. Ranked lowest-first, so the lecturer most behind on recording
 * shows at the top.
 */
function build_lecturer_recording_rate_report(mysqli $conn, int $facultyId, int $departmentId, int $semesterId): array
{
    $conditions = [];
    $params = [$semesterId, $semesterId];
    $types = 'ii';

    if ($facultyId > 0) {
        $conditions[] = 'd2.faculty_id = ?';
        $params[] = $facultyId;
        $types .= 'i';
    }
    if ($departmentId > 0) {
        $conditions[] = 'c2.department_id = ?';
        $params[] = $departmentId;
        $types .= 'i';
    }
    $whereExtra = empty($conditions) ? '' : (' AND ' . implode(' AND ', $conditions));

    $sql = "SELECT lc.lecturer_id, l.staff_no, l.full_name,
                   COUNT(*) AS course_count,
                   SUM(COALESCE(rec.recorded_count, 0)) AS total_recorded
            FROM (SELECT DISTINCT co.lecturer_id, co.course_id FROM course_offerings co WHERE co.semester_id = ? AND co.lecturer_id IS NOT NULL) lc
            JOIN lecturers l ON l.id = lc.lecturer_id
            JOIN courses c2 ON c2.id = lc.course_id
            JOIN departments d2 ON d2.id = c2.department_id
            LEFT JOIN (
                SELECT a.course_id, COUNT(DISTINCT a.session_id) AS recorded_count
                FROM attendance a
                JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
                WHERE sess.semester_id = ?
                GROUP BY a.course_id
            ) rec ON rec.course_id = lc.course_id
            WHERE 1 = 1{$whereExtra}
            GROUP BY lc.lecturer_id, l.staff_no, l.full_name
            ORDER BY total_recorded ASC, l.full_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $columns = [
        ['key' => 'staff_no', 'label' => 'Staff No'],
        ['key' => 'full_name', 'label' => 'Full Name'],
        ['key' => 'course_count', 'label' => 'Courses This Semester'],
        ['key' => 'total_recorded', 'label' => 'Sessions Recorded'],
        ['key' => 'total_expected', 'label' => 'Sessions Expected'],
        ['key' => 'recording_rate', 'label' => 'Recording Rate'],
    ];

    $data = [];
    foreach ($rows as $r) {
        $courseCount = (int) $r['course_count'];
        $expected = $courseCount * ATTENDANCE_MAX_SCORE;
        $recorded = (int) $r['total_recorded'];
        $rate = $expected > 0 ? round(($recorded / $expected) * 100, 1) : 0.0;
        $data[] = [
            'staff_no' => $r['staff_no'],
            'full_name' => $r['full_name'],
            'course_count' => $courseCount,
            'total_recorded' => $recorded,
            'total_expected' => $expected,
            'recording_rate' => $rate . '%',
        ];
    }

    return [$columns, $data];
}

function build_department_summary_report(mysqli $conn, string $role, int $facultyId, int $departmentId, int $semesterId): array
{
    $conditions = [];
    $params = [];
    $types = '';
    if ($facultyId > 0) {
        $conditions[] = 'd.faculty_id = ?';
        $params[] = $facultyId;
        $types .= 'i';
    }
    if ($departmentId > 0) {
        $conditions[] = 'd.id = ?';
        $params[] = $departmentId;
        $types .= 'i';
    }
    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $sql = "SELECT d.id, d.name AS department_name, f.name AS faculty_name
            FROM departments d
            JOIN faculties f ON f.id = d.faculty_id
            {$whereSql}
            ORDER BY f.name, d.name";
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $departments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $courseCounts = [];
    $res = $conn->query('SELECT department_id, COUNT(*) AS c FROM courses GROUP BY department_id');
    while ($row = $res->fetch_assoc()) {
        $courseCounts[(int) $row['department_id']] = (int) $row['c'];
    }

    $studentCounts = [];
    $res = $conn->query("SELECT department_id, COUNT(*) AS c FROM students WHERE status = 'active' GROUP BY department_id");
    while ($row = $res->fetch_assoc()) {
        $studentCounts[(int) $row['department_id']] = (int) $row['c'];
    }

    // Registration has no Attendance access (see CLAUDE.md §4), so its
    // Department Summary shows enrollment counts instead of attendance %.
    $includeAttendance = $role !== 'registration';

    $attendanceByDept = [];
    $enrollmentsByDept = [];
    if ($includeAttendance) {
        $scoreSql = attendance_score_subquery();
        $attStmt = $conn->prepare(
            "SELECT c.department_id,
                    COUNT(DISTINCT t.student_id) AS student_count,
                    ROUND(AVG(t.present_score), 1) AS avg_present_pct,
                    ROUND(AVG(t.absent_score), 1) AS avg_absent_pct
             FROM ({$scoreSql} GROUP BY a.student_id, a.course_id) t
             JOIN courses c ON c.id = t.course_id
             GROUP BY c.department_id"
        );
        $attStmt->bind_param('i', $semesterId);
        $attStmt->execute();
        $attRes = $attStmt->get_result();
        while ($row = $attRes->fetch_assoc()) {
            $attendanceByDept[(int) $row['department_id']] = $row;
        }
        $attStmt->close();
    } else {
        $res = $conn->query(
            "SELECT c.department_id, COUNT(*) AS c
             FROM course_enrollments ce
             JOIN courses c ON c.id = ce.course_id
             GROUP BY c.department_id"
        );
        while ($row = $res->fetch_assoc()) {
            $enrollmentsByDept[(int) $row['department_id']] = (int) $row['c'];
        }
    }

    $columns = [
        ['key' => 'department', 'label' => 'Department'],
        ['key' => 'faculty', 'label' => 'Faculty'],
        ['key' => 'total_courses', 'label' => 'Total Courses'],
        ['key' => 'total_students', 'label' => 'Total Students'],
    ];
    if ($includeAttendance) {
        $columns[] = ['key' => 'avg_present_pct', 'label' => 'Avg Attendance (of 10)'];
        $columns[] = ['key' => 'avg_absent_pct', 'label' => 'Avg Absent (of 10)'];
    } else {
        $columns[] = ['key' => 'total_enrollments', 'label' => 'Total Enrollments'];
    }

    $data = [];
    foreach ($departments as $d) {
        $deptId = (int) $d['id'];
        $row = [
            'department' => $d['department_name'],
            'faculty' => $d['faculty_name'],
            'total_courses' => $courseCounts[$deptId] ?? 0,
            'total_students' => $studentCounts[$deptId] ?? 0,
        ];
        if ($includeAttendance) {
            $att = $attendanceByDept[$deptId] ?? null;
            $row['avg_present_pct'] = (float) ($att['avg_present_pct'] ?? 0);
            $row['avg_absent_pct'] = (float) ($att['avg_absent_pct'] ?? 0);
        } else {
            $row['total_enrollments'] = $enrollmentsByDept[$deptId] ?? 0;
        }
        $data[] = $row;
    }

    return [$columns, $data];
}

function build_faculty_summary_report(mysqli $conn, string $role, int $facultyId, int $semesterId): array
{
    $conditions = [];
    $params = [];
    $types = '';
    if ($facultyId > 0) {
        $conditions[] = 'f.id = ?';
        $params[] = $facultyId;
        $types .= 'i';
    }
    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $sql = "SELECT f.id, f.name AS faculty_name FROM faculties f {$whereSql} ORDER BY f.name";
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $faculties = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $deptCounts = [];
    $res = $conn->query('SELECT faculty_id, COUNT(*) AS c FROM departments GROUP BY faculty_id');
    while ($row = $res->fetch_assoc()) {
        $deptCounts[(int) $row['faculty_id']] = (int) $row['c'];
    }

    $courseCounts = [];
    $res = $conn->query('SELECT d.faculty_id, COUNT(*) AS c FROM courses c2 JOIN departments d ON d.id = c2.department_id GROUP BY d.faculty_id');
    while ($row = $res->fetch_assoc()) {
        $courseCounts[(int) $row['faculty_id']] = (int) $row['c'];
    }

    $studentCounts = [];
    $res = $conn->query("SELECT faculty_id, COUNT(*) AS c FROM students WHERE status = 'active' GROUP BY faculty_id");
    while ($row = $res->fetch_assoc()) {
        $studentCounts[(int) $row['faculty_id']] = (int) $row['c'];
    }

    $includeAttendance = $role !== 'registration';

    $attendanceByFaculty = [];
    $enrollmentsByFaculty = [];
    if ($includeAttendance) {
        $scoreSql = attendance_score_subquery();
        $attStmt = $conn->prepare(
            "SELECT d.faculty_id,
                    COUNT(DISTINCT t.student_id) AS student_count,
                    ROUND(AVG(t.present_score), 1) AS avg_present_pct,
                    ROUND(AVG(t.absent_score), 1) AS avg_absent_pct
             FROM ({$scoreSql} GROUP BY a.student_id, a.course_id) t
             JOIN courses c ON c.id = t.course_id
             JOIN departments d ON d.id = c.department_id
             GROUP BY d.faculty_id"
        );
        $attStmt->bind_param('i', $semesterId);
        $attStmt->execute();
        $attRes = $attStmt->get_result();
        while ($row = $attRes->fetch_assoc()) {
            $attendanceByFaculty[(int) $row['faculty_id']] = $row;
        }
        $attStmt->close();
    } else {
        $res = $conn->query(
            "SELECT d.faculty_id, COUNT(*) AS c
             FROM course_enrollments ce
             JOIN courses c ON c.id = ce.course_id
             JOIN departments d ON d.id = c.department_id
             GROUP BY d.faculty_id"
        );
        while ($row = $res->fetch_assoc()) {
            $enrollmentsByFaculty[(int) $row['faculty_id']] = (int) $row['c'];
        }
    }

    $columns = [
        ['key' => 'faculty', 'label' => 'Faculty'],
        ['key' => 'total_departments', 'label' => 'Total Departments'],
        ['key' => 'total_courses', 'label' => 'Total Courses'],
        ['key' => 'total_students', 'label' => 'Total Students'],
    ];
    if ($includeAttendance) {
        $columns[] = ['key' => 'avg_present_pct', 'label' => 'Avg Attendance (of 10)'];
        $columns[] = ['key' => 'avg_absent_pct', 'label' => 'Avg Absent (of 10)'];
    } else {
        $columns[] = ['key' => 'total_enrollments', 'label' => 'Total Enrollments'];
    }

    $data = [];
    foreach ($faculties as $f) {
        $fid = (int) $f['id'];
        $row = [
            'faculty' => $f['faculty_name'],
            'total_departments' => $deptCounts[$fid] ?? 0,
            'total_courses' => $courseCounts[$fid] ?? 0,
            'total_students' => $studentCounts[$fid] ?? 0,
        ];
        if ($includeAttendance) {
            $att = $attendanceByFaculty[$fid] ?? null;
            $row['avg_present_pct'] = (float) ($att['avg_present_pct'] ?? 0);
            $row['avg_absent_pct'] = (float) ($att['avg_absent_pct'] ?? 0);
        } else {
            $row['total_enrollments'] = $enrollmentsByFaculty[$fid] ?? 0;
        }
        $data[] = $row;
    }

    return [$columns, $data];
}

/**
 * Reproduces the lecturer's paper/Excel grid: one row per student enrolled
 * in the course, one column per Xiiso (1-12) for the given semester, plus
 * auto-computed P (present count) / A (absent count) / % trailing columns.
 * Cell values: '1' present, '0' absent, '' unmarked.
 */
function build_xiiso_grid_report(mysqli $conn, int $courseId, int $semesterId, ?string $shift = null): array
{
    $gridData = get_xiiso_grid_data($conn, $courseId, $semesterId, $shift);
    $sessions = $gridData['sessions'];
    $students = $gridData['students'];
    $marksByStudentSession = $gridData['marks'];

    $columns = [
        ['key' => 'student_no', 'label' => 'Student No', 'header_accent' => true],
        ['key' => 'full_name', 'label' => 'Full Name', 'group_end' => true, 'header_accent' => true],
    ];
    // Every 4th Xiiso column gets a sky-blue divider (build_xiiso_chunks()),
    // matching the university's own paper/Excel tracker's banded layout.
    $sessionIdsAtChunkEnd = [];
    foreach (build_xiiso_chunks($sessions) as $chunk) {
        if (!empty($chunk['session_ids'])) {
            $sessionIdsAtChunkEnd[end($chunk['session_ids'])] = true;
        }
    }
    foreach ($sessions as $s) {
        $columns[] = [
            'key' => 'session_' . $s['id'],
            'label' => $s['label'],
            'group_end' => isset($sessionIdsAtChunkEnd[(int) $s['id']]),
            // Midterm/Final are exams, not attendance sessions — greyed out
            // and excluded from the P/A/% score below.
            'exam' => $s['type'] !== 'regular',
        ];
    }
    $columns[] = ['key' => 'present_count', 'label' => 'P', 'group_end' => true, 'summary' => true];
    $columns[] = ['key' => 'absent_count', 'label' => 'A', 'group_end' => true, 'summary' => true];
    $columns[] = ['key' => 'attendance_pct', 'label' => '%', 'summary' => true];

    $data = [];
    foreach ($students as $st) {
        $sid = (int) $st['id'];
        $row = ['student_no' => $st['student_no'], 'full_name' => $st['full_name'], 'photo_path' => $st['photo_path'] ?? null];

        $presentCount = 0;
        $absentCount = 0;
        foreach ($sessions as $s) {
            $status = $marksByStudentSession[$sid][(int) $s['id']] ?? null;
            $row['session_' . $s['id']] = match ($status) {
                'present' => '1',
                'absent' => '0',
                default => '',
            };
            if ($s['type'] !== 'regular') {
                continue;
            }
            if ($status === 'present') {
                $presentCount++;
            } elseif ($status === 'absent') {
                $absentCount++;
            }
        }

        $row['present_count'] = $presentCount;
        $row['absent_count'] = $absentCount;
        $row['attendance_pct'] = min(ATTENDANCE_MAX_SCORE, $presentCount);
        $data[] = $row;
    }

    return [$columns, $data];
}

/**
 * Builds the PDF body as an HTML string dompdf can render, with the
 * university name/logo in the header above the report table.
 */
function render_report_pdf_html(string $universityName, string $campusLine, string $reportTitle, string $metaLine, array $columns, array $rows, string $logoBase64): string
{
    ob_start();
    ?>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; color: #0b1f3a; font-size: 11px; }
    .header { width: 100%; border-bottom: 2px solid #0ea5e9; padding-bottom: 8px; margin-bottom: 10px; overflow: hidden; }
    .header img { float: left; width: 56px; height: 56px; }
    .header-text { margin-left: 68px; }
    .uni-name { font-size: 16px; font-weight: bold; }
    .uni-meta { font-size: 9px; color: #64748b; }
    h2 { font-size: 13px; margin: 6px 0 2px; }
    .meta-line { font-size: 9px; color: #475569; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #0b1f3a; color: #fff; text-transform: uppercase; font-size: 8px; padding: 6px; text-align: left; }
    td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; font-size: 10px; }
    /* Same sky-blue column-group dividers/summary accent as the on-screen
       Xiiso Grid (build_xiiso_chunks()/col-group-end/col-summary in
       app.css) — only $columns built by build_xiiso_grid_report() ever set
       group_end/summary/header_accent, so this is a no-op for the other 3
       report types. */
    th.col-group-end, td.col-group-end { border-right: 3px solid #0ea5e9; }
    th.col-summary { background: #0ea5e9; color: #fff; }
    td.col-summary { background: rgba(14, 165, 233, 0.15); }
    /* Midterm/Final Xiiso columns — exams, not attendance sessions. */
    th.col-exam, td.col-exam { background: #cbd5e1; color: #475569; }
</style>
</head>
<body>
    <div class="header">
        <?php if ($logoBase64 !== ''): ?>
            <img src="data:image/jpeg;base64,<?= $logoBase64 ?>" width="56" height="56">
        <?php endif; ?>
        <div class="header-text">
            <div class="uni-name"><?= htmlspecialchars($universityName) ?></div>
            <div class="uni-meta"><?= htmlspecialchars($campusLine) ?></div>
        </div>
    </div>
    <h2><?= htmlspecialchars($reportTitle) ?></h2>
    <div class="meta-line">
        <?= htmlspecialchars($metaLine) ?>
        &nbsp;|&nbsp; Generated: <?= htmlspecialchars(date('Y-m-d H:i')) ?>
    </div>
    <table>
        <thead>
            <tr>
                <?php foreach ($columns as $col): ?>
                    <?php
                    $thClasses = trim(
                        (!empty($col['group_end']) ? 'col-group-end ' : '')
                        . (!empty($col['summary']) || !empty($col['header_accent']) ? 'col-summary ' : '')
                        . (!empty($col['exam']) ? 'col-exam' : '')
                    );
                    ?>
                    <th class="<?= $thClasses ?>"><?= htmlspecialchars($col['label']) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?= count($columns) ?>" style="text-align:center;color:var(--admas-text-muted);">No data for the selected filters.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <?php
                            $tdClasses = trim(
                                (!empty($col['group_end']) ? 'col-group-end ' : '')
                                . (!empty($col['summary']) ? 'col-summary ' : '')
                                . (!empty($col['exam']) ? 'col-exam' : '')
                            );
                            ?>
                            <td class="<?= $tdClasses ?>"><?= htmlspecialchars((string) $r[$col['key']]) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}

$conn = db();
$currentUser = current_user();
$role = current_role();

// ---------------------------------------------------------------------
// University settings (drives the sky-blue top strip + export headers)
// ---------------------------------------------------------------------
$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

$allowedReportTypes = REPORT_TYPES_BY_ROLE[$role] ?? [];

// ---------------------------------------------------------------------
// Role-specific scope: dean's own faculty, lecturer's own lecturers.id
// (same resolution pattern as attendance.php — never trusted from input)
// ---------------------------------------------------------------------
$deanFacultyId = 0;
$deanFacultyName = '';
if ($role === 'dean') {
    $deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
    if ($deanFacultyId > 0) {
        $fStmt = $conn->prepare('SELECT name FROM faculties WHERE id = ?');
        $fStmt->bind_param('i', $deanFacultyId);
        $fStmt->execute();
        $fRow = $fStmt->get_result()->fetch_assoc();
        $fStmt->close();
        $deanFacultyName = $fRow ? (string) $fRow['name'] : '';
    }
}

$lecturerRecordId = 0;
if ($role === 'lecturer') {
    $lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
    $lecStmt->bind_param('i', $currentUser['id']);
    $lecStmt->execute();
    $lecRow = $lecStmt->get_result()->fetch_assoc();
    $lecStmt->close();
    $lecturerRecordId = $lecRow ? (int) $lecRow['id'] : 0;
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$filterReportType = (string) ($_GET['report_type'] ?? '');
if (!in_array($filterReportType, $allowedReportTypes, true)) {
    $filterReportType = $allowedReportTypes[0] ?? '';
}

// Settings-driven defaults (admin/settings.php) pre-select these filters only
// when the request hasn't specified them at all — an explicit ?faculty_id=0
// ("All Faculties") is still respected as a deliberate choice.
$defaultFacultyIdSetting = (int) ($settings['default_faculty_id'] ?? 0);
$defaultDepartmentIdSetting = (int) ($settings['default_department_id'] ?? 0);

$filterFacultyId = 0;
if ($role === 'dean') {
    $filterFacultyId = $deanFacultyId;
} elseif ($role !== 'lecturer') {
    $filterFacultyId = isset($_GET['faculty_id']) ? (int) $_GET['faculty_id'] : $defaultFacultyIdSetting;
}

$filterDepartmentId = 0;
if ($role !== 'lecturer' && $filterReportType !== 'faculty_summary') {
    $filterDepartmentId = isset($_GET['department_id']) ? (int) $_GET['department_id'] : $defaultDepartmentIdSetting;
}

// Semester picker for the three summary report types (course_attendance,
// department_summary, faculty_summary) — replaces the old Date From/To
// range now that attendance is scored per-semester (out of 10), not as a
// date-range ratio. Dean is scoped to their own faculty's semesters (a
// dean has no legitimate reason to browse another faculty's semester list,
// even though the underlying report queries are already faculty-locked
// regardless — same defense-in-depth convention as the rest of this file).
// registration's own two report types don't use this at all (their
// enrollment-count columns have never been time-scoped), so it's ignored
// for that role rather than required.
$reportSemesters = $conn->query(
    "SELECT s.id, s.faculty_id, s.name, s.status, ay.label AS academic_year_label, f.name AS faculty_name
     FROM semesters s
     JOIN academic_years ay ON ay.id = s.academic_year_id
     JOIN faculties f ON f.id = s.faculty_id
     ORDER BY f.name, ay.label DESC, s.name"
)->fetch_all(MYSQLI_ASSOC);
if ($role === 'dean') {
    $reportSemesters = array_values(array_filter($reportSemesters, static fn ($s) => (int) $s['faculty_id'] === $deanFacultyId));
}
$reportSemesterById = [];
foreach ($reportSemesters as $s) {
    $reportSemesterById[(int) $s['id']] = $s;
}

$filterReportSemesterId = isset($_GET['report_semester_id']) ? (int) $_GET['report_semester_id'] : 0;
if ($filterReportSemesterId > 0 && !isset($reportSemesterById[$filterReportSemesterId])) {
    $filterReportSemesterId = 0;
}

// ---------------------------------------------------------------------
// Course + Semester lists for the Xiiso Attendance Grid report (scoped by
// role the same way as attendance.php's course dropdown).
// ---------------------------------------------------------------------
$xiisoCourses = [];
if (in_array('xiiso_grid', $allowedReportTypes, true)) {
    if ($role === 'lecturer') {
        // Same any-offering, historical scoping as the report filter above
        // — the Xiiso grid report already lets the semester be picked
        // independently, so a lecturer can pull a past semester's grid for
        // a course they no longer currently teach.
        $stmt = $conn->prepare(
            "SELECT c.id, c.code, c.name, c.department_id, d.faculty_id, d.name AS department_name, f.name AS faculty_name
             FROM courses c
             JOIN departments d ON d.id = c.department_id
             JOIN faculties f ON f.id = d.faculty_id
             WHERE EXISTS (SELECT 1 FROM course_offerings co WHERE co.course_id = c.id AND co.lecturer_id = ?)
             ORDER BY c.code"
        );
        $stmt->bind_param('i', $lecturerRecordId);
    } elseif ($role === 'dean') {
        // Own faculty's own-catalog courses, PLUS any course cross-listed
        // INTO this faculty from elsewhere (see the Multi-Faculty Course
        // Offerings plan) — same widening as attendance.php's course list.
        $stmt = $conn->prepare(
            "SELECT DISTINCT c.id, c.code, c.name, c.department_id, d.faculty_id, d.name AS department_name, f.name AS faculty_name
             FROM courses c
             JOIN departments d ON d.id = c.department_id
             JOIN faculties f ON f.id = d.faculty_id
             WHERE d.faculty_id = ?
                OR EXISTS (
                    SELECT 1 FROM course_offerings co
                    JOIN semesters se ON se.id = co.semester_id
                    WHERE co.course_id = c.id AND se.faculty_id = ?
                )
             ORDER BY d.name, c.code"
        );
        $stmt->bind_param('ii', $deanFacultyId, $deanFacultyId);
    } else {
        $stmt = $conn->prepare(
            "SELECT c.id, c.code, c.name, c.department_id, d.faculty_id, d.name AS department_name, f.name AS faculty_name
             FROM courses c
             JOIN departments d ON d.id = c.department_id
             JOIN faculties f ON f.id = d.faculty_id
             ORDER BY f.name, d.name, c.code"
        );
    }
    $stmt->execute();
    $xiisoCourses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
$xiisoCourseById = [];
foreach ($xiisoCourses as $c) {
    $xiisoCourseById[(int) $c['id']] = $c;
}

// Every semester across every faculty is listed here (not just one
// faculty's) — the Xiiso grid report is a historical/reporting surface, so
// a lecturer/admin/head_academic can pull a past semester's grid for a
// course they no longer teach, not just the current one. faculty_id is
// still selected so the mismatch guard below can catch a course+semester
// pair that don't belong to the same faculty. Dean is locked to their own
// faculty's semesters only (same "own faculty only" boundary already
// enforced on $reportSemesters above) — a dean cross-listing an outside
// course into their own faculty still only ever holds an offering under
// their own faculty's own semesters (write access never extends into
// another faculty's semesters), so this restriction never hides a
// legitimate dean-owned offering.
$xiisoSemesters = in_array('xiiso_grid', $allowedReportTypes, true)
    ? $conn->query(
        "SELECT s.id, s.faculty_id, s.name, s.status, ay.label AS academic_year_label, f.name AS faculty_name
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         JOIN faculties f ON f.id = s.faculty_id
         ORDER BY s.start_date DESC"
    )->fetch_all(MYSQLI_ASSOC)
    : [];
if ($role === 'dean') {
    $xiisoSemesters = array_values(array_filter($xiisoSemesters, static fn ($s) => (int) $s['faculty_id'] === $deanFacultyId));
}
$xiisoSemesterById = [];
foreach ($xiisoSemesters as $s) {
    $xiisoSemesterById[(int) $s['id']] = $s;
}

$filterXiisoCourseId = isset($_GET['xiiso_course_id']) ? (int) $_GET['xiiso_course_id'] : 0;

// Every faculty the selected course actually has a real course_offerings
// row in (home faculty first, as the preferred default) — a course can now
// be offered across more than one faculty at once, so there's no single
// scalar "the course's faculty" to default/validate against.
$xiisoCourseFacultyIds = [];
if (array_key_exists($filterXiisoCourseId, $xiisoCourseById)) {
    $offFacStmt = $conn->prepare(
        'SELECT DISTINCT se.faculty_id FROM course_offerings co JOIN semesters se ON se.id = co.semester_id WHERE co.course_id = ?'
    );
    $offFacStmt->bind_param('i', $filterXiisoCourseId);
    $offFacStmt->execute();
    $offFacIds = array_map(static fn ($r) => (int) $r['faculty_id'], $offFacStmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $offFacStmt->close();
    $xiisoCourseFacultyIds = array_values(array_unique(array_merge(
        [(int) $xiisoCourseById[$filterXiisoCourseId]['faculty_id']],
        $offFacIds
    )));
}

// Default the semester dropdown to the selected course's own home
// faculty's current semester, falling back to any other faculty this
// course is actually offered in — there's no single "the" current
// semester to default to before a course is chosen, so no default
// applies yet in that case.
$defaultXiisoSemesterId = 0;
foreach ($xiisoCourseFacultyIds as $fid) {
    $defaultXiisoSemesterId = (int) (get_current_semester($conn, $fid)['id'] ?? 0);
    if ($defaultXiisoSemesterId > 0) {
        break;
    }
}
$filterXiisoSemesterId = isset($_GET['xiiso_semester_id']) ? (int) $_GET['xiiso_semester_id'] : $defaultXiisoSemesterId;

// Guard against a course + semester pair with no real offering (e.g. a
// tampered query string) — not "different faculties," since a course can
// now legitimately have offerings across more than one faculty at once;
// silently building a grid for a pairing with no real offering would still
// be wrong, so treat the semester choice as unset instead.
if (
    array_key_exists($filterXiisoCourseId, $xiisoCourseById)
    && $filterXiisoSemesterId > 0
    && !course_offering_exists($conn, $filterXiisoCourseId, $filterXiisoSemesterId)
) {
    $filterXiisoSemesterId = 0;
}

// Optional Shift filter, same "blank = all shifts" convention as
// attendance.php's own Grid View.
$filterXiisoShift = (string) ($_GET['xiiso_shift'] ?? '');
if (!array_key_exists($filterXiisoShift, SHIFT_LABELS)) {
    $filterXiisoShift = '';
}

// ---------------------------------------------------------------------
// Faculty / Department lists for the filter dropdowns
// ---------------------------------------------------------------------
$faculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$departments = $conn->query(
    "SELECT d.id, d.name, d.faculty_id, f.name AS faculty_name
     FROM departments d
     JOIN faculties f ON f.id = d.faculty_id
     ORDER BY f.name, d.name"
)->fetch_all(MYSQLI_ASSOC);

$departmentsByFacultyId = [];
foreach ($departments as $dept) {
    $departmentsByFacultyId[(int) $dept['faculty_id']][] = ['id' => (int) $dept['id'], 'name' => $dept['name']];
}

$deanDepartments = $role === 'dean'
    ? array_values(array_filter($departments, static fn ($d) => (int) $d['faculty_id'] === $deanFacultyId))
    : [];

// ---------------------------------------------------------------------
// Build the report data for the current filters
// ---------------------------------------------------------------------
// registration's department/faculty summary is enrollment-based (no
// attendance %, never time-scoped) — it can render with no semester chosen
// at all; the other roles/report types genuinely need one selected first.
$reportSemesterOptional = $role === 'registration';

[$reportColumns, $reportRows] = match ($filterReportType) {
    'course_attendance' => $filterReportSemesterId > 0
        ? build_course_attendance_report($conn, $role, $filterFacultyId, $filterDepartmentId, $lecturerRecordId, $filterReportSemesterId)
        : [[], []],
    'department_summary' => ($reportSemesterOptional || $filterReportSemesterId > 0)
        ? build_department_summary_report($conn, $role, $filterFacultyId, $filterDepartmentId, $filterReportSemesterId)
        : [[], []],
    'faculty_summary' => ($reportSemesterOptional || $filterReportSemesterId > 0)
        ? build_faculty_summary_report($conn, $role, $filterFacultyId, $filterReportSemesterId)
        : [[], []],
    'xiiso_grid' => (array_key_exists($filterXiisoCourseId, $xiisoCourseById) && $filterXiisoSemesterId > 0)
        ? build_xiiso_grid_report($conn, $filterXiisoCourseId, $filterXiisoSemesterId, $filterXiisoShift !== '' ? $filterXiisoShift : null)
        : [[], []],
    'at_risk_students' => $filterReportSemesterId > 0
        ? build_at_risk_students_report($conn, $filterFacultyId, $filterDepartmentId, $filterReportSemesterId, (float) ($settings['min_attendance_pct'] ?? 7.5))
        : [[], []],
    'lecturer_recording_rate' => $filterReportSemesterId > 0
        ? build_lecturer_recording_rate_report($conn, $filterFacultyId, $filterDepartmentId, $filterReportSemesterId)
        : [[], []],
    default => [[], []],
};

$reportTitle = REPORT_TYPE_LABELS[$filterReportType] ?? 'Report';

if ($filterReportType === 'xiiso_grid') {
    // Same offering lookup the on-screen breadcrumb already uses
    // (render_offering_summary()/get_offering_summary()) — surfaced here too
    // so the exported file carries the same Faculty/Department/Academic
    // Year/Lecturer context as the screen, not just Course + Semester.
    $xiisoOfferings = (array_key_exists($filterXiisoCourseId, $xiisoCourseById) && $filterXiisoSemesterId > 0)
        ? get_offering_summary($conn, $filterXiisoCourseId, $filterXiisoSemesterId, $filterXiisoShift !== '' ? $filterXiisoShift : null)
        : [];
    $xiisoLecturerLine = empty($xiisoOfferings)
        ? 'Unassigned'
        : implode(', ', array_map(
            static fn ($o) => ($o['shift'] === 'any' ? '' : (OFFERING_SHIFT_LABELS[$o['shift']] ?? $o['shift']) . ': ') . ($o['lecturer_name'] ?: 'Unassigned'),
            $xiisoOfferings
        ));

    // Department/Faculty shown here follow the SELECTED semester, not the
    // course's static catalog home — a cross-faculty ("guest") offering
    // means the semester's own faculty (and its Roster Department) can
    // legitimately differ from the course's catalog department/faculty;
    // showing the catalog values here was misleading whenever that
    // happened (see attendance.php's identical fix/comment for the
    // original incident this addresses).
    $xiisoReportDepartmentName = $xiisoCourseById[$filterXiisoCourseId]['department_name'] ?? '';
    $xiisoReportFacultyName = $xiisoCourseById[$filterXiisoCourseId]['faculty_name'] ?? '';
    if (isset($xiisoSemesterById[$filterXiisoSemesterId]) && array_key_exists($filterXiisoCourseId, $xiisoCourseById)) {
        $xiisoSemesterFacultyId = (int) $xiisoSemesterById[$filterXiisoSemesterId]['faculty_id'];
        if ($xiisoSemesterFacultyId !== (int) $xiisoCourseById[$filterXiisoCourseId]['faculty_id']) {
            $xiisoReportFacultyName = (string) $xiisoSemesterById[$filterXiisoSemesterId]['faculty_name'];
            $xiisoRosterDeptId = resolve_roster_department_id($conn, $filterXiisoCourseId, $filterXiisoSemesterId, $filterXiisoShift !== '' ? $filterXiisoShift : null);
            if ($xiisoRosterDeptId !== null) {
                $xiisoRdStmt = $conn->prepare('SELECT name FROM departments WHERE id = ?');
                $xiisoRdStmt->bind_param('i', $xiisoRosterDeptId);
                $xiisoRdStmt->execute();
                $xiisoRdRow = $xiisoRdStmt->get_result()->fetch_assoc();
                $xiisoRdStmt->close();
                if ($xiisoRdRow) {
                    $xiisoReportDepartmentName = (string) $xiisoRdRow['name'];
                }
            }
        }
    }

    $reportMetaLine = 'Course: ' . ($xiisoCourseById[$filterXiisoCourseId]['code'] ?? '') . ' — ' . ($xiisoCourseById[$filterXiisoCourseId]['name'] ?? '')
        . '   |   Department: ' . $xiisoReportDepartmentName
        . '   |   Faculty: ' . $xiisoReportFacultyName
        . '   |   Semester: ' . ($xiisoSemesterById[$filterXiisoSemesterId]['name'] ?? '')
        . '   |   Academic Year: ' . ($xiisoSemesterById[$filterXiisoSemesterId]['academic_year_label'] ?? '')
        . ($filterXiisoShift !== '' ? '   |   Shift: ' . SHIFT_LABELS[$filterXiisoShift] : '')
        . '   |   Lecturer: ' . $xiisoLecturerLine;
} elseif ($filterReportSemesterId > 0 && isset($reportSemesterById[$filterReportSemesterId])) {
    $rs = $reportSemesterById[$filterReportSemesterId];
    $reportMetaLine = 'Semester: ' . $rs['name'] . ' (' . $rs['academic_year_label'] . ')   |   Faculty: ' . $rs['faculty_name'];
} elseif ($reportSemesterOptional) {
    $reportMetaLine = 'All-time enrollment totals (not semester-scoped)';
} else {
    $reportMetaLine = 'Semester: (none selected)';
}

$currentQuery = [
    'report_type' => $filterReportType,
    'faculty_id' => $filterFacultyId,
    'department_id' => $filterDepartmentId,
    'report_semester_id' => $filterReportSemesterId,
    'xiiso_course_id' => $filterXiisoCourseId,
    'xiiso_semester_id' => $filterXiisoSemesterId,
    'xiiso_shift' => $filterXiisoShift,
];
$exportExcelUrl = BASE_URL . '/reports.php?' . http_build_query($currentQuery + ['export' => 'excel']);
$exportPdfUrl = BASE_URL . '/reports.php?' . http_build_query($currentQuery + ['export' => 'pdf']);

// ---------------------------------------------------------------------
// Export handling (must run before any HTML output)
// ---------------------------------------------------------------------
$exportFormat = (string) ($_GET['export'] ?? '');
if ($exportFormat === 'excel' || $exportFormat === 'pdf') {
    $universityName = $settings['university_name'] ?? 'ADMAS University';

    if ($filterReportType === 'xiiso_grid') {
        $xiisoCourseLabel = $xiisoCourseById[$filterXiisoCourseId]['code'] ?? 'course';
        $xiisoSemesterLabel = $xiisoSemesterById[$filterXiisoSemesterId]['name'] ?? 'semester';
        $filename = str_replace(' ', '_', strtolower(REPORT_TYPE_LABELS[$filterReportType]))
            . '_' . preg_replace('/\s+/', '_', $xiisoCourseLabel) . '_' . preg_replace('/\s+/', '_', $xiisoSemesterLabel);
    } else {
        $semesterLabel = $reportSemesterById[$filterReportSemesterId]['name'] ?? 'all_time';
        $filename = str_replace(' ', '_', strtolower(REPORT_TYPE_LABELS[$filterReportType]))
            . '_' . preg_replace('/\s+/', '_', $semesterLabel);
    }

    if ($exportFormat === 'excel') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $sheet->setCellValue('A1', $universityName);
        $sheet->setCellValue('A2', $reportTitle);
        $sheet->setCellValue('A3', $reportMetaLine . '   |   Generated: ' . date('Y-m-d H:i'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

        $headerRow = 5;
        $columnLetters = [];
        $colLetter = 'A';
        foreach ($reportColumns as $col) {
            $sheet->setCellValue($colLetter . $headerRow, $col['label']);
            $columnLetters[] = $colLetter;
            $colLetter++;
        }
        $lastCol = end($columnLetters) ?: 'A';
        $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0B1F3A');

        $rowIndex = $headerRow + 1;
        foreach ($reportRows as $r) {
            $colLetter = 'A';
            foreach ($reportColumns as $col) {
                $sheet->setCellValue($colLetter . $rowIndex, $r[$col['key']]);
                $colLetter++;
            }
            $rowIndex++;
        }
        $lastDataRow = $rowIndex - 1;

        // Same sky-blue column-group dividers/summary accent as the
        // on-screen Xiiso Grid and the PDF export above — only $reportColumns
        // built by build_xiiso_grid_report() ever set
        // group_end/summary/header_accent, so this is a no-op for the other
        // 3 report types.
        if ($lastDataRow >= $headerRow) {
            $colLetter = 'A';
            foreach ($reportColumns as $col) {
                if (!empty($col['exam'])) {
                    // Midterm/Final Xiiso columns — exams, not attendance
                    // sessions; grey instead of the sky-blue summary tint.
                    $sheet->getStyle($colLetter . $headerRow . ':' . $colLetter . max($lastDataRow, $headerRow))
                        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('CBD5E1');
                } elseif (!empty($col['summary']) || !empty($col['header_accent'])) {
                    $sheet->getStyle($colLetter . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0EA5E9');
                }
                if (!empty($col['summary']) && $lastDataRow > $headerRow) {
                    $sheet->getStyle($colLetter . ($headerRow + 1) . ':' . $colLetter . $lastDataRow)
                        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D6F0FC');
                }
                if (!empty($col['group_end'])) {
                    $sheet->getStyle($colLetter . $headerRow . ':' . $colLetter . $lastDataRow)
                        ->getBorders()->getRight()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('0EA5E9');
                }
                $colLetter++;
            }
        }

        foreach ($columnLetters as $cl) {
            $sheet->getColumnDimension($cl)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');

        (new XlsxWriter($spreadsheet))->save('php://output');
        exit;
    }

    // PDF export
    $logoPath = __DIR__ . '/' . get_university_logo_relative_path($settings);
    $logoBase64 = is_file($logoPath) ? base64_encode((string) file_get_contents($logoPath)) : '';
    $campusLine = trim(($settings['campus'] ?? '') . ' — ' . ($settings['contact_email'] ?? '') . ' — ' . ($settings['contact_phone'] ?? ''), ' —');

    $html = render_report_pdf_html($universityName, $campusLine, $reportTitle, $reportMetaLine, $reportColumns, $reportRows, $logoBase64);

    $pdfOptions = new DompdfOptions();
    $pdfOptions->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($pdfOptions);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream($filename . '.pdf', ['Attachment' => true]);
    exit;
}

$scopeBanner = match ($role) {
    'university_rector' => 'Access scope: Full system — all faculties, departments, and courses',
    'head_academic' => 'Access scope: All faculties (cross-faculty reporting)',
    'registration' => 'Access scope: All faculties — enrollment-focused reports only',
    'dean' => 'Access scope: ' . $deanFacultyName . ' Faculty only',
    'lecturer' => 'Access scope: Your assigned courses only',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                <?= htmlspecialchars($scopeBanner) ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Reports</h4>
                    <p class="text-muted mb-0">Filter and export attendance and enrollment reports for your scope.</p>
                </div>
            </div>

            <div class="admas-card p-4 mb-3">
                <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/reports.php" class="row g-2 align-items-end" id="reportsFilterForm">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label small mb-1">Report Type</label>
                        <select class="form-select form-select-sm" name="report_type" id="reportTypeSelect" onchange="toggleReportFilters()">
                            <?php foreach ($allowedReportTypes as $typeKey): ?>
                                <option value="<?= htmlspecialchars($typeKey) ?>" <?= $filterReportType === $typeKey ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(REPORT_TYPE_LABELS[$typeKey]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-sm-6 col-md-3" id="facultyFilterWrap" style="<?= $filterReportType === 'xiiso_grid' ? 'display:none;' : '' ?>">
                        <label class="form-label small mb-1">Faculty</label>
                        <?php if ($role === 'lecturer'): ?>
                            <input type="text" class="form-control form-control-sm" value="Your courses" disabled>
                        <?php elseif ($role === 'dean'): ?>
                            <select class="form-select form-select-sm" disabled>
                                <option selected><?= htmlspecialchars($deanFacultyName) ?></option>
                            </select>
                        <?php else: ?>
                            <select class="form-select form-select-sm" name="faculty_id" id="filterFacultySelect" onchange="updateFilterDepartmentOptions(this.value, 0)">
                                <option value="0">All Faculties</option>
                                <?php foreach ($faculties as $f): ?>
                                    <option value="<?= (int) $f['id'] ?>" <?= $filterFacultyId === (int) $f['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($f['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="col-sm-6 col-md-3" id="departmentFilterWrap" style="<?= ($filterReportType === 'faculty_summary' || $filterReportType === 'xiiso_grid') ? 'display:none;' : '' ?>">
                        <label class="form-label small mb-1">Department</label>
                        <?php if ($role === 'lecturer'): ?>
                            <input type="text" class="form-control form-control-sm" value="Your courses" disabled>
                        <?php elseif ($role === 'dean'): ?>
                            <select class="form-select form-select-sm" name="department_id">
                                <option value="0">All Departments</option>
                                <?php foreach ($deanDepartments as $d): ?>
                                    <option value="<?= (int) $d['id'] ?>" <?= $filterDepartmentId === (int) $d['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <select class="form-select form-select-sm" name="department_id" id="filterDepartmentSelect">
                                <option value="0">All Departments</option>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="col-sm-6 col-md-3" id="reportSemesterWrap" style="<?= $filterReportType === 'xiiso_grid' ? 'display:none;' : '' ?>">
                        <label class="form-label small mb-1">Semester</label>
                        <select class="form-select form-select-sm" name="report_semester_id">
                            <option value="0"><?= $reportSemesterOptional ? 'All-time' : 'Select semester' ?></option>
                            <?php foreach ($reportSemesters as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $filterReportSemesterId === (int) $s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name'] . ' (' . $s['academic_year_label'] . ' · ' . ucfirst($s['status']) . ') — ' . $s['faculty_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$reportSemesterOptional): ?>
                            <div class="form-text">Each Present regular Xiiso session = 1 point (out of 10).</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-sm-6 col-md-3" id="xiisoCourseWrap" style="<?= $filterReportType === 'xiiso_grid' ? '' : 'display:none;' ?>">
                        <label class="form-label small mb-1">Course</label>
                        <select class="form-select form-select-sm" name="xiiso_course_id">
                            <option value="">Select course</option>
                            <?php foreach ($xiisoCourses as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= $filterXiisoCourseId === (int) $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-2" id="xiisoSemesterWrap" style="<?= $filterReportType === 'xiiso_grid' ? '' : 'display:none;' ?>">
                        <label class="form-label small mb-1">Semester</label>
                        <select class="form-select form-select-sm" name="xiiso_semester_id">
                            <option value="">Select semester</option>
                            <?php foreach ($xiisoSemesters as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $filterXiisoSemesterId === (int) $s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name'] . ' (' . $s['academic_year_label'] . ' · ' . ucfirst($s['status']) . ') — ' . $s['faculty_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-sm-6 col-md-2" id="xiisoShiftWrap" style="<?= $filterReportType === 'xiiso_grid' ? '' : 'display:none;' ?>">
                        <label class="form-label small mb-1">Shift <span class="text-muted fw-normal">(optional)</span></label>
                        <select class="form-select form-select-sm" name="xiiso_shift">
                            <option value="">All Shifts</option>
                            <?php foreach (SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                <option value="<?= htmlspecialchars($shiftValue) ?>" <?= $filterXiisoShift === $shiftValue ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($shiftLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-sm-6 col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                            <i class="bi bi-funnel"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <div class="admas-card p-4">
                <?php if ($filterReportType === 'xiiso_grid' && array_key_exists($filterXiisoCourseId, $xiisoCourseById)): ?>
                    <?= render_scope_breadcrumb([
                        $xiisoCourseById[$filterXiisoCourseId]['code'],
                        $xiisoReportDepartmentName ?? $xiisoCourseById[$filterXiisoCourseId]['department_name'],
                        $xiisoReportFacultyName ?? $xiisoCourseById[$filterXiisoCourseId]['faculty_name'],
                        $xiisoSemesterById[$filterXiisoSemesterId]['name'] ?? null,
                        $xiisoSemesterById[$filterXiisoSemesterId]['academic_year_label'] ?? null,
                    ]) ?>
                    <?= render_offering_summary(get_offering_summary($conn, $filterXiisoCourseId, (int) $filterXiisoSemesterId)) ?>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h6 class="fw-bold mb-0" style="color: var(--admas-text);">
                        <?= htmlspecialchars($reportTitle) ?>
                        <span class="text-muted fw-normal">(<?= htmlspecialchars($reportMetaLine) ?>)</span>
                    </h6>
                    <div class="d-flex gap-2">
                        <a href="<?= htmlspecialchars($exportExcelUrl) ?>" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                        </a>
                        <a href="<?= htmlspecialchars($exportPdfUrl) ?>" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                            <i class="bi bi-file-earmark-pdf"></i> Export PDF
                        </a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <?php if ($filterReportType === 'xiiso_grid' && $filterXiisoSemesterId > 0): ?>
                                <?php $xiisoBandChunks = build_xiiso_chunks(get_sessions_for_semester($conn, $filterXiisoSemesterId)); ?>
                                <tr>
                                    <th colspan="2"></th>
                                    <?php foreach ($xiisoBandChunks as $chunk): ?>
                                        <th class="grid-month-band col-group-end" colspan="<?= (int) $chunk['span'] ?>"><?= htmlspecialchars($chunk['label']) ?></th>
                                    <?php endforeach; ?>
                                    <th colspan="3"></th>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <?php foreach ($reportColumns as $col): ?>
                                    <th class="<?= trim((!empty($col['group_end']) ? 'col-group-end' : '') . ' ' . (!empty($col['summary']) || !empty($col['header_accent']) ? 'col-summary' : '') . ' ' . (!empty($col['exam']) ? 'col-exam' : '')) ?>"<?= !empty($col['exam']) ? ' title="Exam — not part of the attendance score"' : '' ?>><?= htmlspecialchars($col['label']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportRows)): ?>
                                <tr>
                                    <td colspan="<?= max(1, count($reportColumns)) ?>" class="text-center text-muted py-4">No data for the selected filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reportRows as $r): ?>
                                    <tr>
                                        <?php foreach ($reportColumns as $col): ?>
                                            <td class="<?= trim((!empty($col['group_end']) ? 'col-group-end' : '') . ' ' . (!empty($col['summary']) ? 'col-summary' : '') . ' ' . (!empty($col['exam']) ? 'col-exam' : '')) ?>">
                                                <?php if ($col['key'] === 'full_name' && array_key_exists('photo_path', $r)): ?>
                                                    <?php render_person_avatar_cell($r['photo_path'], (string) $r['full_name'], '', true); ?>
                                                <?php else: ?>
                                                    <?= htmlspecialchars((string) $r[$col['key']]) ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/live_filter.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            admasInitLiveFilter('#reportsFilterForm');
        });

        function toggleReportFilters() {
            const reportType = document.getElementById('reportTypeSelect').value;
            const isXiiso = reportType === 'xiiso_grid';

            const setDisplay = (id, visible) => {
                const el = document.getElementById(id);
                if (el) {
                    el.style.display = visible ? '' : 'none';
                }
            };

            setDisplay('facultyFilterWrap', !isXiiso);
            setDisplay('departmentFilterWrap', !isXiiso && reportType !== 'faculty_summary');
            setDisplay('reportSemesterWrap', !isXiiso);
            setDisplay('xiisoCourseWrap', isXiiso);
            setDisplay('xiisoSemesterWrap', isXiiso);
            setDisplay('xiisoShiftWrap', isXiiso);
        }
    </script>
    <?php if (!in_array($role, ['dean', 'lecturer'], true)): ?>
        <script>
            const departmentsByFacultyId = <?= json_encode($departmentsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const allDepartmentsFlat = <?= json_encode(array_map(static fn ($d) => ['id' => (int) $d['id'], 'name' => $d['name']], $departments), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            function buildDepartmentOptions(select, facultyId, selectedDepartmentId) {
                let deptList = departmentsByFacultyId[facultyId] || [];
                if (deptList.length === 0 && (!facultyId || facultyId === '0')) {
                    deptList = allDepartmentsFlat;
                }
                select.innerHTML = '';

                const allOption = document.createElement('option');
                allOption.value = '0';
                allOption.textContent = 'All Departments';
                select.appendChild(allOption);

                deptList.forEach((dept) => {
                    const opt = document.createElement('option');
                    opt.value = String(dept.id);
                    opt.textContent = dept.name;
                    select.appendChild(opt);
                });

                select.value = String(selectedDepartmentId || 0);
                if (select.value !== String(selectedDepartmentId || 0)) {
                    select.value = '0';
                }
            }

            function updateFilterDepartmentOptions(facultyId, selectedDepartmentId) {
                buildDepartmentOptions(document.getElementById('filterDepartmentSelect'), facultyId, selectedDepartmentId);
            }

            window.addEventListener('DOMContentLoaded', () => {
                const facultySelect = document.getElementById('filterFacultySelect');
                updateFilterDepartmentOptions(facultySelect.value, <?= (int) $filterDepartmentId ?>);
            });
        </script>
    <?php endif; ?>
</body>
</html>
