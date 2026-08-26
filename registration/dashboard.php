<?php
/**
 * Registration Office dashboard — university-wide (all faculties),
 * enrollment-focused. No Attendance/Settings access per CLAUDE.md §4.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/avatar_helpers.php';

require_role(['registration']);

$conn = db();
$currentUser = current_user();

const SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

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

// ---------------------------------------------------------------------
// KPI cards — only cheaply-computable, real counts (no import-log
// tracking exists in the schema, so that KPI is intentionally omitted).
// ---------------------------------------------------------------------
$totalStudents = (int) ($conn->query("SELECT COUNT(*) AS c FROM students WHERE status = 'active'")->fetch_assoc()['c'] ?? 0);
$totalFaculties = (int) ($conn->query('SELECT COUNT(*) AS c FROM faculties')->fetch_assoc()['c'] ?? 0);
$totalDepartments = (int) ($conn->query('SELECT COUNT(*) AS c FROM departments')->fetch_assoc()['c'] ?? 0);

$addedThisMonthStmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM students WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);
$addedThisMonthStmt->execute();
$studentsAddedThisMonth = (int) ($addedThisMonthStmt->get_result()->fetch_assoc()['c'] ?? 0);
$addedThisMonthStmt->close();

// ---------------------------------------------------------------------
// Enrollment stats charts — students per faculty, students per shift, and
// the registration trend over the last 6 months. Reuses the exact same
// query shapes already established on admin/dashboard.php's own oversight
// charts, scoped down to what's actually relevant/visible to Registration
// Office (no lecturer/attendance data — this role has none of that access).
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

$studentsByShiftResult = $conn->query(
    "SELECT shift, COUNT(*) AS c FROM students WHERE status = 'active' GROUP BY shift"
)->fetch_all(MYSQLI_ASSOC);
$studentsByShiftLabels = array_map(static fn ($r) => SHIFT_LABELS[$r['shift']] ?? $r['shift'], $studentsByShiftResult);
$studentsByShiftData = array_map(static fn ($r) => (int) $r['c'], $studentsByShiftResult);

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

// ---------------------------------------------------------------------
// Recent Student Registrations (last 10, across all faculties)
// ---------------------------------------------------------------------
$recentStudents = $conn->query(
    "SELECT s.student_no, s.full_name, s.created_at, f.name AS faculty_name, d.name AS department_name, u.photo_path
     FROM students s
     JOIN faculties f ON f.id = s.faculty_id
     JOIN departments d ON d.id = s.department_id
     JOIN users u ON u.id = s.user_id
     ORDER BY s.created_at DESC, s.id DESC
     LIMIT 10"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Office Dashboard — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        /* Fixed-height chart boxes, same convention as admin/dashboard.php's
           own oversight charts (paired with Chart.js's maintainAspectRatio:
           false below) so the three enrollment charts sit at a consistent
           height instead of each growing to its own aspect ratio. */
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
                Access scope: All faculties — enrollment-focused
            </div>

            <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Welcome back, <?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></h4>
            <p class="text-muted mb-4">Here's the latest student registration activity across ADMAS University.</p>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="admas-card kpi-card accent-sky h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalStudents) ?></div>
                            <div class="kpi-label">Total Registered Students</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/reports.php?report_type=faculty_summary" class="admas-card kpi-card accent-navy h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-bank"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalFaculties) ?></div>
                            <div class="kpi-label">Faculties</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/reports.php?report_type=department_summary" class="admas-card kpi-card accent-green h-100">
                        <div class="kpi-icon bg-green"><i class="bi bi-diagram-3-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalDepartments) ?></div>
                            <div class="kpi-label">Departments</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="admas-card kpi-card accent-amber h-100">
                        <div class="kpi-icon bg-amber"><i class="bi bi-person-plus-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($studentsAddedThisMonth) ?></div>
                            <div class="kpi-label">Added This Month</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
            </div>

            <!-- Enrollment Stats -->
            <div class="row g-3 mb-4">
                <div class="col-xl-4 col-md-6">
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
                <div class="col-xl-4 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Students per Shift</h6>
                        <?php if (empty($studentsByShiftLabels)): ?>
                            <p class="text-muted small mb-0">No students registered yet.</p>
                        <?php else: ?>
                            <div class="dash-chart-box">
                                <canvas id="studentsByShiftChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6">
                    <div class="admas-card p-3 h-100">
                        <h6 class="fw-bold mb-2 small text-uppercase" style="color: var(--admas-text);">Registrations (6mo)</h6>
                        <div class="dash-chart-box">
                            <canvas id="registrationTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admas-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Recent Student Registrations</h6>
                    <div class="d-flex gap-2">
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                            <i class="bi bi-file-earmark-arrow-up"></i> Import from Excel
                        </a>
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                            <i class="bi bi-plus-lg"></i> Add Student
                        </a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Student No</th>
                                <th>Full Name</th>
                                <th>Faculty</th>
                                <th>Department</th>
                                <th>Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentStudents)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No students have been registered yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentStudents as $s): ?>
                                    <tr>
                                        <td><span class="badge-pill badge-active"><?= htmlspecialchars($s['student_no']) ?></span></td>
                                        <td><?php render_person_avatar_cell($s['photo_path'] ?? null, (string) $s['full_name'], (string) $s['student_no']); ?></td>
                                        <td><?= htmlspecialchars($s['faculty_name']) ?></td>
                                        <td><?= htmlspecialchars($s['department_name']) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime((string) $s['created_at']))) ?></td>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        // Read the current theme's own colors so charts stay readable in both
        // light and dark mode instead of baking in fixed light-mode hex values
        // — same approach already used on admin/dashboard.php's own charts.
        const cssVar = (name, fallback) => {
            const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            return v || fallback;
        };
        const chartSky = cssVar('--admas-sky', '#0ea5e9');
        const chartTextMuted = cssVar('--admas-text-muted', '#64748b');
        const chartGrid = cssVar('--admas-border', '#e2e8f0');
        const chartSurface = cssVar('--admas-surface', '#ffffff');
        const pieColors = ['#0ea5e9', '#6366f1', '#22c55e', '#f59e0b', '#ec4899', '#14b8a6', '#a855f7', '#ef4444', '#84cc16', '#0891b2'];

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

        <?php if (!empty($studentsByShiftLabels)): ?>
        new Chart(document.getElementById('studentsByShiftChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($studentsByShiftLabels) ?>,
                datasets: [{
                    label: 'Students',
                    data: <?= json_encode($studentsByShiftData) ?>,
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
