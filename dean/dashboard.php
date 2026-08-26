<?php
/**
 * Dean dashboard — scoped entirely to the Dean's own faculty
 * ($_SESSION['faculty_id'], never trusted from request input).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';
require_once __DIR__ . '/../includes/semester_helpers.php';
require_once __DIR__ . '/../includes/timetable_helpers.php';
require_once __DIR__ . '/../includes/university_logo.php';
require_once __DIR__ . '/../includes/avatar_helpers.php';

require_role(['dean']);

$conn = db();
$currentUser = current_user();

// ---------------------------------------------------------------------
// University settings (drives the sky-blue top strip)
// ---------------------------------------------------------------------
$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$minAttendancePct = (float) ($settings['min_attendance_pct'] ?? 75);

// ---------------------------------------------------------------------
// Own faculty (never trusted from input)
// ---------------------------------------------------------------------
$deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
$deanFacultyName = '';
if ($deanFacultyId > 0) {
    $fStmt = $conn->prepare('SELECT name FROM faculties WHERE id = ?');
    $fStmt->bind_param('i', $deanFacultyId);
    $fStmt->execute();
    $fRow = $fStmt->get_result()->fetch_assoc();
    $fStmt->close();
    $deanFacultyName = $fRow ? (string) $fRow['name'] : '';
}
// This faculty's own current semester — not a single global settings
// value, and not just its academic_year_id (two of this faculty's own
// semesters can share one academic year, so filtering by year alone can
// mix an already-ended semester in with the current one).
$deanCurrentSemester = get_current_semester($conn, $deanFacultyId);
$currentSemesterId = (int) ($deanCurrentSemester['id'] ?? 0);

// ---------------------------------------------------------------------
// KPI cards
// ---------------------------------------------------------------------
$studentsStmt = $conn->prepare("SELECT COUNT(*) AS c FROM students WHERE faculty_id = ? AND status = 'active'");
$studentsStmt->bind_param('i', $deanFacultyId);
$studentsStmt->execute();
$studentsInFaculty = (int) ($studentsStmt->get_result()->fetch_assoc()['c'] ?? 0);
$studentsStmt->close();

$lecturersStmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM lecturers l JOIN departments d ON d.id = l.department_id
     WHERE d.faculty_id = ? AND l.status = 'active'"
);
$lecturersStmt->bind_param('i', $deanFacultyId);
$lecturersStmt->execute();
$lecturersInFaculty = (int) ($lecturersStmt->get_result()->fetch_assoc()['c'] ?? 0);
$lecturersStmt->close();

$departmentsStmt = $conn->prepare('SELECT COUNT(*) AS c FROM departments WHERE faculty_id = ?');
$departmentsStmt->bind_param('i', $deanFacultyId);
$departmentsStmt->execute();
$departmentsInFaculty = (int) ($departmentsStmt->get_result()->fetch_assoc()['c'] ?? 0);
$departmentsStmt->close();

$avgTodayStmt = $conn->prepare(
    "SELECT ROUND(100 * SUM(a.status = 'present') / COUNT(*), 1) AS pct
     FROM attendance a JOIN students s ON s.id = a.student_id
     WHERE s.faculty_id = ? AND a.attendance_date = CURDATE()"
);
$avgTodayStmt->bind_param('i', $deanFacultyId);
$avgTodayStmt->execute();
$avgTodayRow = $avgTodayStmt->get_result()->fetch_assoc();
$avgTodayStmt->close();
$avgAttendanceToday = $avgTodayRow && $avgTodayRow['pct'] !== null ? (float) $avgTodayRow['pct'] : null;

// ---------------------------------------------------------------------
// Departments in My Faculty table — grouped counts merged in PHP to
// avoid join fan-out (same pattern as reports.php's department summary).
// ---------------------------------------------------------------------
$departments = $conn->prepare('SELECT id, name FROM departments WHERE faculty_id = ? ORDER BY name');
$departments->bind_param('i', $deanFacultyId);
$departments->execute();
$departmentRows = $departments->get_result()->fetch_all(MYSQLI_ASSOC);
$departments->close();

$studentCountByDept = [];
$sStmt = $conn->prepare("SELECT department_id, COUNT(*) AS c FROM students WHERE faculty_id = ? AND status = 'active' GROUP BY department_id");
$sStmt->bind_param('i', $deanFacultyId);
$sStmt->execute();
$sRes = $sStmt->get_result();
while ($row = $sRes->fetch_assoc()) {
    $studentCountByDept[(int) $row['department_id']] = (int) $row['c'];
}
$sStmt->close();

$lecturerCountByDept = [];
$lStmt = $conn->prepare(
    "SELECT l.department_id, COUNT(*) AS c FROM lecturers l JOIN departments d ON d.id = l.department_id
     WHERE d.faculty_id = ? AND l.status = 'active' GROUP BY l.department_id"
);
$lStmt->bind_param('i', $deanFacultyId);
$lStmt->execute();
$lRes = $lStmt->get_result();
while ($row = $lRes->fetch_assoc()) {
    $lecturerCountByDept[(int) $row['department_id']] = (int) $row['c'];
}
$lStmt->close();

$avgAttendanceByDept = [];
if ($currentSemesterId > 0) {
    // Average of each (student, course) pair's own out-of-10 score — not a
    // pooled ratio — same "average of capped scores" semantics used by
    // reports.php's Department Summary. Only *regular* sessions count.
    $aStmt = $conn->prepare(
        "SELECT s.department_id, ROUND(AVG(t.present_score), 1) AS pct
         FROM (
             SELECT a.student_id, a.course_id,
                    LEAST(10, SUM(a.status = 'present')) AS present_score
             FROM attendance a
             JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
             WHERE sess.semester_id = ?
             GROUP BY a.student_id, a.course_id
         ) t
         JOIN students s ON s.id = t.student_id
         WHERE s.faculty_id = ?
         GROUP BY s.department_id"
    );
    $aStmt->bind_param('ii', $currentSemesterId, $deanFacultyId);
    $aStmt->execute();
    $aRes = $aStmt->get_result();
    while ($row = $aRes->fetch_assoc()) {
        $avgAttendanceByDept[(int) $row['department_id']] = (float) $row['pct'];
    }
    $aStmt->close();
}

// ---------------------------------------------------------------------
// Attendance by Semester (bar chart) — every semester in my faculty, not
// just the current one, joined through sessions (only Xiiso-based rows have
// a session_id, matching how the rest of the Semester/Xiiso system reports
// per-semester figures elsewhere in this app).
// ---------------------------------------------------------------------
$semesterChartLabels = [];
$semesterChartData = [];
// Average of each (student, course) pair's own out-of-10 score, per
// semester — not a pooled ratio — same semantics as the department query
// above. Only *regular* sessions count (Midterm/Final never do).
$semChartStmt = $conn->prepare(
    "SELECT sem.id, sem.name, ROUND(AVG(t.present_score), 1) AS pct
     FROM semesters sem
     JOIN (
         SELECT a.student_id, a.course_id, sess.semester_id,
                LEAST(10, SUM(a.status = 'present')) AS present_score
         FROM attendance a
         JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
         GROUP BY a.student_id, a.course_id, sess.semester_id
     ) t ON t.semester_id = sem.id
     JOIN students s ON s.id = t.student_id AND s.faculty_id = sem.faculty_id
     WHERE sem.faculty_id = ?
     GROUP BY sem.id, sem.name
     ORDER BY sem.id"
);
$semChartStmt->bind_param('i', $deanFacultyId);
$semChartStmt->execute();
$semChartRows = $semChartStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$semChartStmt->close();
foreach ($semChartRows as $row) {
    $semesterChartLabels[] = $row['name'];
    $semesterChartData[] = (float) $row['pct'];
}

// Attendance by Department (pie chart) — reshapes the $avgAttendanceByDept
// data already computed above for the Departments table, so no new query.
$deptChartLabels = [];
$deptChartData = [];
foreach ($departmentRows as $d) {
    $did = (int) $d['id'];
    if (isset($avgAttendanceByDept[$did])) {
        $deptChartLabels[] = $d['name'];
        $deptChartData[] = $avgAttendanceByDept[$did];
    }
}

// ---------------------------------------------------------------------
// Low Attendance — My Faculty (same live query shape as notifications.php,
// scoped to this Dean's own faculty)
// ---------------------------------------------------------------------
$lowAttendanceAlerts = [];
if ($currentSemesterId > 0) {
    $alertsStmt = $conn->prepare(
        "SELECT s.full_name, s.student_no, c.name AS course_name,
                LEAST(10, SUM(a.status = 'present')) AS attendance_pct
         FROM attendance a
         JOIN students s ON s.id = a.student_id
         JOIN courses c ON c.id = a.course_id
         JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
         WHERE sess.semester_id = ? AND s.faculty_id = ?
         GROUP BY s.id, a.course_id
         HAVING attendance_pct < ?
         ORDER BY attendance_pct ASC
         LIMIT 8"
    );
    $alertsStmt->bind_param('iid', $currentSemesterId, $deanFacultyId, $minAttendancePct);
    $alertsStmt->execute();
    $lowAttendanceAlerts = $alertsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $alertsStmt->close();
}

// ---------------------------------------------------------------------
// Class Time Table — every scheduled course_offerings row within this
// faculty's own current semester (any course, including one cross-listed
// INTO this faculty — the semester_id itself is faculty-scoped, so this
// can never surface another faculty's schedule).
// ---------------------------------------------------------------------
$deanTimetableRows = [];
if ($currentSemesterId > 0) {
    $ttStmt = $conn->prepare(
        "SELECT c.code, c.name AS course_name, co.day_of_week, co.start_time, co.end_time, co.room, l.full_name AS lecturer_name
         FROM course_offerings co
         JOIN courses c ON c.id = co.course_id
         LEFT JOIN lecturers l ON l.id = co.lecturer_id
         WHERE co.semester_id = ? AND co.day_of_week IS NOT NULL AND co.start_time IS NOT NULL AND co.end_time IS NOT NULL"
    );
    $ttStmt->bind_param('i', $currentSemesterId);
    $ttStmt->execute();
    $deanTimetableRows = $ttStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ttStmt->close();
}
$deanTimetableGrid = build_class_timetable_grid($deanTimetableRows);
$dashboardLogoRelativePath = get_university_logo_relative_path($settings);
$printDayOrder = array_values(array_diff(DAY_OF_WEEK_DISPLAY_ORDER, ['friday']));

// ---------------------------------------------------------------------
// Students per Department (doughnut) — reuses $studentCountByDept above,
// same chart type as University Rector's own "Students per Faculty"
// chart, scaled down one level since Dean's own scope is one faculty.
// ---------------------------------------------------------------------
$deptStudentChartLabels = [];
$deptStudentChartData = [];
foreach ($departmentRows as $d) {
    $deptStudentChartLabels[] = $d['name'];
    $deptStudentChartData[] = $studentCountByDept[(int) $d['id']] ?? 0;
}

// ---------------------------------------------------------------------
// Lecturer Workload (Current Semester, own faculty only) — top 8
// lecturers by number of CURRENT course_offerings, same chart as
// admin/dashboard.php's own, scoped to this Dean's faculty.
// ---------------------------------------------------------------------
$lecturerWorkloadStmt = $conn->prepare(
    "SELECT l.full_name, u.photo_path, COUNT(*) AS c
     FROM course_offerings co
     JOIN semesters se ON se.id = co.semester_id AND se.status = 'current'
     JOIN lecturers l ON l.id = co.lecturer_id
     JOIN users u ON u.id = l.user_id
     JOIN departments d ON d.id = l.department_id
     WHERE d.faculty_id = ?
     GROUP BY l.id, l.full_name, u.photo_path
     ORDER BY c DESC, l.full_name
     LIMIT 8"
);
$lecturerWorkloadStmt->bind_param('i', $deanFacultyId);
$lecturerWorkloadStmt->execute();
$lecturerWorkload = $lecturerWorkloadStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lecturerWorkloadStmt->close();
// Lecturer Check-In Ranking (own faculty only) — top 8 lecturers by total
// Check-Ins this current semester, most first — same shape as
// admin/dashboard.php's own, scoped to this Dean's faculty.
$lecturerCheckinStmt = $conn->prepare(
    "SELECT l.full_name, u.photo_path, COUNT(*) AS c
     FROM lecturer_checkins lc
     JOIN lecturers l ON l.id = lc.lecturer_id
     JOIN users u ON u.id = l.user_id
     JOIN departments d ON d.id = l.department_id
     JOIN sessions sess ON sess.id = lc.session_id
     JOIN semesters se ON se.id = sess.semester_id AND se.status = 'current'
     WHERE d.faculty_id = ?
     GROUP BY l.id, l.full_name, u.photo_path
     ORDER BY c DESC, l.full_name
     LIMIT 8"
);
$lecturerCheckinStmt->bind_param('i', $deanFacultyId);
$lecturerCheckinStmt->execute();
$lecturerCheckinRanking = $lecturerCheckinStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lecturerCheckinStmt->close();

// ---------------------------------------------------------------------
// Student registration trend — last 6 months, own faculty only.
// ---------------------------------------------------------------------
$registrationTrendLabels = [];
$registrationTrendData = [];
$regByMonth = [];
$regStmt = $conn->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
     FROM students
     WHERE faculty_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY ym"
);
$regStmt->bind_param('i', $deanFacultyId);
$regStmt->execute();
$regRes = $regStmt->get_result();
while ($row = $regRes->fetch_assoc()) {
    $regByMonth[$row['ym']] = (int) $row['c'];
}
$regStmt->close();
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-{$i} months"));
    $registrationTrendLabels[] = date('M Y', strtotime($ym . '-01'));
    $registrationTrendData[] = $regByMonth[$ym] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dean Dashboard — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        /* Matches admin/dashboard.php's own density exactly, so the Dean
           dashboard fits on the same laptop screen without scrolling —
           fixed-height chart boxes (paired with Chart.js's
           maintainAspectRatio: false below) plus scroll-capped list/table
           panels instead of growing with the data. */
        .dash-chart-box {
            position: relative;
            height: 140px;
        }

        .dash-alerts-box {
            max-height: 160px;
            overflow-y: auto;
        }

        .dash-table-box {
            max-height: 200px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                Access scope: <?= htmlspecialchars($deanFacultyName) ?> Faculty only
            </div>
            <?php if ($deanCurrentSemester): ?>
                <div class="semester-scope-banner">
                    <i class="bi bi-calendar-week"></i>
                    Showing: <?= htmlspecialchars((string) $deanCurrentSemester['name']) ?> (current, <?= htmlspecialchars($deanFacultyName) ?>)
                </div>
            <?php else: ?>
                <div class="semester-scope-banner">
                    <i class="bi bi-calendar-week"></i>
                    No current semester set for <?= htmlspecialchars($deanFacultyName) ?> yet
                </div>
            <?php endif; ?>

            <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Welcome back, <?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></h4>
            <p class="text-muted mb-2">Here's what's happening in <?= htmlspecialchars($deanFacultyName) ?> today.</p>

            <!-- KPI Cards -->
            <div class="row g-3 mb-3">
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="admas-card kpi-card accent-sky h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($studentsInFaculty) ?></div>
                            <div class="kpi-label">Students in Faculty</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php" class="admas-card kpi-card accent-navy h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-person-badge-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($lecturersInFaculty) ?></div>
                            <div class="kpi-label">Lecturers in Faculty</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/departments.php" class="admas-card kpi-card accent-green h-100">
                        <div class="kpi-icon bg-green"><i class="bi bi-diagram-3-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($departmentsInFaculty) ?></div>
                            <div class="kpi-label">Departments</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/reports.php" class="admas-card kpi-card accent-amber h-100">
                        <div class="kpi-icon bg-amber"><i class="bi bi-graph-up-arrow"></i></div>
                        <div>
                            <div class="kpi-value"><?= $avgAttendanceToday === null ? '—' : number_format($avgAttendanceToday, 1) . '%' ?></div>
                            <div class="kpi-label">Avg Attendance Today</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
            </div>

            <div class="row g-3 mb-0">
                <div class="col-xl-8">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase text-muted">Departments in My Faculty</h6>
                        <div class="table-responsive dash-table-box">
                            <table class="table admas-table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Students</th>
                                        <th>Lecturers</th>
                                        <th>Avg Attendance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($departmentRows)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No departments exist in this faculty yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($departmentRows as $d): ?>
                                            <?php $deptId = (int) $d['id']; ?>
                                            <tr>
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($d['name']) ?></td>
                                                <td><?= number_format($studentCountByDept[$deptId] ?? 0) ?></td>
                                                <td><?= number_format($lecturerCountByDept[$deptId] ?? 0) ?></td>
                                                <td>
                                                    <?php if (isset($avgAttendanceByDept[$deptId])): ?>
                                                        <span class="badge-pill <?= attendance_badge_class($avgAttendanceByDept[$deptId], $minAttendancePct) ?>"><?= number_format($avgAttendanceByDept[$deptId], 1) ?>%</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase text-muted">
                            <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                            Low Attendance — My Faculty
                        </h6>
                        <?php if (empty($lowAttendanceAlerts)): ?>
                            <p class="text-muted small mb-0">No students in your faculty are currently below the <?= htmlspecialchars((string) $minAttendancePct) ?>% attendance threshold.</p>
                        <?php else: ?>
                            <div class="dash-alerts-box">
                                <?php foreach ($lowAttendanceAlerts as $alert): ?>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/notifications.php" class="alert-row" title="Open Notifications to notify this student">
                                        <div>
                                            <div class="alert-student-name"><?= htmlspecialchars((string) $alert['full_name']) ?></div>
                                            <div class="alert-student-meta"><?= htmlspecialchars((string) $alert['student_no']) ?> &middot; <?= htmlspecialchars((string) $alert['course_name']) ?></div>
                                        </div>
                                        <div class="alert-pct"><?= number_format((float) $alert['attendance_pct'], 1) ?>%</div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Same additional oversight charts as University Rector's own
                 dashboard (Students count, Lecturer Workload, Student
                 Registrations), scoped one level down since Dean's own
                 scope is a single faculty — per-department instead of
                 per-faculty. Placed above the attendance charts below, per
                 explicit request. -->
            <div class="row g-3 mt-0">
                <div class="col-xl-3 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Students per Department</h6>
                        <?php if (empty($deptStudentChartLabels)): ?>
                            <p class="text-muted small mb-0">No departments exist in this faculty yet.</p>
                        <?php else: ?>
                            <div class="dash-chart-box">
                                <canvas id="deptStudentChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Lecturer Workload</h6>
                        <?php if (empty($lecturerWorkload)): ?>
                            <p class="text-muted small mb-0">No lecturers in this faculty currently have an assigned offering this semester.</p>
                        <?php else: ?>
                            <div class="dash-rank-list">
                                <?php foreach ($lecturerWorkload as $lw): ?>
                                    <?php render_dash_rank_row($lw['photo_path'], $lw['full_name'], (int) $lw['c'], 'var(--admas-sky)'); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">
                            <i class="bi bi-door-open-fill"></i> Lecturer Check-In Ranking
                        </h6>
                        <?php if (empty($lecturerCheckinRanking)): ?>
                            <p class="text-muted small mb-0">No lecturers in this faculty have checked in yet this semester.</p>
                        <?php else: ?>
                            <div class="dash-rank-list">
                                <?php foreach ($lecturerCheckinRanking as $lr): ?>
                                    <?php render_dash_rank_row($lr['photo_path'], $lr['full_name'], (int) $lr['c'], '#16a34a'); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Registrations (6mo)</h6>
                        <div class="dash-chart-box">
                            <canvas id="registrationTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts — fixed-height boxes (see .dash-chart-box) paired
                 with Chart.js's maintainAspectRatio: false, so both charts
                 stay compact and fit alongside everything above without
                 the page needing to scroll, per explicit request. -->
            <div class="row g-3 mt-0">
                <div class="col-xl-8">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Attendance by Semester</h6>
                        <?php if (empty($semesterChartLabels)): ?>
                            <p class="text-muted small mb-0">No Xiiso attendance recorded yet for any semester in this faculty.</p>
                        <?php else: ?>
                            <div class="dash-chart-box">
                                <canvas id="semesterBarChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Attendance by Department</h6>
                        <?php if (empty($deptChartLabels)): ?>
                            <p class="text-muted small mb-0">No current-semester attendance data yet.</p>
                        <?php else: ?>
                            <div class="dash-chart-box">
                                <canvas id="deptPieChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="admas-card p-2 timetable-print-card timetable-print-compact mt-3">
                <div class="timetable-print-header">
                    <img src="<?= htmlspecialchars(BASE_URL . '/' . $dashboardLogoRelativePath) ?>" alt="" class="timetable-print-logo">
                    <div class="timetable-print-header-text">
                        <div class="timetable-print-university"><?= htmlspecialchars(mb_strtoupper((string) ($settings['university_name'] ?? 'ADMAS University'))) ?></div>
                        <div class="timetable-print-faculty">Faculty: <?= htmlspecialchars($deanFacultyName) ?></div>
                        <?php if ($deanCurrentSemester): ?>
                            <div class="timetable-print-year"><?= htmlspecialchars((string) $deanCurrentSemester['name']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="timetable-print-meta">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/class_timetable.php" class="timetable-print-title text-decoration-none">Class Time Table</a>
                </div>
                <?php if (empty($deanTimetableGrid['time_slots'])): ?>
                    <p class="text-muted small mb-0 py-2">No scheduled class times have been set for <?= htmlspecialchars($deanFacultyName) ?> yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <?php render_class_timetable_grid_table($deanTimetableGrid, $printDayOrder, 'course_name', 'timetable-print-table'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        const cssVar = (name, fallback) => {
            const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            return v || fallback;
        };
        const chartSky = cssVar('--admas-sky', '#0ea5e9');
        const chartTextMuted = cssVar('--admas-text-muted', '#64748b');
        const chartGrid = cssVar('--admas-border', '#e2e8f0');
        const chartSurface = cssVar('--admas-surface', '#ffffff');
        const pieColors = ['#0ea5e9', '#6366f1', '#22c55e', '#f59e0b', '#ec4899', '#14b8a6', '#a855f7', '#ef4444', '#84cc16', '#0891b2'];

        <?php if (!empty($semesterChartLabels)): ?>
        new Chart(document.getElementById('semesterBarChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($semesterChartLabels) ?>,
                datasets: [{
                    label: 'Attendance %',
                    data: <?= json_encode($semesterChartData) ?>,
                    backgroundColor: chartSky,
                    borderRadius: 6,
                    maxBarThickness: 48,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: chartTextMuted }, grid: { display: false } },
                    y: {
                        min: 0,
                        max: 10,
                        ticks: { color: chartTextMuted, stepSize: 1, callback: (value) => value + '%' },
                        grid: { color: chartGrid },
                    },
                },
            },
        });
        <?php endif; ?>

        <?php if (!empty($deptChartLabels)): ?>
        new Chart(document.getElementById('deptPieChart'), {
            type: 'pie',
            data: {
                labels: <?= json_encode($deptChartLabels) ?>,
                datasets: [{
                    label: 'Attendance %',
                    data: <?= json_encode($deptChartData) ?>,
                    backgroundColor: pieColors,
                    borderColor: chartSurface,
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { color: chartTextMuted, boxWidth: 12, font: { size: 11 } } },
                    tooltip: { callbacks: { label: (item) => `${item.label}: ${item.formattedValue}%` } },
                },
            },
        });
        <?php endif; ?>

        <?php if (!empty($deptStudentChartLabels)): ?>
        new Chart(document.getElementById('deptStudentChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($deptStudentChartLabels) ?>,
                datasets: [{
                    label: 'Students',
                    data: <?= json_encode($deptStudentChartData) ?>,
                    backgroundColor: pieColors,
                    borderColor: chartSurface,
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { color: chartTextMuted, boxWidth: 12, font: { size: 11 } } },
                },
            },
        });
        <?php endif; ?>

        new Chart(document.getElementById('registrationTrendChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode($registrationTrendLabels) ?>,
                datasets: [{
                    label: 'Students Registered',
                    data: <?= json_encode($registrationTrendData) ?>,
                    borderColor: chartSky,
                    backgroundColor: chartSky,
                    tension: 0.3,
                    fill: false,
                    pointRadius: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: chartTextMuted }, grid: { display: false } },
                    y: { ticks: { color: chartTextMuted, precision: 0 }, grid: { color: chartGrid } },
                },
            },
        });
    </script>
</body>
</html>
