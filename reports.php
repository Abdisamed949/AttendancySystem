<?php
/**
 * Reports screen — shared by System Administrator, Head of Academic Affairs,
 * Dean, Registration Office, and Lecturer, each scoped to a different slice
 * of the data (same scoping pattern as attendance.php). Lives at the app
 * root because it is reused by five roles rather than owned by one folder.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/semester_helpers.php';
require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

require_role(['system_admin', 'head_academic', 'dean', 'registration', 'lecturer']);

use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

const REPORT_TYPE_LABELS = [
    'course_attendance' => 'Course Attendance Summary',
    'department_summary' => 'Department Summary',
    'faculty_summary' => 'Faculty Summary',
    'xiiso_grid' => 'Xiiso Attendance Grid',
];

const REPORT_TYPES_BY_ROLE = [
    'system_admin' => ['course_attendance', 'department_summary', 'faculty_summary', 'xiiso_grid'],
    'head_academic' => ['course_attendance', 'department_summary', 'faculty_summary', 'xiiso_grid'],
    'dean' => ['course_attendance', 'department_summary', 'xiiso_grid'],
    'registration' => ['department_summary', 'faculty_summary'],
    'lecturer' => ['course_attendance', 'xiiso_grid'],
];

// ---------------------------------------------------------------------
// Report data builders — one function per report type. Each returns
// [$columns, $rows] where $columns is a list of ['key' => .., 'label' => ..]
// and $rows is a list of assoc arrays keyed the same way, so the HTML
// table, the Excel export, and the PDF export can all share one render
// loop instead of three separate hand-written tables.
// ---------------------------------------------------------------------

function build_course_attendance_report(mysqli $conn, string $role, int $facultyId, int $departmentId, int $lecturerRecordId, string $dateFrom, string $dateTo): array
{
    $conditions = [];
    $params = [$dateFrom, $dateTo];
    $types = 'ss';

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

    $sql = "SELECT c.code, c.name, d.name AS department_name, f.name AS faculty_name,
                   COUNT(DISTINCT a.attendance_date) AS total_sessions,
                   COUNT(a.id) AS total_marks,
                   SUM(a.status = 'present') AS present_count,
                   SUM(a.status = 'absent') AS absent_count
            FROM courses c
            JOIN departments d ON d.id = c.department_id
            JOIN faculties f ON f.id = d.faculty_id
            LEFT JOIN attendance a ON a.course_id = c.id AND a.attendance_date BETWEEN ? AND ?
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
        ['key' => 'total_sessions', 'label' => 'Total Sessions'],
        ['key' => 'avg_present_pct', 'label' => 'Avg Present %'],
        ['key' => 'avg_absent_pct', 'label' => 'Avg Absent %'],
    ];

    $data = [];
    foreach ($rows as $r) {
        $totalMarks = (int) $r['total_marks'];
        $data[] = [
            'course' => $r['code'] . ' — ' . $r['name'],
            'department' => $r['department_name'],
            'faculty' => $r['faculty_name'],
            'total_sessions' => (int) $r['total_sessions'],
            'avg_present_pct' => $totalMarks > 0 ? round(100 * (int) $r['present_count'] / $totalMarks, 1) : 0.0,
            'avg_absent_pct' => $totalMarks > 0 ? round(100 * (int) $r['absent_count'] / $totalMarks, 1) : 0.0,
        ];
    }

    return [$columns, $data];
}

function build_department_summary_report(mysqli $conn, string $role, int $facultyId, int $departmentId, string $dateFrom, string $dateTo): array
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
        $attStmt = $conn->prepare(
            "SELECT c.department_id,
                    COUNT(a.id) AS total_marks,
                    SUM(a.status = 'present') AS present_count,
                    SUM(a.status = 'absent') AS absent_count
             FROM attendance a
             JOIN courses c ON c.id = a.course_id
             WHERE a.attendance_date BETWEEN ? AND ?
             GROUP BY c.department_id"
        );
        $attStmt->bind_param('ss', $dateFrom, $dateTo);
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
        $columns[] = ['key' => 'avg_present_pct', 'label' => 'Avg Present %'];
        $columns[] = ['key' => 'avg_absent_pct', 'label' => 'Avg Absent %'];
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
            $totalMarks = (int) ($att['total_marks'] ?? 0);
            $row['avg_present_pct'] = $totalMarks > 0 ? round(100 * (int) $att['present_count'] / $totalMarks, 1) : 0.0;
            $row['avg_absent_pct'] = $totalMarks > 0 ? round(100 * (int) $att['absent_count'] / $totalMarks, 1) : 0.0;
        } else {
            $row['total_enrollments'] = $enrollmentsByDept[$deptId] ?? 0;
        }
        $data[] = $row;
    }

    return [$columns, $data];
}

function build_faculty_summary_report(mysqli $conn, string $role, int $facultyId, string $dateFrom, string $dateTo): array
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
        $attStmt = $conn->prepare(
            "SELECT d.faculty_id,
                    COUNT(a.id) AS total_marks,
                    SUM(a.status = 'present') AS present_count,
                    SUM(a.status = 'absent') AS absent_count
             FROM attendance a
             JOIN courses c ON c.id = a.course_id
             JOIN departments d ON d.id = c.department_id
             WHERE a.attendance_date BETWEEN ? AND ?
             GROUP BY d.faculty_id"
        );
        $attStmt->bind_param('ss', $dateFrom, $dateTo);
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
        $columns[] = ['key' => 'avg_present_pct', 'label' => 'Avg Present %'];
        $columns[] = ['key' => 'avg_absent_pct', 'label' => 'Avg Absent %'];
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
            $totalMarks = (int) ($att['total_marks'] ?? 0);
            $row['avg_present_pct'] = $totalMarks > 0 ? round(100 * (int) $att['present_count'] / $totalMarks, 1) : 0.0;
            $row['avg_absent_pct'] = $totalMarks > 0 ? round(100 * (int) $att['absent_count'] / $totalMarks, 1) : 0.0;
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
function build_xiiso_grid_report(mysqli $conn, int $courseId, int $semesterId): array
{
    $gridData = get_xiiso_grid_data($conn, $courseId, $semesterId);
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
        ];
    }
    $columns[] = ['key' => 'present_count', 'label' => 'P', 'group_end' => true, 'summary' => true];
    $columns[] = ['key' => 'absent_count', 'label' => 'A', 'group_end' => true, 'summary' => true];
    $columns[] = ['key' => 'attendance_pct', 'label' => '%', 'summary' => true];

    $data = [];
    foreach ($students as $st) {
        $sid = (int) $st['id'];
        $row = ['student_no' => $st['student_no'], 'full_name' => $st['full_name']];

        $presentCount = 0;
        $absentCount = 0;
        $totalMarks = 0;
        foreach ($sessions as $s) {
            $status = $marksByStudentSession[$sid][(int) $s['id']] ?? null;
            $row['session_' . $s['id']] = match ($status) {
                'present' => '1',
                'absent' => '0',
                default => '',
            };
            if ($status !== null) {
                $totalMarks++;
                if ($status === 'present') {
                    $presentCount++;
                } elseif ($status === 'absent') {
                    $absentCount++;
                }
            }
        }

        $row['present_count'] = $presentCount;
        $row['absent_count'] = $absentCount;
        $row['attendance_pct'] = $totalMarks > 0 ? round(100 * $presentCount / $totalMarks, 1) : 0.0;
        $data[] = $row;
    }

    return [$columns, $data];
}

function report_export_filename(string $reportType, string $dateFrom, string $dateTo): string
{
    $slug = str_replace(' ', '_', strtolower(REPORT_TYPE_LABELS[$reportType] ?? 'report'));

    return $slug . '_' . $dateFrom . '_to_' . $dateTo;
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
</style>
</head>
<body>
    <div class="header">
        <?php if ($logoBase64 !== ''): ?>
            <img src="data:image/jpeg;base64,<?= $logoBase64 ?>">
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
                    <th><?= htmlspecialchars($col['label']) ?></th>
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
                            <td><?= htmlspecialchars((string) $r[$col['key']]) ?></td>
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

$defaultDateFrom = date('Y-m-01');
$defaultDateTo = date('Y-m-t');
$filterDateFrom = (string) ($_GET['date_from'] ?? $defaultDateFrom);
$filterDateTo = (string) ($_GET['date_to'] ?? $defaultDateTo);
if (!DateTime::createFromFormat('Y-m-d', $filterDateFrom)) {
    $filterDateFrom = $defaultDateFrom;
}
if (!DateTime::createFromFormat('Y-m-d', $filterDateTo)) {
    $filterDateTo = $defaultDateTo;
}
if ($filterDateFrom > $filterDateTo) {
    [$filterDateFrom, $filterDateTo] = [$filterDateTo, $filterDateFrom];
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
        $stmt = $conn->prepare(
            "SELECT c.id, c.code, c.name, c.department_id, d.faculty_id, d.name AS department_name, f.name AS faculty_name
             FROM courses c
             JOIN departments d ON d.id = c.department_id
             JOIN faculties f ON f.id = d.faculty_id
             WHERE d.faculty_id = ?
             ORDER BY d.name, c.code"
        );
        $stmt->bind_param('i', $deanFacultyId);
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
// a lecturer/dean/admin can pull a past semester's grid for a course they
// no longer teach, not just the current one. faculty_id is still selected
// so the mismatch guard below can catch a course+semester pair that don't
// belong to the same faculty.
$xiisoSemesters = in_array('xiiso_grid', $allowedReportTypes, true)
    ? $conn->query(
        "SELECT s.id, s.faculty_id, s.name, ay.label AS academic_year_label
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         ORDER BY s.start_date DESC"
    )->fetch_all(MYSQLI_ASSOC)
    : [];
$xiisoSemesterById = [];
foreach ($xiisoSemesters as $s) {
    $xiisoSemesterById[(int) $s['id']] = $s;
}

$filterXiisoCourseId = isset($_GET['xiiso_course_id']) ? (int) $_GET['xiiso_course_id'] : 0;

// Default the semester dropdown to the selected course's own faculty's
// current semester — there's no single "the" current semester to default
// to before a course is chosen, so no default applies yet in that case.
$defaultXiisoSemesterId = 0;
if (array_key_exists($filterXiisoCourseId, $xiisoCourseById)) {
    $defaultXiisoSemesterId = (int) (get_current_semester($conn, (int) $xiisoCourseById[$filterXiisoCourseId]['faculty_id'])['id'] ?? 0);
}
$filterXiisoSemesterId = isset($_GET['xiiso_semester_id']) ? (int) $_GET['xiiso_semester_id'] : $defaultXiisoSemesterId;

// Guard against a course + semester pair from different faculties (e.g. a
// tampered query string) — silently building a cross-faculty grid would be
// wrong, so treat the semester choice as unset instead.
if (
    array_key_exists($filterXiisoCourseId, $xiisoCourseById)
    && array_key_exists($filterXiisoSemesterId, $xiisoSemesterById)
    && (int) $xiisoSemesterById[$filterXiisoSemesterId]['faculty_id'] !== (int) $xiisoCourseById[$filterXiisoCourseId]['faculty_id']
) {
    $filterXiisoSemesterId = 0;
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
[$reportColumns, $reportRows] = match ($filterReportType) {
    'course_attendance' => build_course_attendance_report($conn, $role, $filterFacultyId, $filterDepartmentId, $lecturerRecordId, $filterDateFrom, $filterDateTo),
    'department_summary' => build_department_summary_report($conn, $role, $filterFacultyId, $filterDepartmentId, $filterDateFrom, $filterDateTo),
    'faculty_summary' => build_faculty_summary_report($conn, $role, $filterFacultyId, $filterDateFrom, $filterDateTo),
    'xiiso_grid' => (array_key_exists($filterXiisoCourseId, $xiisoCourseById) && $filterXiisoSemesterId > 0)
        ? build_xiiso_grid_report($conn, $filterXiisoCourseId, $filterXiisoSemesterId)
        : [[], []],
    default => [[], []],
};

$reportTitle = REPORT_TYPE_LABELS[$filterReportType] ?? 'Report';

if ($filterReportType === 'xiiso_grid') {
    $reportMetaLine = 'Course: ' . ($xiisoCourseById[$filterXiisoCourseId]['code'] ?? '') . ' — ' . ($xiisoCourseById[$filterXiisoCourseId]['name'] ?? '')
        . '   |   Semester: ' . ($xiisoSemesterById[$filterXiisoSemesterId]['name'] ?? '');
} else {
    $reportMetaLine = 'Period: ' . $filterDateFrom . ' to ' . $filterDateTo;
}

$currentQuery = [
    'report_type' => $filterReportType,
    'faculty_id' => $filterFacultyId,
    'department_id' => $filterDepartmentId,
    'date_from' => $filterDateFrom,
    'date_to' => $filterDateTo,
    'xiiso_course_id' => $filterXiisoCourseId,
    'xiiso_semester_id' => $filterXiisoSemesterId,
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
        $filename = report_export_filename($filterReportType, $filterDateFrom, $filterDateTo);
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
    $logoPath = __DIR__ . '/logo/logo.jpg';
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
    'system_admin' => 'Access scope: Full system — all faculties, departments, and courses',
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
                <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/reports.php" class="row g-2 align-items-end">
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

                    <div class="col-sm-6 col-md-2" id="dateFromWrap" style="<?= $filterReportType === 'xiiso_grid' ? 'display:none;' : '' ?>">
                        <label class="form-label small mb-1">From</label>
                        <input type="date" class="form-control form-control-sm" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
                    </div>
                    <div class="col-sm-6 col-md-2" id="dateToWrap" style="<?= $filterReportType === 'xiiso_grid' ? 'display:none;' : '' ?>">
                        <label class="form-label small mb-1">To</label>
                        <input type="date" class="form-control form-control-sm" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
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
                                    <?= htmlspecialchars($s['name'] . ' (' . $s['academic_year_label'] . ')') ?>
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
                        $xiisoCourseById[$filterXiisoCourseId]['department_name'],
                        $xiisoCourseById[$filterXiisoCourseId]['faculty_name'],
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
                        <a href="<?= htmlspecialchars($exportExcelUrl) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                        </a>
                        <a href="<?= htmlspecialchars($exportPdfUrl) ?>" class="btn btn-outline-secondary btn-sm">
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
                                    <th class="<?= trim((!empty($col['group_end']) ? 'col-group-end' : '') . ' ' . (!empty($col['summary']) || !empty($col['header_accent']) ? 'col-summary' : '')) ?>"><?= htmlspecialchars($col['label']) ?></th>
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
                                            <td class="<?= trim((!empty($col['group_end']) ? 'col-group-end' : '') . ' ' . (!empty($col['summary']) ? 'col-summary' : '')) ?>"><?= htmlspecialchars((string) $r[$col['key']]) ?></td>
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
    <script>
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
            setDisplay('dateFromWrap', !isXiiso);
            setDisplay('dateToWrap', !isXiiso);
            setDisplay('xiisoCourseWrap', isXiiso);
            setDisplay('xiisoSemesterWrap', isXiiso);
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
