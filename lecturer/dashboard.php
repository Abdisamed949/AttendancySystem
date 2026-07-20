<?php
/**
 * Lecturer dashboard — scoped to this lecturer's own assigned courses only
 * (resolved via current_user()['id'] -> lecturers.user_id, same lookup as
 * attendance.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/semester_helpers.php';

require_role(['lecturer']);

const SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

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
// Own lecturers.id (never trusted from input)
// ---------------------------------------------------------------------
$lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
$lecStmt->bind_param('i', $currentUser['id']);
$lecStmt->execute();
$lecRow = $lecStmt->get_result()->fetch_assoc();
$lecStmt->close();
$lecturerRecordId = $lecRow ? (int) $lecRow['id'] : 0;

// "My courses" means current-offering-only (course_offerings scoped to
// that course's own faculty's current semester), not the deprecated
// permanent courses.lecturer_id — reused below for the KPI counts and the
// course table so they can never drift on what "mine" means. Resolved per
// course's own faculty, never the lecturer's home department (D1).
$currentOfferingJoin = 'JOIN course_offerings co ON co.course_id = c.id AND co.lecturer_id = ?
     JOIN semesters se ON se.id = co.semester_id AND se.faculty_id = d.faculty_id AND se.is_current = 1';

// ---------------------------------------------------------------------
// KPI cards
// ---------------------------------------------------------------------
$myCoursesStmt = $conn->prepare(
    "SELECT COUNT(DISTINCT c.id) AS c
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     {$currentOfferingJoin}"
);
$myCoursesStmt->bind_param('i', $lecturerRecordId);
$myCoursesStmt->execute();
$myCoursesCount = (int) ($myCoursesStmt->get_result()->fetch_assoc()['c'] ?? 0);
$myCoursesStmt->close();

$totalStudentsStmt = $conn->prepare(
    "SELECT COUNT(DISTINCT ce.student_id) AS c
     FROM course_enrollments ce
     JOIN courses c ON c.id = ce.course_id
     JOIN departments d ON d.id = c.department_id
     {$currentOfferingJoin}"
);
$totalStudentsStmt->bind_param('i', $lecturerRecordId);
$totalStudentsStmt->execute();
$totalStudentsCount = (int) ($totalStudentsStmt->get_result()->fetch_assoc()['c'] ?? 0);
$totalStudentsStmt->close();

$sessionsStmt = $conn->prepare('SELECT COUNT(DISTINCT attendance_date) AS c FROM attendance WHERE recorded_by_user_id = ?');
$sessionsStmt->bind_param('i', $currentUser['id']);
$sessionsStmt->execute();
$sessionsRecorded = (int) ($sessionsStmt->get_result()->fetch_assoc()['c'] ?? 0);
$sessionsStmt->close();

// ---------------------------------------------------------------------
// My Assigned Courses table — each row's own Academic Year comes from its
// own current offering's semester, since different courses here can
// belong to different faculties on different academic years at once;
// there is no single shared "the current academic year" to show anymore.
// ---------------------------------------------------------------------
$myCoursesStmt2 = $conn->prepare(
    "SELECT c.id, c.code, c.name, ay.label AS academic_year_label, se.id AS semester_id,
            MAX(a.attendance_date) AS last_session,
            (SELECT a2.shift FROM attendance a2 WHERE a2.course_id = c.id ORDER BY a2.attendance_date DESC LIMIT 1) AS last_shift,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) AS student_count
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     {$currentOfferingJoin}
     JOIN academic_years ay ON ay.id = se.academic_year_id
     LEFT JOIN attendance a ON a.course_id = c.id
     GROUP BY c.id, c.code, c.name, ay.label, se.id
     ORDER BY c.code"
);
$myCoursesStmt2->bind_param('i', $lecturerRecordId);
$myCoursesStmt2->execute();
$myCourses = $myCoursesStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$myCoursesStmt2->close();

// ---------------------------------------------------------------------
// Pending Xiiso Sessions — for each currently-offered course, any Xiiso
// whose date has already passed but doesn't have an attendance mark for
// every enrolled student yet. Surfaced so a lecturer never accidentally
// lets a session go unrecorded before the semester closes.
// ---------------------------------------------------------------------
$today = date('Y-m-d');
$pendingSessions = [];
foreach ($myCourses as $course) {
    $enrolledCount = (int) $course['student_count'];
    if ($enrolledCount === 0 || $course['semester_id'] === null) {
        continue;
    }

    $sessions = get_sessions_for_semester($conn, (int) $course['semester_id']);
    foreach ($sessions as $session) {
        if ($session['date'] === null || $session['date'] > $today) {
            continue;
        }

        $markedStmt = $conn->prepare('SELECT COUNT(*) AS c FROM attendance WHERE course_id = ? AND session_id = ?');
        $markedStmt->bind_param('ii', $course['id'], $session['id']);
        $markedStmt->execute();
        $markedCount = (int) ($markedStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $markedStmt->close();

        if ($markedCount < $enrolledCount) {
            $pendingSessions[] = [
                'course_id' => (int) $course['id'],
                'course_label' => $course['code'] . ' — ' . $course['name'],
                'session_label' => $session['label'],
                'session_date' => $session['date'],
                'marked_count' => $markedCount,
                'enrolled_count' => $enrolledCount,
            ];
        }
    }
}
usort($pendingSessions, static fn ($a, $b) => strcmp($a['session_date'], $b['session_date']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard — ADMAS Attendance System</title>
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
                Access scope: Your assigned courses only
            </div>

            <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Welcome back, <?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></h4>
            <p class="text-muted mb-4">Here's a summary of your assigned courses.</p>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-4">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-journal-bookmark-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($myCoursesCount) ?></div>
                            <div class="kpi-label">My Courses</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalStudentsCount) ?></div>
                            <div class="kpi-label">Total Students</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-green"><i class="bi bi-calendar2-check-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($sessionsRecorded) ?></div>
                            <div class="kpi-label">Sessions Recorded</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($pendingSessions)): ?>
                <div class="admas-card p-4 mb-4">
                    <h6 class="fw-bold mb-3" style="color: var(--admas-text);">
                        <i class="bi bi-exclamation-triangle-fill text-warning"></i> Pending Xiiso Sessions
                    </h6>
                    <p class="text-muted small mb-3">These sessions have already happened but attendance hasn't been marked for every student yet.</p>
                    <div class="table-responsive">
                        <table class="table admas-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Xiiso</th>
                                    <th>Date</th>
                                    <th>Marked</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingSessions as $p): ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($p['course_label']) ?></td>
                                        <td><?= htmlspecialchars($p['session_label']) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($p['session_date']))) ?></td>
                                        <td>
                                            <span class="badge-pill badge-warning"><?= $p['marked_count'] ?> / <?= $p['enrolled_count'] ?></span>
                                        </td>
                                        <td>
                                            <a href="<?= htmlspecialchars(BASE_URL) ?>/attendance.php?course_id=<?= $p['course_id'] ?>" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                                <i class="bi bi-calendar2-check"></i> Mark Now
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="admas-card p-4">
                <h6 class="fw-bold mb-3" style="color: var(--admas-text);">My Assigned Courses</h6>
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Academic Year</th>
                                <th>Shift</th>
                                <th>Students</th>
                                <th>Last Session</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($myCourses)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">You have no assigned courses yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($myCourses as $c): ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?></td>
                                        <td><?= htmlspecialchars($c['academic_year_label']) ?></td>
                                        <td>
                                            <?php if ($c['last_shift'] !== null && isset(SHIFT_LABELS[$c['last_shift']])): ?>
                                                <?= htmlspecialchars(SHIFT_LABELS[$c['last_shift']]) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= number_format((int) $c['student_count']) ?></td>
                                        <td>
                                            <?php if ($c['last_session']): ?>
                                                <?= htmlspecialchars(date('M j, Y', strtotime((string) $c['last_session']))) ?>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?= htmlspecialchars(BASE_URL) ?>/attendance.php?course_id=<?= (int) $c['id'] ?>" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                                <i class="bi bi-calendar2-check"></i> Take Attendance
                                            </a>
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
