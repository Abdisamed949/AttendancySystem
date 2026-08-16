<?php
/**
 * University Rector dashboard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/semester_helpers.php';

require_role(['university_rector']);

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
// KPI cards
// ---------------------------------------------------------------------
$totalStudents = (int) ($conn->query("SELECT COUNT(*) AS c FROM students WHERE status = 'active'")->fetch_assoc()['c'] ?? 0);
$totalLecturers = (int) ($conn->query("SELECT COUNT(*) AS c FROM lecturers WHERE status = 'active'")->fetch_assoc()['c'] ?? 0);
$activeCourses = (int) ($conn->query('SELECT COUNT(*) AS c FROM courses')->fetch_assoc()['c'] ?? 0);

$todayResult = $conn->query(
    "SELECT ROUND(100 * SUM(status = 'present') / COUNT(*), 1) AS pct FROM attendance WHERE attendance_date = CURDATE()"
);
$todayRow = $todayResult ? $todayResult->fetch_assoc() : null;
$avgAttendanceToday = $todayRow && $todayRow['pct'] !== null ? (float) $todayRow['pct'] : null;

// ---------------------------------------------------------------------
// Weekly attendance trend (last 7 days) for the Chart.js line chart
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
// Attendance alerts widget: prefer the notifications table, fall back to
// a live computation from attendance if no notifications exist yet.
// ---------------------------------------------------------------------
$alerts = [];
$notifStmt = $conn->prepare(
    "SELECT s.full_name, s.student_no, c.name AS course_name, n.attendance_pct
     FROM notifications n
     JOIN students s ON s.id = n.student_id
     JOIN courses c ON c.id = n.course_id
     WHERE n.is_read = 0
     ORDER BY n.created_at DESC
     LIMIT 8"
);
if ($notifStmt) {
    $notifStmt->execute();
    $alerts = $notifStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $notifStmt->close();
}

if (empty($alerts)) {
    // Cross-faculty, so each faculty's own current SEMESTER is used (never
    // academic_year_id — two of a faculty's semesters can share one
    // academic year, so filtering by year alone can mix an already-ended
    // semester in with the current one; same fix as notifications.php).
    // Only *regular* Xiiso sessions count toward the score (out of 10) —
    // Midterm/Final never do.
    $liveAlerts = [];
    $facultiesForAlerts = $conn->query('SELECT id FROM faculties')->fetch_all(MYSQLI_ASSOC);
    foreach ($facultiesForAlerts as $f) {
        $facultyId = (int) $f['id'];
        $facultySemester = get_current_semester($conn, $facultyId);
        $facultySemesterId = (int) ($facultySemester['id'] ?? 0);
        if ($facultySemesterId <= 0) {
            continue;
        }

        $liveStmt = $conn->prepare(
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
        $liveStmt->bind_param('iid', $facultySemesterId, $facultyId, $minAttendancePct);
        $liveStmt->execute();
        $liveAlerts = array_merge($liveAlerts, $liveStmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $liveStmt->close();
    }
    usort($liveAlerts, static fn ($a, $b) => $a['attendance_pct'] <=> $b['attendance_pct']);
    $alerts = array_slice($liveAlerts, 0, 8);
}

// ---------------------------------------------------------------------
// Attendance by Department (pie chart) — university-wide. Each faculty has
// its own current semester/academic year (no single global "current year"),
// so this is one small query per faculty, merged, same pattern already used
// above for the live-alerts fallback.
// ---------------------------------------------------------------------
$departmentsForChart = $conn->query('SELECT id, name FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$avgAttendanceByDeptChart = [];
$facultiesForDeptChart = $conn->query('SELECT id FROM faculties')->fetch_all(MYSQLI_ASSOC);
foreach ($facultiesForDeptChart as $f) {
    $fid = (int) $f['id'];
    $fSem = get_current_semester($conn, $fid);
    $fSemesterId = (int) ($fSem['id'] ?? 0);
    if ($fSemesterId <= 0) {
        continue;
    }
    // Average of each (student, course) pair's own out-of-10 score — not a
    // pooled ratio — same "average of capped scores" semantics used by
    // reports.php's Department Summary. Only *regular* sessions count.
    $dStmt = $conn->prepare(
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
    $dStmt->bind_param('ii', $fSemesterId, $fid);
    $dStmt->execute();
    $dRes = $dStmt->get_result();
    while ($row = $dRes->fetch_assoc()) {
        $avgAttendanceByDeptChart[(int) $row['department_id']] = (float) $row['pct'];
    }
    $dStmt->close();
}

$deptChartLabels = [];
$deptChartData = [];
foreach ($departmentsForChart as $d) {
    $did = (int) $d['id'];
    if (isset($avgAttendanceByDeptChart[$did])) {
        $deptChartLabels[] = $d['name'];
        $deptChartData[] = $avgAttendanceByDeptChart[$did];
    }
}

// ---------------------------------------------------------------------
// Attendance by Faculty (bar chart) — university-wide oversight for the
// Rector role: one score per faculty, each resolved against that
// faculty's own current semester (never a single shared "current" value,
// same per-faculty pattern used by the department chart above and by
// head_academic/dashboard.php's own Attendance-by-Faculty section — reused
// here rather than reinvented). Average of each (student, course) pair's
// own capped out-of-10 score, only counting *regular* Xiiso sessions.
// ---------------------------------------------------------------------
$allFacultiesForChart = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$facultyChartLabels = [];
$facultyChartData = [];
foreach ($allFacultiesForChart as $f) {
    $fid = (int) $f['id'];
    $fSem = get_current_semester($conn, $fid);
    $fSemesterId = (int) ($fSem['id'] ?? 0);
    if ($fSemesterId <= 0) {
        continue;
    }
    $fStmt = $conn->prepare(
        "SELECT ROUND(AVG(t.present_score), 1) AS pct
         FROM (
             SELECT a.student_id, a.course_id,
                    LEAST(10, SUM(a.status = 'present')) AS present_score
             FROM attendance a
             JOIN students s ON s.id = a.student_id
             JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
             WHERE sess.semester_id = ? AND s.faculty_id = ?
             GROUP BY a.student_id, a.course_id
         ) t"
    );
    $fStmt->bind_param('ii', $fSemesterId, $fid);
    $fStmt->execute();
    $fRow = $fStmt->get_result()->fetch_assoc();
    $fStmt->close();
    if ($fRow && $fRow['pct'] !== null) {
        $facultyChartLabels[] = $f['name'];
        $facultyChartData[] = (float) $fRow['pct'];
    }
}

// ---------------------------------------------------------------------
// Students per Faculty (doughnut chart) — simple headcount, university-wide.
// ---------------------------------------------------------------------
$studentsPerFacultyResult = $conn->query(
    "SELECT f.name, COUNT(s.id) AS c
     FROM faculties f
     LEFT JOIN students s ON s.faculty_id = f.id AND s.status = 'active'
     GROUP BY f.id, f.name
     ORDER BY f.name"
)->fetch_all(MYSQLI_ASSOC);
$studentsPerFacultyLabels = array_map(static fn ($r) => $r['name'], $studentsPerFacultyResult);
$studentsPerFacultyData = array_map(static fn ($r) => (int) $r['c'], $studentsPerFacultyResult);

// ---------------------------------------------------------------------
// Lecturer workload (horizontal bar) — top 8 lecturers by number of
// CURRENT course_offerings (any faculty's semester whose own status is
// 'current'), so it reflects who is actually teaching the most right now,
// not a lifetime historical count.
// ---------------------------------------------------------------------
$lecturerWorkload = $conn->query(
    "SELECT l.full_name, COUNT(*) AS c
     FROM course_offerings co
     JOIN semesters se ON se.id = co.semester_id AND se.status = 'current'
     JOIN lecturers l ON l.id = co.lecturer_id
     GROUP BY l.id, l.full_name
     ORDER BY c DESC, l.full_name
     LIMIT 8"
)->fetch_all(MYSQLI_ASSOC);
$lecturerWorkloadLabels = array_map(static fn ($r) => $r['full_name'], $lecturerWorkload);
$lecturerWorkloadData = array_map(static fn ($r) => (int) $r['c'], $lecturerWorkload);

// ---------------------------------------------------------------------
// Student registration trend — students added per month, last 6 months
// (students.created_at). Skipped if there is no meaningful spread of data
// (e.g. every student was added in the same bulk import) — checked below
// by simply rendering whatever the query returns; a flat single-month bar
// is still a truthful (if unexciting) chart, not fabricated.
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
    <title>Admin Dashboard — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        /* Fixed-height chart boxes (paired with Chart.js's maintainAspectRatio:
           false in the script below) so all 6 charts + the alerts panel stay
           compact and fit on one screen together, per explicit request —
           without this, each chart would grow to whatever height its own
           aspect ratio implied and the page would need scrolling. */
        .dash-chart-box {
            position: relative;
            height: 140px;
        }

        .dash-alerts-box {
            max-height: 140px;
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
                Access scope: Full system — all faculties, departments, and courses
            </div>

            <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Welcome back, <?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></h4>
            <p class="text-muted mb-4">Here's what's happening across ADMAS University today.</p>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="admas-card kpi-card accent-sky h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalStudents) ?></div>
                            <div class="kpi-label">Total Students</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php" class="admas-card kpi-card accent-navy h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-person-badge-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalLecturers) ?></div>
                            <div class="kpi-label">Total Lecturers</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="admas-card kpi-card accent-green h-100">
                        <div class="kpi-icon bg-green"><i class="bi bi-journal-bookmark-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($activeCourses) ?></div>
                            <div class="kpi-label">Active Courses</div>
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

            <!-- Charts + Alerts — every chart below is a fixed-height box
                 (see .dash-chart-box) with Chart.js's maintainAspectRatio
                 set to false, and this whole section uses 4 narrow columns
                 instead of 2 wide ones, specifically so all 6 charts + the
                 alerts panel fit on one screen without scrolling on a
                 normal desktop viewport, per explicit request. -->
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
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Attendance by Faculty</h6>
                        <?php if (empty($facultyChartLabels)): ?>
                            <p class="text-muted small mb-0">No current-semester attendance data yet.</p>
                        <?php else: ?>
                            <div class="dash-chart-box">
                                <canvas id="facultyAttendanceChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
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
                        <?php if (empty($lecturerWorkloadLabels)): ?>
                            <p class="text-muted small mb-0">No lecturers currently have an assigned offering this semester.</p>
                        <?php else: ?>
                            <div class="dash-chart-box">
                                <canvas id="lecturerWorkloadChart"></canvas>
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
                <div class="col-xl-3 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">
                            <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                            Attendance Alerts
                        </h6>
                        <div class="dash-alerts-box">
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
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        // Read the current theme's own colors so charts stay readable in
        // both light and dark mode instead of baking in fixed light-mode hex
        // values (see the dark-mode text-contrast fixes this app already has
        // for .text-muted/.admas-table — Chart.js has no CSS-variable support
        // of its own, so this has to be read manually at render time).
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
                plugins: {
                    legend: { display: false },
                },
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
                    legend: {
                        position: 'bottom',
                        labels: { color: chartTextMuted, boxWidth: 12, font: { size: 11 } },
                    },
                    tooltip: {
                        callbacks: { label: (item) => `${item.label}: ${item.formattedValue}%` },
                    },
                },
            },
        });
        <?php endif; ?>

        <?php if (!empty($facultyChartLabels)): ?>
        new Chart(document.getElementById('facultyAttendanceChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($facultyChartLabels) ?>,
                datasets: [{
                    label: 'Avg Score (out of 10)',
                    data: <?= json_encode($facultyChartData) ?>,
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
                    y: { min: 0, max: 10, ticks: { color: chartTextMuted }, grid: { color: chartGrid } },
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
                    legend: {
                        position: 'bottom',
                        labels: { color: chartTextMuted, boxWidth: 12, font: { size: 11 } },
                    },
                },
            },
        });
        <?php endif; ?>

        <?php if (!empty($lecturerWorkloadLabels)): ?>
        new Chart(document.getElementById('lecturerWorkloadChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($lecturerWorkloadLabels) ?>,
                datasets: [{
                    label: 'Current Course Offerings',
                    data: <?= json_encode($lecturerWorkloadData) ?>,
                    backgroundColor: chartSky,
                    borderRadius: 6,
                    maxBarThickness: 28,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: chartTextMuted, precision: 0 }, grid: { color: chartGrid } },
                    y: { ticks: { color: chartTextMuted }, grid: { display: false } },
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
                    y: { min: 0, ticks: { color: chartTextMuted, precision: 0 }, grid: { color: chartGrid } },
                },
            },
        });
    </script>
</body>
</html>
