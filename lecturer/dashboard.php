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
require_once __DIR__ . '/../includes/attendance_helpers.php';

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

// "My courses" means current-offering-only (course_offerings, current
// semester), not the deprecated permanent courses.lecturer_id — reused
// below for the KPI counts and the course table so they can never drift on
// what "mine" means. No longer requires the offering's semester to belong
// to the course's own catalog department's faculty — a lecturer may hold a
// cross-listed/guest-faculty offering (see the Multi-Faculty Course
// Offerings plan), never derived from the lecturer's home department (D1).
$currentOfferingJoin = 'JOIN course_offerings co ON co.course_id = c.id AND co.lecturer_id = ?
     JOIN semesters se ON se.id = co.semester_id AND se.is_current = 1';

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

$sessionsStmt = $conn->prepare('SELECT COUNT(DISTINCT attendance_date) AS c FROM attendance WHERE recorded_by_user_id = ?');
$sessionsStmt->bind_param('i', $currentUser['id']);
$sessionsStmt->execute();
$sessionsRecorded = (int) ($sessionsStmt->get_result()->fetch_assoc()['c'] ?? 0);
$sessionsStmt->close();

// ---------------------------------------------------------------------
// My Assigned Courses table — each row's own Academic Year/Faculty comes
// from its own current offering's semester, since different courses here
// can belong to different faculties on different academic years at once;
// there is no single shared "the current academic year" to show anymore.
// Faculty/Department shown are the OFFERING's own faculty and roster
// department (falling back to the course's own catalog department when
// unset) — not necessarily the course's catalog home, matching
// lecturer/courses.php.
// ---------------------------------------------------------------------
$myCoursesStmt2 = $conn->prepare(
    "SELECT c.id, c.code, c.name, offf.name AS faculty_name, COALESCE(rd.name, d.name) AS department_name,
            se.name AS semester_name, ay.label AS academic_year_label, se.id AS semester_id,
            co.shift AS offering_shift,
            MAX(a.attendance_date) AS last_session
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     {$currentOfferingJoin}
     JOIN faculties offf ON offf.id = se.faculty_id
     LEFT JOIN departments rd ON rd.id = co.roster_department_id
     JOIN academic_years ay ON ay.id = se.academic_year_id
     LEFT JOIN attendance a ON a.course_id = c.id
     GROUP BY c.id, c.code, c.name, offf.name, d.name, rd.name, se.name, ay.label, se.id, co.shift
     ORDER BY c.code"
);
$myCoursesStmt2->bind_param('i', $lecturerRecordId);
$myCoursesStmt2->execute();
$myCourses = $myCoursesStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$myCoursesStmt2->close();

// Real roster size per course — enrollment-then-department-fallback (same
// resolution the Xiiso Grid itself uses), not a bare course_enrollments
// count, which understates any course only ever reachable via the
// department-roster fallback (no explicit enrollment rows yet). "Total
// Students" below is the distinct student ids across every one of this
// lecturer's own courses, not a sum (a student can be on more than one of
// their rosters at once).
$allRosterStudentIds = [];
foreach ($myCourses as &$courseRow) {
    $rosterShift = ($courseRow['offering_shift'] !== null && $courseRow['offering_shift'] !== 'any') ? $courseRow['offering_shift'] : null;
    $rosterIds = get_course_roster_student_ids($conn, (int) $courseRow['id'], (int) $courseRow['semester_id'], $rosterShift);
    $courseRow['student_count'] = count($rosterIds);
    $allRosterStudentIds = array_merge($allRosterStudentIds, $rosterIds);
}
unset($courseRow);
$totalStudentsCount = count(array_unique($allRosterStudentIds));

// Attendance % per course (this course's own current-semester sessions,
// any recorder — not just this lecturer's own clicks, since a cross-listed
// course can have marks from more than one lecturer) for the "My Attendance
// by Course" bar chart. Only courses with real marks yet are plotted.
$courseChartLabels = [];
$courseChartData = [];
foreach ($myCourses as $course) {
    if ($course['semester_id'] === null) {
        continue;
    }
    $pctStmt = $conn->prepare(
        "SELECT LEAST(10, SUM(a.status = 'present')) AS pct, COUNT(*) AS total_marks
         FROM attendance a JOIN sessions se ON se.id = a.session_id AND se.type = 'regular'
         WHERE a.course_id = ? AND se.semester_id = ?"
    );
    $pctStmt->bind_param('ii', $course['id'], $course['semester_id']);
    $pctStmt->execute();
    $pctRow = $pctStmt->get_result()->fetch_assoc();
    $pctStmt->close();
    if ($pctRow && (int) $pctRow['total_marks'] > 0) {
        $courseChartLabels[] = $course['code'];
        $courseChartData[] = (float) $pctRow['pct'];
    }
}

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
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/lecturer/courses.php" class="admas-card kpi-card accent-sky h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-journal-bookmark-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($myCoursesCount) ?></div>
                            <div class="kpi-label">My Courses</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/lecturer/courses.php" class="admas-card kpi-card accent-navy h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalStudentsCount) ?></div>
                            <div class="kpi-label">Total Students</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/reports.php" class="admas-card kpi-card accent-green h-100">
                        <div class="kpi-icon bg-green"><i class="bi bi-calendar2-check-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($sessionsRecorded) ?></div>
                            <div class="kpi-label">Sessions Recorded</div>
                        </div>
                        <i class="bi bi-chevron-right kpi-arrow"></i>
                    </a>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <?php if (!empty($courseChartLabels)): ?>
                <div class="col-xl-4">
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">My Attendance by Course</h6>
                        <canvas id="courseAttendanceChart" height="220"></canvas>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-xl-<?= !empty($courseChartLabels) ? '8' : '12' ?>">
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">My Assigned Courses</h6>
                        <div class="table-responsive">
                            <table class="table admas-table align-middle">
                                <thead>
                                    <tr>
                                        <th>Course</th>
                                        <th>Semester</th>
                                        <th>Faculty</th>
                                        <th>Department</th>
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
                                            <td colspan="9" class="text-center text-muted py-4">You have no assigned courses yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($myCourses as $c): ?>
                                            <tr>
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?></td>
                                                <td><?= htmlspecialchars($c['semester_name']) ?></td>
                                                <td><?= htmlspecialchars($c['faculty_name']) ?></td>
                                                <td><?= htmlspecialchars($c['department_name']) ?></td>
                                                <td><?= htmlspecialchars($c['academic_year_label']) ?></td>
                                                <td>
                                                    <?php if ($c['offering_shift'] !== null && isset(SHIFT_LABELS[$c['offering_shift']])): ?>
                                                        <?= htmlspecialchars(SHIFT_LABELS[$c['offering_shift']]) ?>
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!empty($courseChartLabels)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        const cssVar = (name, fallback) => {
            const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            return v || fallback;
        };
        const chartTextMuted = cssVar('--admas-text-muted', '#64748b');
        const chartGrid = cssVar('--admas-border', '#e2e8f0');
        // One distinct color per course bar (cycled if more courses than
        // colors) — same categorical palette used by the department/faculty
        // pie charts elsewhere in this app.
        const barPalette = ['#0ea5e9', '#6366f1', '#22c55e', '#f59e0b', '#ec4899', '#14b8a6', '#a855f7', '#ef4444', '#84cc16', '#0891b2'];
        const courseChartLabelsJs = <?= json_encode($courseChartLabels) ?>;
        const barColors = courseChartLabelsJs.map((_, i) => barPalette[i % barPalette.length]);

        new Chart(document.getElementById('courseAttendanceChart'), {
            type: 'bar',
            data: {
                labels: courseChartLabelsJs,
                datasets: [{
                    label: 'Attendance %',
                    data: <?= json_encode($courseChartData) ?>,
                    backgroundColor: barColors,
                    borderRadius: 6,
                    maxBarThickness: 48,
                }],
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: chartTextMuted }, grid: { display: false } },
                    y: {
                        min: 0,
                        max: 10,
                        ticks: { color: chartTextMuted, stepSize: 1, callback: (value) => value + '%' },
                        grid: { color: chartGrid },
                    },
                },
            },
        });
    </script>
    <?php endif; ?>
</body>
</html>
