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
require_once __DIR__ . '/../includes/semester_helpers.php';

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
$minAttendancePct = (float) ($settings['min_attendance_pct'] ?? 75);

// ---------------------------------------------------------------------
// Own students.id + department_id + shift (never trusted from input) —
// department_id/shift are needed below for the same course-discovery
// logic student/courses.php already uses.
// ---------------------------------------------------------------------
$ownStmt = $conn->prepare(
    'SELECT s.id, s.full_name, s.faculty_id, s.department_id, s.shift, f.name AS faculty_name, d.name AS department_name
     FROM students s
     JOIN faculties f ON f.id = s.faculty_id
     JOIN departments d ON d.id = s.department_id
     WHERE s.user_id = ?'
);
$ownStmt->bind_param('i', $currentUser['id']);
$ownStmt->execute();
$ownRow = $ownStmt->get_result()->fetch_assoc();
$ownStmt->close();
$ownStudentId = $ownRow ? (int) $ownRow['id'] : 0;
$ownDepartmentId = $ownRow ? (int) $ownRow['department_id'] : 0;
$ownShift = (string) ($ownRow['shift'] ?? '');

// Resolved from this student's own faculty's current semester, not a
// single global settings value.
$ownCurrentSemester = $ownRow ? get_current_semester($conn, (int) $ownRow['faculty_id']) : null;
$currentSemesterId = (int) ($ownCurrentSemester['id'] ?? 0);

// ---------------------------------------------------------------------
// Course discovery — same three-source logic as student/courses.php,
// kept in sync deliberately: course_enrollments first; department
// fallback if that's empty; plus a third, additive source for a
// cross-listed/guest-faculty offering whose roster_department_id names
// this student's own department (see the Multi-Faculty Course Offerings
// work). Previously this dashboard only ever looked at real `attendance`
// rows directly, which meant a student's own current-semester courses
// were invisible here until someone had actually marked at least one
// session — correct data, but a confusing empty dashboard for a course
// that's really just "not marked yet".
// ---------------------------------------------------------------------
$courseIds = [];
if ($ownStudentId > 0) {
    $enrollStmt = $conn->prepare('SELECT course_id FROM course_enrollments WHERE student_id = ?');
    $enrollStmt->bind_param('i', $ownStudentId);
    $enrollStmt->execute();
    $enrollRes = $enrollStmt->get_result();
    while ($row = $enrollRes->fetch_assoc()) {
        $courseIds[] = (int) $row['course_id'];
    }
    $enrollStmt->close();

    if (empty($courseIds) && $ownDepartmentId > 0) {
        $deptCourseStmt = $conn->prepare('SELECT id FROM courses WHERE department_id = ?');
        $deptCourseStmt->bind_param('i', $ownDepartmentId);
        $deptCourseStmt->execute();
        $deptCourseRes = $deptCourseStmt->get_result();
        while ($row = $deptCourseRes->fetch_assoc()) {
            $courseIds[] = (int) $row['id'];
        }
        $deptCourseStmt->close();
    }

    if ($ownDepartmentId > 0) {
        $guestCourseStmt = $conn->prepare('SELECT DISTINCT course_id FROM course_offerings WHERE roster_department_id = ?');
        $guestCourseStmt->bind_param('i', $ownDepartmentId);
        $guestCourseStmt->execute();
        $guestCourseRes = $guestCourseStmt->get_result();
        while ($row = $guestCourseRes->fetch_assoc()) {
            $courseIds[] = (int) $row['course_id'];
        }
        $guestCourseStmt->close();
        $courseIds = array_values(array_unique($courseIds));
    }
}

// ---------------------------------------------------------------------
// My Course Attendance — every candidate course that has real evidence of
// belonging to the current SEMESTER specifically (not just the current
// academic year — a faculty's semesters share one academic_year_id, e.g.
// both "Semester 8" and "Semester 9" can be "2023/2024" at once, so
// filtering by academic_year_id alone would mix an already-completed
// semester in with the current one): either a course_offerings row for
// (course, current semester, this student's own shift or 'any'), or a
// real attendance record — same "real evidence" condition and shift
// correlated-subquery precedence as student/courses.php, so the two pages
// can never drift on what counts as "this student's course, this
// semester". A course can now show with zero marks yet ("No records
// yet") when only the offering exists — that's new, and deliberate: the
// student can see their real current course load before anyone has
// marked a single session.
// ---------------------------------------------------------------------
$courseAttendance = [];
if ($ownStudentId > 0 && $currentSemesterId > 0 && !empty($courseIds)) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $sql = "SELECT c.id AS course_id, c.code, c.name,
                   SUM(a.status = 'present') AS present_count,
                   SUM(a.status = 'absent') AS absent_count,
                   COUNT(a.id) AS total_marks
            FROM courses c
            LEFT JOIN course_offerings co ON co.id = (
                SELECT co2.id FROM course_offerings co2
                WHERE co2.course_id = c.id AND co2.semester_id = ? AND (co2.shift = ? OR co2.shift = 'any')
                ORDER BY (co2.shift = ?) DESC
                LIMIT 1
            )
            LEFT JOIN attendance a ON a.course_id = c.id AND a.student_id = ?
                AND a.session_id IN (SELECT id FROM sessions WHERE semester_id = ?)
            WHERE c.id IN ({$placeholders})
                AND (co.id IS NOT NULL OR a.id IS NOT NULL)
            GROUP BY c.id, c.code, c.name
            ORDER BY c.code";
    $stmt = $conn->prepare($sql);
    $types = 'issii' . str_repeat('i', count($courseIds));
    $params = array_merge([$currentSemesterId, $ownShift, $ownShift, $ownStudentId, $currentSemesterId], $courseIds);
    $stmt->bind_param($types, ...$params);
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
    // Only judge a course against the threshold once it has real marks —
    // a course with zero attendance recorded yet isn't "below threshold",
    // it's simply not marked yet.
    if ((int) $row['total_marks'] > 0) {
        $pct = 100 * (int) $row['present_count'] / (int) $row['total_marks'];
        if ($pct < $minAttendancePct) {
            $coursesBelowThreshold++;
        }
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

            <h4 class="fw-bold mb-2" style="color: var(--admas-text);">Welcome back, <?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></h4>
            <?php if ($ownRow): ?>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <span class="badge-pill badge-present fs-6 px-3 py-2">
                        <i class="bi bi-bank"></i> <?= htmlspecialchars((string) $ownRow['faculty_name']) ?>
                    </span>
                    <span class="badge-pill badge-neutral fs-6 px-3 py-2">
                        <i class="bi bi-diagram-3"></i> <?= htmlspecialchars((string) $ownRow['department_name']) ?>
                    </span>
                </div>
            <?php endif; ?>
            <p class="text-muted mb-4">Here's a summary of your attendance this academic year.</p>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-4">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/student/attendance_history.php" class="admas-card kpi-card accent-sky h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-graph-up-arrow"></i></div>
                        <div>
                            <div class="kpi-value"><?= $myAttendancePct === null ? '—' : number_format($myAttendancePct, 1) . '%' ?></div>
                            <div class="kpi-label">My Attendance %</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/student/courses.php" class="admas-card kpi-card accent-navy h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-journal-bookmark-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($enrolledCoursesCount) ?></div>
                            <div class="kpi-label">Enrolled Courses</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/student/courses.php" class="admas-card kpi-card accent-amber h-100">
                        <div class="kpi-icon bg-amber"><i class="bi bi-exclamation-triangle-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($coursesBelowThreshold) ?></div>
                            <div class="kpi-label">Courses Below Threshold</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
            </div>

            <div class="admas-card p-4">
                <h6 class="fw-bold mb-3" style="color: var(--admas-text);">My Course Attendance</h6>
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courseAttendance)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No courses recorded for this semester yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courseAttendance as $row): ?>
                                    <?php
                                    $totalMarks = (int) $row['total_marks'];
                                    $pct = $totalMarks > 0 ? round(100 * (int) $row['present_count'] / $totalMarks, 1) : null;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($row['code'] . ' — ' . $row['name']) ?></td>
                                        <td><?= (int) $row['present_count'] ?></td>
                                        <td><?= (int) $row['absent_count'] ?></td>
                                        <td>
                                            <?php if ($pct === null): ?>
                                                <span class="text-muted">No records yet</span>
                                            <?php else: ?>
                                                <span class="badge-pill <?= attendance_badge_class($pct, $minAttendancePct) ?>"><?= number_format($pct, 1) ?>%</span>
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
