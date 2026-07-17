<?php
/**
 * Registration Office dashboard — university-wide (all faculties),
 * enrollment-focused. No Attendance/Settings access per CLAUDE.md §4.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';

require_role(['registration']);

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
// Recent Student Registrations (last 10, across all faculties)
// ---------------------------------------------------------------------
$recentStudents = $conn->query(
    "SELECT s.student_no, s.full_name, s.created_at, f.name AS faculty_name, d.name AS department_name
     FROM students s
     JOIN faculties f ON f.id = s.faculty_id
     JOIN departments d ON d.id = s.department_id
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
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalStudents) ?></div>
                            <div class="kpi-label">Total Registered Students</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-bank"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalFaculties) ?></div>
                            <div class="kpi-label">Faculties</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-green"><i class="bi bi-diagram-3-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalDepartments) ?></div>
                            <div class="kpi-label">Departments</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-amber"><i class="bi bi-person-plus-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($studentsAddedThisMonth) ?></div>
                            <div class="kpi-label">Added This Month</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admas-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Recent Student Registrations</h6>
                    <div class="d-flex gap-2">
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php" class="btn btn-outline-secondary btn-sm">
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
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($s['full_name']) ?></td>
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
</body>
</html>
