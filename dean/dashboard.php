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

// "Current academic year" is now resolved from this faculty's own current
// semester, not a single global settings value — different faculties can
// be on different semesters/years at once.
$deanCurrentSemester = get_current_semester($conn, $deanFacultyId);
$currentAcademicYearId = (int) ($deanCurrentSemester['academic_year_id'] ?? 0);

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
if ($currentAcademicYearId > 0) {
    $aStmt = $conn->prepare(
        "SELECT s.department_id, ROUND(100 * SUM(a.status = 'present') / COUNT(*), 1) AS pct
         FROM attendance a JOIN students s ON s.id = a.student_id
         WHERE s.faculty_id = ? AND a.academic_year_id = ?
         GROUP BY s.department_id"
    );
    $aStmt->bind_param('ii', $deanFacultyId, $currentAcademicYearId);
    $aStmt->execute();
    $aRes = $aStmt->get_result();
    while ($row = $aRes->fetch_assoc()) {
        $avgAttendanceByDept[(int) $row['department_id']] = (float) $row['pct'];
    }
    $aStmt->close();
}

// ---------------------------------------------------------------------
// Low Attendance — My Faculty (same live query shape as notifications.php,
// scoped to this Dean's own faculty)
// ---------------------------------------------------------------------
$lowAttendanceAlerts = [];
if ($currentAcademicYearId > 0) {
    $alertsStmt = $conn->prepare(
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
    $alertsStmt->bind_param('iid', $currentAcademicYearId, $deanFacultyId, $minAttendancePct);
    $alertsStmt->execute();
    $lowAttendanceAlerts = $alertsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $alertsStmt->close();
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

            <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Welcome back, <?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></h4>
            <p class="text-muted mb-4">Here's what's happening in <?= htmlspecialchars($deanFacultyName) ?> today.</p>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($studentsInFaculty) ?></div>
                            <div class="kpi-label">Students in Faculty</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-person-badge-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($lecturersInFaculty) ?></div>
                            <div class="kpi-label">Lecturers in Faculty</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-green"><i class="bi bi-diagram-3-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($departmentsInFaculty) ?></div>
                            <div class="kpi-label">Departments</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-amber"><i class="bi bi-graph-up-arrow"></i></div>
                        <div>
                            <div class="kpi-value"><?= $avgAttendanceToday === null ? '—' : number_format($avgAttendanceToday, 1) . '%' ?></div>
                            <div class="kpi-label">Avg Attendance Today</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-xl-8">
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Departments in My Faculty</h6>
                        <div class="table-responsive">
                            <table class="table admas-table align-middle">
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
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">
                            <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                            Low Attendance — My Faculty
                        </h6>
                        <?php if (empty($lowAttendanceAlerts)): ?>
                            <p class="text-muted small mb-0">No students in your faculty are currently below the <?= htmlspecialchars((string) $minAttendancePct) ?>% attendance threshold.</p>
                        <?php else: ?>
                            <?php foreach ($lowAttendanceAlerts as $alert): ?>
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
</body>
</html>
