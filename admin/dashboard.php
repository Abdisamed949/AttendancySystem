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
    // Cross-faculty, so each faculty's own current academic year is used
    // (never one shared global value) — one small query per faculty,
    // merged and re-sorted, since there's no longer a single "the" current
    // academic year to filter all of them by at once.
    $liveAlerts = [];
    $facultiesForAlerts = $conn->query('SELECT id FROM faculties')->fetch_all(MYSQLI_ASSOC);
    foreach ($facultiesForAlerts as $f) {
        $facultyId = (int) $f['id'];
        $facultySemester = get_current_semester($conn, $facultyId);
        $facultyAcademicYearId = (int) ($facultySemester['academic_year_id'] ?? 0);
        if ($facultyAcademicYearId <= 0) {
            continue;
        }

        $liveStmt = $conn->prepare(
            "SELECT s.full_name, s.student_no, c.name AS course_name,
                    ROUND(100 * SUM(a.status = 'present') / COUNT(*), 2) AS attendance_pct
             FROM attendance a
             JOIN students s ON s.id = a.student_id
             JOIN courses c ON c.id = a.course_id
             WHERE a.academic_year_id = ? AND s.faculty_id = ?
             GROUP BY s.id, a.course_id
             HAVING attendance_pct < ?
             ORDER BY attendance_pct ASC
             LIMIT 8"
        );
        $liveStmt->bind_param('iid', $facultyAcademicYearId, $facultyId, $minAttendancePct);
        $liveStmt->execute();
        $liveAlerts = array_merge($liveAlerts, $liveStmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $liveStmt->close();
    }
    usort($liveAlerts, static fn ($a, $b) => $a['attendance_pct'] <=> $b['attendance_pct']);
    $alerts = array_slice($liveAlerts, 0, 8);
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

            <!-- Chart + Alerts -->
            <div class="row g-3">
                <div class="col-xl-8">
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Weekly Attendance Trend</h6>
                        <canvas id="weeklyAttendanceChart" height="110"></canvas>
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
                                <div class="alert-row">
                                    <div>
                                        <div class="alert-student-name"><?= htmlspecialchars((string) $alert['full_name']) ?></div>
                                        <div class="alert-student-meta"><?= htmlspecialchars((string) $alert['student_no']) ?> &middot; <?= htmlspecialchars((string) $alert['course_name']) ?></div>
                                    </div>
                                    <div class="alert-pct"><?= number_format((float) $alert['attendance_pct'], 1) ?>%</div>
                                </div>
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
        const ctx = document.getElementById('weeklyAttendanceChart');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($trendLabels) ?>,
                datasets: [{
                    label: 'Attendance %',
                    data: <?= json_encode($trendData) ?>,
                    borderColor: '#0ea5e9',
                    backgroundColor: 'rgba(14, 165, 233, 0.12)',
                    tension: 0.35,
                    fill: true,
                    pointBackgroundColor: '#0ea5e9',
                    pointRadius: 4,
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                },
                scales: {
                    y: {
                        min: 0,
                        max: 100,
                        ticks: { callback: (value) => value + '%' },
                    },
                },
            },
        });
    </script>
</body>
</html>
