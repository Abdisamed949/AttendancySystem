<?php
/**
 * Class Time Table (Student only) — this student's own current-semester
 * weekly schedule, styled to match ADMAS's real printed Class Time Table
 * (logo/name/campus header, Faculty/Year/Semester line, Day x Time grid,
 * REGISTRAR signature line). Read-only — the actual Day/Time/Room fields
 * are set on admin/course_offerings.php/lecturer_courses.php by Dean/Head
 * of Academic Affairs; this page only shows the student's own resulting
 * schedule, same "own record only" scope as every other student page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/university_logo.php';
require_once __DIR__ . '/../includes/semester_helpers.php';
require_once __DIR__ . '/../includes/timetable_helpers.php';

require_role(['student']);

$conn = db();
$currentUser = current_user();

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$universityName = $settings['university_name'] ?? 'ADMAS University';
$campusLine = $settings['campus'] ?? 'Garoowe Campus';
$logoRelativePath = get_university_logo_relative_path($settings);

$ownStmt = $conn->prepare(
    'SELECT s.id, s.shift, s.faculty_id, s.department_id, s.semester_id,
            f.name AS faculty_name, f.semesters_per_year, d.name AS department_name
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
$ownShift = $ownRow ? (string) $ownRow['shift'] : 'morning';
$ownFacultyId = $ownRow ? (int) $ownRow['faculty_id'] : 0;
$ownDepartmentId = $ownRow ? (int) $ownRow['department_id'] : 0;

// Own current semester (per-cohort resolution, same as student/dashboard.php)
$ownCurrentSemester = null;
if ($ownFacultyId > 0) {
    $curSemStmt = $conn->prepare(
        "SELECT se.id, se.name, se.start_date FROM semesters se
         JOIN students s2 ON s2.id = ?
         WHERE se.faculty_id = ? AND se.status = 'current' AND se.academic_year_id = (
             SELECT s3.academic_year_id FROM students s3 WHERE s3.id = ?
         )
         ORDER BY se.id DESC LIMIT 1"
    );
    $curSemStmt->bind_param('iii', $ownStudentId, $ownFacultyId, $ownStudentId);
    $curSemStmt->execute();
    $ownCurrentSemester = $curSemStmt->get_result()->fetch_assoc();
    $curSemStmt->close();

    if (!$ownCurrentSemester) {
        $ownCurrentSemester = get_current_semester($conn, $ownFacultyId);
    }
}
$currentSemesterId = (int) ($ownCurrentSemester['id'] ?? 0);
$semesterYearNumber = $ownCurrentSemester
    ? semester_year_number((string) $ownCurrentSemester['name'], (int) ($ownRow['semesters_per_year'] ?? 3))
    : null;

// Same course discovery as student/dashboard.php/student/courses.php:
// course_enrollments, department fallback, guest-offering roster_department.
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

    if (empty($courseIds)) {
        $deptCourseStmt = $conn->prepare('SELECT id FROM courses WHERE department_id = ?');
        $deptCourseStmt->bind_param('i', $ownDepartmentId);
        $deptCourseStmt->execute();
        $deptCourseRes = $deptCourseStmt->get_result();
        while ($row = $deptCourseRes->fetch_assoc()) {
            $courseIds[] = (int) $row['id'];
        }
        $deptCourseStmt->close();
    }

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

$myTimetableRows = [];
if ($ownStudentId > 0 && $currentSemesterId > 0 && !empty($courseIds)) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $sql = "SELECT c.code, c.name AS course_name, co.day_of_week, co.start_time, co.end_time, co.room, l.full_name AS lecturer_name
            FROM courses c
            JOIN course_offerings co ON co.id = (
                SELECT co2.id FROM course_offerings co2
                WHERE co2.course_id = c.id AND co2.semester_id = ? AND (co2.shift = ? OR co2.shift = 'any')
                ORDER BY (co2.shift = ?) DESC
                LIMIT 1
            )
            LEFT JOIN lecturers l ON l.id = co.lecturer_id
            WHERE c.id IN ({$placeholders})
              AND co.day_of_week IS NOT NULL AND co.start_time IS NOT NULL AND co.end_time IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $types = 'sss' . str_repeat('i', count($courseIds));
    $params = array_merge([$currentSemesterId, $ownShift, $ownShift], $courseIds);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $myTimetableRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
$myTimetableGrid = build_class_timetable_grid($myTimetableRows);

// Reference table only shows the Sat-Thu teaching week (no Friday column).
$printDayOrder = array_values(array_diff(DAY_OF_WEEK_DISPLAY_ORDER, ['friday']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Time Table — ADMAS Attendance System</title>
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

            <div class="admas-card p-4 timetable-print-card">
                <div class="timetable-print-header">
                    <img src="<?= htmlspecialchars(BASE_URL . '/' . $logoRelativePath) ?>" alt="<?= htmlspecialchars($universityName) ?> logo" class="timetable-print-logo">
                    <div class="timetable-print-header-text">
                        <div class="timetable-print-university"><?= htmlspecialchars(mb_strtoupper($universityName)) ?></div>
                        <div class="timetable-print-campus"><?= htmlspecialchars($campusLine) ?></div>
                        <div class="timetable-print-faculty"><?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></div>
                        <div class="timetable-print-faculty">Faculty: <?= htmlspecialchars((string) ($ownRow['faculty_name'] ?? '—')) ?></div>
                        <?php if ($ownCurrentSemester): ?>
                            <div class="timetable-print-year">
                                <?= $semesterYearNumber !== null ? 'Year ' . $semesterYearNumber . '  ' : '' ?><?= htmlspecialchars((string) $ownCurrentSemester['name']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="timetable-print-meta">
                    <span class="timetable-print-title">Class Time Table</span>
                    <?php if ($ownCurrentSemester && !empty($ownCurrentSemester['start_date'])): ?>
                        <span>Starting Date <?= htmlspecialchars((string) $ownCurrentSemester['start_date']) ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!$ownCurrentSemester): ?>
                    <p class="text-muted small py-3">No current semester is set for your faculty yet.</p>
                <?php elseif (empty($myTimetableGrid['time_slots'])): ?>
                    <p class="text-muted small py-3">No scheduled class times have been set for your courses yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <?php render_class_timetable_grid_table($myTimetableGrid, $printDayOrder, 'course_name', 'timetable-print-table'); ?>
                    </div>
                <?php endif; ?>

                <div class="timetable-print-signature">REGISTRAR</div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
