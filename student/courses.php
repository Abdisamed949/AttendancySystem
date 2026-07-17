<?php
/**
 * My Courses (Student only) — the courses this student takes, plus their
 * own attendance % in each. Resolved via current_user()['id'] ->
 * students.user_id, never from request input (same pattern as
 * student/dashboard.php).
 *
 * Course discovery: tries `course_enrollments` first (the real enrollment
 * record); if this student has zero enrollment rows, falls back to every
 * course in the student's own department — the same
 * enrolled-or-department-fallback assumption attendance.php's roster query
 * already makes in the opposite direction (course -> students).
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
// Own students.id + department_id + faculty_id (never trusted from input)
// ---------------------------------------------------------------------
$ownStmt = $conn->prepare('SELECT id, department_id, faculty_id FROM students WHERE user_id = ?');
$ownStmt->bind_param('i', $currentUser['id']);
$ownStmt->execute();
$ownRow = $ownStmt->get_result()->fetch_assoc();
$ownStmt->close();
$ownStudentId = $ownRow ? (int) $ownRow['id'] : 0;
$ownDepartmentId = $ownRow ? (int) $ownRow['department_id'] : 0;

// "Current academic year" is resolved from this student's own faculty's
// current semester, not a single global settings value.
$ownCurrentSemester = $ownRow ? get_current_semester($conn, (int) $ownRow['faculty_id']) : null;
$currentAcademicYearId = (int) ($ownCurrentSemester['academic_year_id'] ?? 0);

// ---------------------------------------------------------------------
// Course discovery: course_enrollments first, department fallback second.
// ---------------------------------------------------------------------
$courseIds = [];
$discoveryMethod = 'none';
if ($ownStudentId > 0) {
    $enrollStmt = $conn->prepare('SELECT course_id FROM course_enrollments WHERE student_id = ?');
    $enrollStmt->bind_param('i', $ownStudentId);
    $enrollStmt->execute();
    $enrollRes = $enrollStmt->get_result();
    while ($row = $enrollRes->fetch_assoc()) {
        $courseIds[] = (int) $row['course_id'];
    }
    $enrollStmt->close();

    if (!empty($courseIds)) {
        $discoveryMethod = 'course_enrollments';
    } elseif ($ownDepartmentId > 0) {
        // Fallback: course_enrollments is still unpopulated for this student
        // — assume every course in their own department is theirs, the same
        // way attendance.php's roster falls back to department/year/shift
        // matching when a course has no enrollment rows yet.
        $deptCourseStmt = $conn->prepare('SELECT id FROM courses WHERE department_id = ?');
        $deptCourseStmt->bind_param('i', $ownDepartmentId);
        $deptCourseStmt->execute();
        $deptCourseRes = $deptCourseStmt->get_result();
        while ($row = $deptCourseRes->fetch_assoc()) {
            $courseIds[] = (int) $row['id'];
        }
        $deptCourseStmt->close();
        $discoveryMethod = 'department_fallback';
    }
}

// ---------------------------------------------------------------------
// Course details + this student's own attendance % in each (for the
// current academic year, if one is set)
// ---------------------------------------------------------------------
$courses = [];
if (!empty($courseIds)) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    // Lecturer shown is whoever currently holds this course's offering for
    // ITS OWN faculty's current semester (not the deprecated permanent
    // courses.lecturer_id) — "Unassigned" if there's no current offering
    // or no current semester set for that faculty yet.
    $sql = "SELECT c.id, c.code, c.name, l.full_name AS lecturer_name,
                   SUM(a.status = 'present') AS present_count,
                   COUNT(a.id) AS total_marks
            FROM courses c
            JOIN departments d ON d.id = c.department_id
            LEFT JOIN semesters se ON se.faculty_id = d.faculty_id AND se.is_current = 1
            LEFT JOIN course_offerings co ON co.course_id = c.id AND co.semester_id = se.id
            LEFT JOIN lecturers l ON l.id = co.lecturer_id
            LEFT JOIN attendance a ON a.course_id = c.id AND a.student_id = ? AND a.academic_year_id = ?
            WHERE c.id IN ({$placeholders})
            GROUP BY c.id, c.code, c.name, l.full_name
            ORDER BY c.code";
    $stmt = $conn->prepare($sql);
    $types = 'ii' . str_repeat('i', count($courseIds));
    $params = array_merge([$ownStudentId, $currentAcademicYearId], $courseIds);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses — ADMAS Attendance System</title>
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

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">My Courses</h4>
                    <p class="text-muted mb-0">Courses you're enrolled in and your attendance % in each.</p>
                </div>
            </div>

            <div class="admas-card p-4">
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Name</th>
                                <th>Lecturer</th>
                                <th>Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courses)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">You are not enrolled in any courses yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courses as $c): ?>
                                    <?php
                                    $totalMarks = (int) $c['total_marks'];
                                    $pct = $totalMarks > 0 ? round(100 * (int) $c['present_count'] / $totalMarks, 1) : null;
                                    ?>
                                    <tr>
                                        <td><span class="badge-pill badge-active"><?= htmlspecialchars($c['code']) ?></span></td>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($c['name']) ?></td>
                                        <td>
                                            <?php if ($c['lecturer_name']): ?>
                                                <?= htmlspecialchars($c['lecturer_name']) ?>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
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
