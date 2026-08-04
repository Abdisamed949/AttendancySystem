<?php
/**
 * System Administrator dashboard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/semester_helpers.php';

require_role(['system_admin']);

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

            <!-- Charts + Alerts -->
            <div class="row g-3">
                <div class="col-xl-5">
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Weekly Attendance (This Week)</h6>
                        <canvas id="weeklyAttendanceChart" height="150"></canvas>
                    </div>
                </div>
                <div class="col-xl-3">
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Attendance by Department</h6>
                        <?php if (empty($deptChartLabels)): ?>
                            <p class="text-muted small mb-0">No current-semester attendance data yet.</p>
                        <?php else: ?>
                            <canvas id="deptPieChart" height="220"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">
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
    </script>
</body>
</html>
