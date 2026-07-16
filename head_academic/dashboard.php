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

require_role(['head_academic']);

$conn = db();
$currentUser = current_user();

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
// Attendance by Faculty — each faculty has its own current semester (and
// therefore its own current academic year), so this is a genuine
// cross-faculty aggregate: one small query per faculty against that
// faculty's own current academic year, rather than one shared global year.
// A faculty with no current semester set yet simply shows "—".
// ---------------------------------------------------------------------
$faculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$avgAttendanceByFaculty = [];
foreach ($faculties as $f) {
    $facultyId = (int) $f['id'];
    $facultyCurrentSemester = get_current_semester($conn, $facultyId);
    $facultyAcademicYearId = (int) ($facultyCurrentSemester['academic_year_id'] ?? 0);
    if ($facultyAcademicYearId <= 0) {
        continue;
    }

    $facAttStmt = $conn->prepare(
        "SELECT ROUND(100 * SUM(a.status = 'present') / COUNT(*), 1) AS pct
         FROM attendance a JOIN students s ON s.id = a.student_id
         WHERE s.faculty_id = ? AND a.academic_year_id = ?"
    );
    $facAttStmt->bind_param('ii', $facultyId, $facultyAcademicYearId);
    $facAttStmt->execute();
    $facAttRow = $facAttStmt->get_result()->fetch_assoc();
    $facAttStmt->close();
    if ($facAttRow && $facAttRow['pct'] !== null) {
        $avgAttendanceByFaculty[$facultyId] = (float) $facAttRow['pct'];
    }
}

$studentCountByFaculty = [];
$fscRes = $conn->query("SELECT faculty_id, COUNT(*) AS c FROM students WHERE status = 'active' GROUP BY faculty_id");
while ($row = $fscRes->fetch_assoc()) {
    $studentCountByFaculty[(int) $row['faculty_id']] = (int) $row['c'];
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

            <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Welcome back, <?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></h4>
            <p class="text-muted mb-4">Here's what's happening across ADMAS University today.</p>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-bank"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalFaculties) ?></div>
                            <div class="kpi-label">Faculties</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-diagram-3-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalDepartments) ?></div>
                            <div class="kpi-label">Departments</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-green"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalStudents) ?></div>
                            <div class="kpi-label">Students (University-wide)</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-amber"><i class="bi bi-graph-up-arrow"></i></div>
                        <div>
                            <div class="kpi-value"><?= $universityAvgAttendance === null ? '—' : number_format($universityAvgAttendance, 1) . '%' ?></div>
                            <div class="kpi-label">University Avg Attendance Today</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admas-card p-4">
                <h6 class="fw-bold mb-3" style="color: #0b1f3a;">Attendance by Faculty</h6>
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Faculty</th>
                                <th>Students</th>
                                <th>Avg Attendance</th>
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
                                        <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($f['name']) ?></td>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
