<?php
/**
 * Head of Academic Affairs dashboard — cross-faculty (university-wide) KPIs
 * and an Attendance-by-Faculty breakdown. The "Register New Lecturer" quick
 * -add form used to live here but has moved to head_academic/lecturers.php
 * so it's reachable from the "Lecturers" sidebar item instead of only from
 * the dashboard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/semester_helpers.php';
require_once __DIR__ . '/../includes/timetable_helpers.php';
require_once __DIR__ . '/../includes/university_logo.php';
require_once __DIR__ . '/../includes/avatar_helpers.php';

require_role(['head_academic']);

$conn = db();
$currentUser = current_user();

// ---------------------------------------------------------------------
// University settings (needed for the Attendance Alerts threshold)
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
// KPI cards
// ---------------------------------------------------------------------
$totalFaculties = (int) ($conn->query('SELECT COUNT(*) AS c FROM faculties')->fetch_assoc()['c'] ?? 0);
$totalDepartments = (int) ($conn->query('SELECT COUNT(*) AS c FROM departments')->fetch_assoc()['c'] ?? 0);
$totalStudents = (int) ($conn->query("SELECT COUNT(*) AS c FROM students WHERE status = 'active'")->fetch_assoc()['c'] ?? 0);

$todayResult = $conn->query(
    "SELECT ROUND(100 * SUM(status = 'present') / COUNT(*), 1) AS pct FROM attendance WHERE attendance_date = CURDATE()"
);
$todayRow = $todayResult ? $todayResult->fetch_assoc() : null;
$universityAvgAttendance = $todayRow && $todayRow['pct'] !== null ? (float) $todayRow['pct'] : null;

// ---------------------------------------------------------------------
// Weekly attendance (last 7 days) for the Bar chart — university-wide, same
// unscoped query as admin/dashboard.php's own weekly chart.
// ---------------------------------------------------------------------
$weeklyByDate = [];
$weekStmt = $conn->prepare(
    "SELECT attendance_date, ROUND(100 * SUM(status = 'present') / COUNT(*), 1) AS pct
     FROM attendance
     WHERE attendance_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
     GROUP BY attendance_date"
);
if ($weekStmt) {
    $weekStmt->execute();
    $weekResult = $weekStmt->get_result();
    while ($row = $weekResult->fetch_assoc()) {
        $weeklyByDate[$row['attendance_date']] = (float) $row['pct'];
    }
    $weekStmt->close();
}

$trendLabels = [];
$trendData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $trendLabels[] = date('D', strtotime($date));
    $trendData[] = $weeklyByDate[$date] ?? 0;
}

// ---------------------------------------------------------------------
// Attendance by Faculty — each faculty has its own current semester (and
// therefore its own current academic year), so this is a genuine
// cross-faculty aggregate: one small query per faculty against that
// faculty's own current academic year, rather than one shared global year.
// A faculty with no current semester set yet simply shows "—".
// ---------------------------------------------------------------------
$faculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$avgAttendanceByFaculty = [];
// Attendance by Department (pie chart) — university-wide, accumulated in the
// same per-faculty-current-year loop above rather than a second pass.
$departmentsForChart = $conn->query('SELECT id, name FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$avgAttendanceByDeptChart = [];
// Attendance Alerts widget — same per-student/per-course below-threshold
// query shape as admin/dashboard.php's own alerts widget and
// notifications.php, accumulated in this same per-faculty loop.
$alerts = [];
// Which semester each faculty's own KPIs/charts below reflect — shown as a
// per-faculty banner near the top, since there's no single university-wide
// "current semester" (each faculty runs its own independent track).
$currentSemesterByFacultyName = [];
$hoaaTimetableSemesterIds = [];
foreach ($faculties as $f) {
    $facultyId = (int) $f['id'];
    $facultyCurrentSemester = get_current_semester($conn, $facultyId);
    $currentSemesterByFacultyName[(string) $f['name']] = $facultyCurrentSemester['name'] ?? null;
    $facultySemesterId = (int) ($facultyCurrentSemester['id'] ?? 0);
    if ($facultySemesterId <= 0) {
        continue;
    }
    $hoaaTimetableSemesterIds[] = $facultySemesterId;

    // Average of each (student, course) pair's own out-of-10 score — not a
    // pooled ratio — same "average of capped scores" semantics used by
    // reports.php's Faculty/Department Summary. Only *regular* sessions
    // count (Midterm/Final never do).
    $scoreSql = "SELECT a.student_id, a.course_id,
                        LEAST(10, SUM(a.status = 'present')) AS present_score
                 FROM attendance a
                 JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
                 WHERE sess.semester_id = ?
                 GROUP BY a.student_id, a.course_id";

    $facAttStmt = $conn->prepare(
        "SELECT ROUND(AVG(t.present_score), 1) AS pct
         FROM ({$scoreSql}) t
         JOIN students s ON s.id = t.student_id
         WHERE s.faculty_id = ?"
    );
    $facAttStmt->bind_param('ii', $facultySemesterId, $facultyId);
    $facAttStmt->execute();
    $facAttRow = $facAttStmt->get_result()->fetch_assoc();
    $facAttStmt->close();
    if ($facAttRow && $facAttRow['pct'] !== null) {
        $avgAttendanceByFaculty[$facultyId] = (float) $facAttRow['pct'];
    }

    $deptAttStmt = $conn->prepare(
        "SELECT s.department_id, ROUND(AVG(t.present_score), 1) AS pct
         FROM ({$scoreSql}) t
         JOIN students s ON s.id = t.student_id
         WHERE s.faculty_id = ?
         GROUP BY s.department_id"
    );
    $deptAttStmt->bind_param('ii', $facultySemesterId, $facultyId);
    $deptAttStmt->execute();
    $deptAttRes = $deptAttStmt->get_result();

    $alertStmt = $conn->prepare(
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
    $alertStmt->bind_param('iid', $facultySemesterId, $facultyId, $minAttendancePct);
    $alertStmt->execute();
    $alerts = array_merge($alerts, $alertStmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $alertStmt->close();

    while ($row = $deptAttRes->fetch_assoc()) {
        $avgAttendanceByDeptChart[(int) $row['department_id']] = (float) $row['pct'];
    }
    $deptAttStmt->close();
}
usort($alerts, static fn ($a, $b) => $a['attendance_pct'] <=> $b['attendance_pct']);
$alerts = array_slice($alerts, 0, 8);

$deptChartLabels = [];
$deptChartData = [];
foreach ($departmentsForChart as $d) {
    $did = (int) $d['id'];
    if (isset($avgAttendanceByDeptChart[$did])) {
        $deptChartLabels[] = $d['name'];
        $deptChartData[] = $avgAttendanceByDeptChart[$did];
    }
}

$studentCountByFaculty = [];
$fscRes = $conn->query("SELECT faculty_id, COUNT(*) AS c FROM students WHERE status = 'active' GROUP BY faculty_id");
while ($row = $fscRes->fetch_assoc()) {
    $studentCountByFaculty[(int) $row['faculty_id']] = (int) $row['c'];
}

// ---------------------------------------------------------------------
// Class Time Table — every scheduled course_offerings row across every
// faculty's own current semester (university-wide, matching this
// dashboard's own scope) — accumulated from $hoaaTimetableSemesterIds
// above, so a course scheduled under any faculty's current semester shows
// up, not just one faculty's.
// ---------------------------------------------------------------------
$hoaaTimetableRows = [];
if (!empty($hoaaTimetableSemesterIds)) {
    $ttPlaceholders = implode(',', array_fill(0, count($hoaaTimetableSemesterIds), '?'));
    $ttStmt = $conn->prepare(
        "SELECT c.code, c.name AS course_name, co.day_of_week, co.start_time, co.end_time, co.room, l.full_name AS lecturer_name
         FROM course_offerings co
         JOIN courses c ON c.id = co.course_id
         LEFT JOIN lecturers l ON l.id = co.lecturer_id
         WHERE co.semester_id IN ({$ttPlaceholders}) AND co.day_of_week IS NOT NULL AND co.start_time IS NOT NULL AND co.end_time IS NOT NULL"
    );
    $ttStmt->bind_param(str_repeat('i', count($hoaaTimetableSemesterIds)), ...$hoaaTimetableSemesterIds);
    $ttStmt->execute();
    $hoaaTimetableRows = $ttStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ttStmt->close();
}
$hoaaTimetableGrid = build_class_timetable_grid($hoaaTimetableRows);
$dashboardLogoRelativePath = get_university_logo_relative_path($settings);
$printDayOrder = array_values(array_diff(DAY_OF_WEEK_DISPLAY_ORDER, ['friday']));

// ---------------------------------------------------------------------
// Students per Faculty (doughnut) — reuses $studentCountByFaculty above,
// same chart type/shape as admin/dashboard.php's own University Rector
// oversight chart, since this role's scope is equally university-wide.
// ---------------------------------------------------------------------
$studentsPerFacultyLabels = [];
$studentsPerFacultyData = [];
foreach ($faculties as $f) {
    $studentsPerFacultyLabels[] = $f['name'];
    $studentsPerFacultyData[] = $studentCountByFaculty[(int) $f['id']] ?? 0;
}

// ---------------------------------------------------------------------
// Lecturer Workload (Current Semester, university-wide) — top 8 lecturers
// by number of CURRENT course_offerings, same query as admin/dashboard.php.
// ---------------------------------------------------------------------
$lecturerWorkload = $conn->query(
    "SELECT l.full_name, u.photo_path, COUNT(*) AS c
     FROM course_offerings co
     JOIN semesters se ON se.id = co.semester_id AND se.status = 'current'
     JOIN lecturers l ON l.id = co.lecturer_id
     JOIN users u ON u.id = l.user_id
     GROUP BY l.id, l.full_name, u.photo_path
     ORDER BY c DESC, l.full_name
     LIMIT 8"
)->fetch_all(MYSQLI_ASSOC);
// Lecturer Check-In Ranking — top 8 lecturers by total Check-Ins this
// current semester, most first (same shape as admin/dashboard.php's own).
$lecturerCheckinRanking = $conn->query(
    "SELECT l.full_name, u.photo_path, COUNT(*) AS c
     FROM lecturer_checkins lc
     JOIN lecturers l ON l.id = lc.lecturer_id
     JOIN users u ON u.id = l.user_id
     JOIN sessions sess ON sess.id = lc.session_id
     JOIN semesters se ON se.id = sess.semester_id AND se.status = 'current'
     GROUP BY l.id, l.full_name, u.photo_path
     ORDER BY c DESC, l.full_name
     LIMIT 8"
)->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------------
// Student registration trend — last 6 months, university-wide, same query
// as admin/dashboard.php.
// ---------------------------------------------------------------------
$registrationTrendLabels = [];
$registrationTrendData = [];
$regByMonth = [];
$regResult = $conn->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
     FROM students
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY ym"
);
if ($regResult) {
    while ($row = $regResult->fetch_assoc()) {
        $regByMonth[$row['ym']] = (int) $row['c'];
    }
}
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
    <title>Head of Academic Affairs Dashboard — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        .dash-chart-box {
            position: relative;
            height: 160px;
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
                Access scope: All faculties (cross-faculty)
            </div>
            <div class="semester-scope-banner" title="Each faculty runs its own independent semester track — there is no single university-wide current semester.">
                <i class="bi bi-calendar-week"></i>
                Showing each faculty's own current semester:
                <?php
                $semesterBannerParts = [];
                foreach ($currentSemesterByFacultyName as $facName => $semName) {
                    $semesterBannerParts[] = htmlspecialchars($facName) . ' (' . ($semName !== null ? htmlspecialchars((string) $semName) : 'none set') . ')';
                }
                echo implode(', ', $semesterBannerParts);
                ?>
            </div>

            <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Welcome back, <?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></h4>
            <p class="text-muted mb-4">Here's what's happening across ADMAS University today.</p>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/reports.php?report_type=faculty_summary" class="admas-card kpi-card accent-sky h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-bank"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalFaculties) ?></div>
                            <div class="kpi-label">Faculties</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/reports.php?report_type=department_summary" class="admas-card kpi-card accent-navy h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-diagram-3-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalDepartments) ?></div>
                            <div class="kpi-label">Departments</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/reports.php?report_type=department_summary" class="admas-card kpi-card accent-green h-100">
                        <div class="kpi-icon bg-green"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalStudents) ?></div>
                            <div class="kpi-label">Students (University-wide)</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/reports.php" class="admas-card kpi-card accent-amber h-100">
                        <div class="kpi-icon bg-amber"><i class="bi bi-graph-up-arrow"></i></div>
                        <div>
                            <div class="kpi-value"><?= $universityAvgAttendance === null ? '—' : number_format($universityAvgAttendance, 1) . '%' ?></div>
                            <div class="kpi-label">University Avg Attendance Today</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
            </div>

            <!-- Same additional oversight charts as University Rector's own
                 dashboard (Students per Faculty, Lecturer Workload, Student
                 Registrations) — this role's scope is equally
                 university-wide, so the same per-faculty breakdowns apply.
                 Placed above the attendance charts/tables below, per
                 explicit request. -->
            <div class="row g-3 mb-3">
                <div class="col-xl-3 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Students per Faculty</h6>
                        <?php if (empty($studentsPerFacultyLabels)): ?>
                            <p class="text-muted small mb-0">No faculties exist yet.</p>
                        <?php else: ?>
                            <div class="dash-chart-box">
                                <canvas id="studentsPerFacultyChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Lecturer Workload</h6>
                        <?php if (empty($lecturerWorkload)): ?>
                            <p class="text-muted small mb-0">No lecturers currently have an assigned offering this semester.</p>
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
                            <p class="text-muted small mb-0">No lecturers have checked in yet this semester.</p>
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
                 with Chart.js's maintainAspectRatio: false, so charts stay
                 compact and fit alongside everything else on one screen
                 without scrolling, per explicit request. -->
            <div class="row g-3">
                <div class="col-xl-3 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Weekly Attendance</h6>
                        <div class="dash-chart-box">
                            <canvas id="weeklyAttendanceChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
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
                <div class="col-xl-3 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase text-muted">Attendance by Faculty</h6>
                        <div class="table-responsive">
                            <table class="table admas-table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Faculty</th>
                                        <th>Students</th>
                                        <th>Avg</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($faculties)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-4">No faculties exist yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($faculties as $f): ?>
                                            <?php $fid = (int) $f['id']; ?>
                                            <tr>
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($f['name']) ?></td>
                                                <td><?= number_format($studentCountByFaculty[$fid] ?? 0) ?></td>
                                                <td>
                                                    <?php if (isset($avgAttendanceByFaculty[$fid])): ?>
                                                        <?= number_format($avgAttendanceByFaculty[$fid], 1) ?>%
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
                <div class="col-xl-3 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase text-muted">
                            <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                            Attendance Alerts
                        </h6>
                        <?php if (empty($alerts)): ?>
                            <p class="text-muted small mb-0">No students are currently below the <?= htmlspecialchars((string) $minAttendancePct) ?>% attendance threshold.</p>
                        <?php else: ?>
                            <?php foreach ($alerts as $alert): ?>
                                <a href="<?= htmlspecialchars(BASE_URL) ?>/notifications.php" class="alert-row" title="Open Notifications to notify this student">
                                    <div>
                                        <div class="alert-student-name"><?= htmlspecialchars((string) $alert['full_name']) ?></div>
                                        <div class="alert-student-meta"><?= htmlspecialchars((string) $alert['student_no']) ?> &middot; <?= htmlspecialchars((string) $alert['course_name']) ?></div>
                                    </div>
                                    <div class="alert-pct"><?= number_format((float) $alert['attendance_pct'], 1) ?>%</div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="admas-card p-2 timetable-print-card timetable-print-compact mt-3">
                <div class="timetable-print-header">
                    <img src="<?= htmlspecialchars(BASE_URL . '/' . $dashboardLogoRelativePath) ?>" alt="" class="timetable-print-logo">
                    <div class="timetable-print-header-text">
                        <div class="timetable-print-university"><?= htmlspecialchars(mb_strtoupper((string) ($settings['university_name'] ?? 'ADMAS University'))) ?></div>
                        <div class="timetable-print-faculty">All Faculties</div>
                    </div>
                </div>
                <div class="timetable-print-meta">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/class_timetable.php" class="timetable-print-title text-decoration-none">Class Time Table</a>
                </div>
                <?php if (empty($hoaaTimetableGrid['time_slots'])): ?>
                    <p class="text-muted small mb-0 py-2">No scheduled class times have been set yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <?php render_class_timetable_grid_table($hoaaTimetableGrid, $printDayOrder, 'course_name', 'timetable-print-table'); ?>
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

        new Chart(document.getElementById('weeklyAttendanceChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($trendLabels) ?>,
                datasets: [{
                    label: 'Attendance %',
                    data: <?= json_encode($trendData) ?>,
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
                        max: 100,
                        ticks: { color: chartTextMuted, callback: (value) => value + '%' },
                        grid: { color: chartGrid },
                    },
                },
            },
        });

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

        <?php if (!empty($studentsPerFacultyLabels)): ?>
        new Chart(document.getElementById('studentsPerFacultyChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($studentsPerFacultyLabels) ?>,
                datasets: [{
                    label: 'Students',
                    data: <?= json_encode($studentsPerFacultyData) ?>,
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
