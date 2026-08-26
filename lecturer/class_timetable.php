<?php
/**
 * Class Time Table (Lecturer only) — this lecturer's own current-semester
 * weekly schedule across every course they currently hold an offering for,
 * styled to match ADMAS's real printed Class Time Table (same layout as
 * student/class_timetable.php). Read-only — the actual Day/Time/Room fields
 * are set on admin/course_offerings.php/lecturer_courses.php by Dean/Head
 * of Academic Affairs; this page only shows the lecturer's own resulting
 * schedule, reusing the exact same "My Assigned Courses" query already used
 * on lecturer/dashboard.php so the two can never drift.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/university_logo.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';
require_once __DIR__ . '/../includes/timetable_helpers.php';

require_role(['lecturer']);

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

// Own lecturers.id (never trusted from input) — same lookup as
// lecturer/dashboard.php.
$lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
$lecStmt->bind_param('i', $currentUser['id']);
$lecStmt->execute();
$lecRow = $lecStmt->get_result()->fetch_assoc();
$lecStmt->close();
$lecturerRecordId = $lecRow ? (int) $lecRow['id'] : 0;

// "My courses" means current-offering-only (course_offerings, current
// semester) — identical query shape to lecturer/dashboard.php's own
// $myCourses, so this page can never disagree with the dashboard's own
// timetable card about what a lecturer is currently teaching.
$currentOfferingJoin = 'JOIN course_offerings co ON co.course_id = c.id AND co.lecturer_id = ?
     JOIN semesters se ON se.id = co.semester_id AND se.is_current = 1';

$myCoursesStmt = $conn->prepare(
    "SELECT c.id, c.code, c.name, offf.name AS faculty_name, COALESCE(rd.name, d.name) AS department_name,
            se.name AS semester_name, se.id AS semester_id,
            co.shift AS offering_shift, co.day_of_week, co.start_time, co.end_time, co.room
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     {$currentOfferingJoin}
     JOIN faculties offf ON offf.id = se.faculty_id
     LEFT JOIN departments rd ON rd.id = co.roster_department_id
     GROUP BY c.id, c.code, c.name, offf.name, d.name, rd.name, se.name, se.id, co.shift, co.day_of_week, co.start_time, co.end_time, co.room
     ORDER BY c.code"
);
$myCoursesStmt->bind_param('i', $lecturerRecordId);
$myCoursesStmt->execute();
$myCourses = $myCoursesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$myCoursesStmt->close();

$myTimetableGrid = build_class_timetable_grid($myCourses);

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
                Access scope: Own assigned courses only
            </div>

            <div class="admas-card p-4 timetable-print-card">
                <div class="timetable-print-header">
                    <img src="<?= htmlspecialchars(BASE_URL . '/' . $logoRelativePath) ?>" alt="<?= htmlspecialchars($universityName) ?> logo" class="timetable-print-logo">
                    <div class="timetable-print-header-text">
                        <div class="timetable-print-university"><?= htmlspecialchars(mb_strtoupper($universityName)) ?></div>
                        <div class="timetable-print-campus"><?= htmlspecialchars($campusLine) ?></div>
                        <div class="timetable-print-faculty"><?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></div>
                    </div>
                </div>

                <div class="timetable-print-meta">
                    <span class="timetable-print-title">Class Time Table</span>
                </div>

                <?php if (empty($myTimetableGrid['time_slots'])): ?>
                    <p class="text-muted small py-3">No scheduled class times have been set for your courses yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <?php render_class_timetable_grid_table($myTimetableGrid, $printDayOrder, 'name', 'timetable-print-table'); ?>
                    </div>
                <?php endif; ?>

                <div class="timetable-print-signature">REGISTRAR</div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
