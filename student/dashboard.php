<?php
/**
 * Student dashboard — strictly scoped to this student's own record, never
 * another student's (resolved via current_user()['id'] -> students.user_id,
 * never from request input).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['student']);

$conn = db();
$currentUser = current_user();

// ---------------------------------------------------------------------
// University settings (drives the sky-blue top strip + threshold)
// ---------------------------------------------------------------------
$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$currentAcademicYearId = (int) ($settings['current_academic_year_id'] ?? 0);
$minAttendancePct = (float) ($settings['min_attendance_pct'] ?? 75);

// ---------------------------------------------------------------------
// Own students.id (never trusted from input)
// ---------------------------------------------------------------------
$ownStmt = $conn->prepare('SELECT id, full_name FROM students WHERE user_id = ?');
$ownStmt->bind_param('i', $currentUser['id']);
$ownStmt->execute();
$ownRow = $ownStmt->get_result()->fetch_assoc();
$ownStmt->close();
$ownStudentId = $ownRow ? (int) $ownRow['id'] : 0;

// ---------------------------------------------------------------------
// My Course Attendance — per course, for the current academic year
// ---------------------------------------------------------------------
$courseAttendance = [];
if ($ownStudentId > 0 && $currentAcademicYearId > 0) {
    $stmt = $conn->prepare(
        "SELECT c.id AS course_id, c.code, c.name,
                SUM(a.status = 'present') AS present_count,
                SUM(a.status = 'absent') AS absent_count,
                SUM(a.status = 'late') AS late_count,
                COUNT(*) AS total_marks
         FROM attendance a
         JOIN courses c ON c.id = a.course_id
         WHERE a.student_id = ? AND a.academic_year_id = ?
         GROUP BY c.id, c.code, c.name
         ORDER BY c.code"
    );
    $stmt->bind_param('ii', $ownStudentId, $currentAcademicYearId);
    $stmt->execute();
    $courseAttendance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ---------------------------------------------------------------------
// KPI cards
// ---------------------------------------------------------------------
$totalMarksAll = 0;
$totalPresentAll = 0;
$coursesBelowThreshold = 0;
foreach ($courseAttendance as $row) {
    $totalMarksAll += (int) $row['total_marks'];
    $totalPresentAll += (int) $row['present_count'];
    $pct = (int) $row['total_marks'] > 0 ? 100 * (int) $row['present_count'] / (int) $row['total_marks'] : 0;
    if ($pct < $minAttendancePct) {
        $coursesBelowThreshold++;
    }
}
$myAttendancePct = $totalMarksAll > 0 ? round(100 * $totalPresentAll / $totalMarksAll, 1) : null;

$enrolledCoursesCount = 0;
if ($ownStudentId > 0) {
    $enrolledStmt = $conn->prepare(
        'SELECT COUNT(DISTINCT course_id) AS c FROM (
            SELECT course_id FROM course_enrollments WHERE student_id = ?
            UNION
            SELECT course_id FROM attendance WHERE student_id = ?
         ) AS combined'
    );
    $enrolledStmt->bind_param('ii', $ownStudentId, $ownStudentId);
    $enrolledStmt->execute();
    $enrolledCoursesCount = (int) ($enrolledStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $enrolledStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard — ADMAS Attendance System</title>
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
                Access scope: Own personal record only
            </div>

            <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Welcome back, <?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></h4>
            <p class="text-muted mb-4">Here's a summary of your attendance this academic year.</p>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-4">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-graph-up-arrow"></i></div>
                        <div>
                            <div class="kpi-value"><?= $myAttendancePct === null ? '—' : number_format($myAttendancePct, 1) . '%' ?></div>
                            <div class="kpi-label">My Attendance %</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-journal-bookmark-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($enrolledCoursesCount) ?></div>
                            <div class="kpi-label">Enrolled Courses</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-amber"><i class="bi bi-exclamation-triangle-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($coursesBelowThreshold) ?></div>
                            <div class="kpi-label">Courses Below Threshold</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admas-card p-4">
                <h6 class="fw-bold mb-3" style="color: #0b1f3a;">My Course Attendance</h6>
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Late</th>
                                <th>Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courseAttendance)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No attendance records exist for you yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courseAttendance as $row): ?>
                                    <?php
                                    $totalMarks = (int) $row['total_marks'];
                                    $pct = $totalMarks > 0 ? round(100 * (int) $row['present_count'] / $totalMarks, 1) : 0.0;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($row['code'] . ' — ' . $row['name']) ?></td>
                                        <td><?= (int) $row['present_count'] ?></td>
                                        <td><?= (int) $row['absent_count'] ?></td>
                                        <td><?= (int) $row['late_count'] ?></td>
                                        <td><span class="badge-pill <?= attendance_badge_class($pct, $minAttendancePct) ?>"><?= number_format($pct, 1) ?>%</span></td>
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
