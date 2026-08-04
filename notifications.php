<?php
/**
 * Notifications / Alerts — shared by System Administrator, Head of Academic
 * Affairs, and Dean (each scoped to a different slice of faculties, same
 * pattern as attendance.php / reports.php). Lives at the app root because
 * it is reused by three roles rather than owned by one folder. Students get
 * their own, more restricted view at student/notifications.php instead.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';

require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/includes/semester_helpers.php';

require_role(['system_admin', 'head_academic', 'dean']);

$conn = db();
$currentUser = current_user();
$role = current_role();

// ---------------------------------------------------------------------
// University settings (drives the sky-blue top strip + the threshold)
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
// Role-specific scope: dean's own faculty (never trusted from input,
// same resolution pattern as attendance.php / reports.php)
// ---------------------------------------------------------------------
$deanFacultyId = 0;
$deanFacultyName = '';
$deanCurrentSemesterId = 0;
if ($role === 'dean') {
    $deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
    if ($deanFacultyId > 0) {
        $fStmt = $conn->prepare('SELECT name FROM faculties WHERE id = ?');
        $fStmt->bind_param('i', $deanFacultyId);
        $fStmt->execute();
        $fRow = $fStmt->get_result()->fetch_assoc();
        $fStmt->close();
        $deanFacultyName = $fRow ? (string) $fRow['name'] : '';

        $deanCurrentSemester = get_current_semester($conn, $deanFacultyId);
        $deanCurrentSemesterId = (int) ($deanCurrentSemester['id'] ?? 0);
    }
}

// Every faculty (id => its own current semester id, 0 if none set) — a
// faculty's "current" is per-faculty, so alerts spanning every faculty
// (system_admin/head_academic) are built per-faculty and merged, never off
// one shared global value. Keyed by semester, not academic_year_id — two of
// a faculty's own semesters can share one academic year (e.g. a just-ended
// semester and its successor), so filtering by year alone could mix an
// already-ended semester's marks in with the current one.
$allFaculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$semesterIdByFaculty = [];
foreach ($allFaculties as $f) {
    $fid = (int) $f['id'];
    $sem = get_current_semester($conn, $fid);
    $semesterIdByFaculty[$fid] = (int) ($sem['id'] ?? 0);
}

// ---------------------------------------------------------------------
// Flash messages (post-redirect-get, same pattern as the other pages)
// ---------------------------------------------------------------------
$successMessage = '';
$errorMessage = '';
if (!empty($_SESSION['flash_success'])) {
    $successMessage = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $errorMessage = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------------------------------------------------------------------
// Handle "Notify" (POST) — re-verifies the pct and the faculty scope
// server-side instead of trusting whatever the form posted, then records
// a notifications row only if one wasn't already raised today.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'notify') {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $courseId = (int) ($_POST['course_id'] ?? 0);

    // The verify query needs a semester to filter by, but which one depends
    // on the target student's own faculty (its own current semester) —
    // resolve that first rather than assuming one shared value. Only
    // *regular* Xiiso sessions count toward the score (Midterm/Final never
    // do) — see ATTENDANCE_MAX_SCORE.
    $studentFacultyStmt = $conn->prepare('SELECT faculty_id FROM students WHERE id = ?');
    $studentFacultyStmt->bind_param('i', $studentId);
    $studentFacultyStmt->execute();
    $studentFacultyRow = $studentFacultyStmt->get_result()->fetch_assoc();
    $studentFacultyStmt->close();
    $targetSemesterId = $studentFacultyRow
        ? ($semesterIdByFaculty[(int) $studentFacultyRow['faculty_id']] ?? 0)
        : 0;

    $verifyStmt = $conn->prepare(
        "SELECT s.faculty_id, LEAST(10, SUM(a.status = 'present')) AS attendance_pct
         FROM attendance a
         JOIN students s ON s.id = a.student_id
         JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
         WHERE a.student_id = ? AND a.course_id = ? AND sess.semester_id = ?
         GROUP BY s.faculty_id"
    );
    $verifyStmt->bind_param('iii', $studentId, $courseId, $targetSemesterId);
    $verifyStmt->execute();
    $verifyRow = $verifyStmt->get_result()->fetch_assoc();
    $verifyStmt->close();

    if (!$verifyRow) {
        $_SESSION['flash_error'] = 'That student/course combination has no attendance records for the current semester.';
    } elseif ((float) $verifyRow['attendance_pct'] >= $minAttendancePct) {
        $_SESSION['flash_error'] = 'That student is no longer below the attendance threshold.';
    } elseif ($role === 'dean' && (int) $verifyRow['faculty_id'] !== $deanFacultyId) {
        $_SESSION['flash_error'] = 'That student is outside your faculty.';
    } else {
        $existsStmt = $conn->prepare(
            'SELECT id FROM notifications WHERE student_id = ? AND course_id = ? AND DATE(created_at) = CURDATE()'
        );
        $existsStmt->bind_param('ii', $studentId, $courseId);
        $existsStmt->execute();
        $alreadyNotified = (bool) $existsStmt->get_result()->fetch_assoc();
        $existsStmt->close();

        if ($alreadyNotified) {
            $_SESSION['flash_success'] = 'This student was already notified for this course today.';
        } else {
            $attendancePct = (float) $verifyRow['attendance_pct'];
            $insertStmt = $conn->prepare(
                'INSERT INTO notifications (student_id, course_id, attendance_pct, threshold_at_time, is_read) VALUES (?, ?, ?, ?, 0)'
            );
            $insertStmt->bind_param('iidd', $studentId, $courseId, $attendancePct, $minAttendancePct);
            $insertStmt->execute();
            $insertStmt->close();
            $_SESSION['flash_success'] = 'Notification recorded successfully.';
        }
    }

    redirect_to('notifications.php');
}

// ---------------------------------------------------------------------
// Live below-threshold list, scoped by role. Dean is a single faculty (one
// query, its own current semester); system_admin/head_academic span every
// faculty, each with its own current semester, so that's one query per
// faculty merged together rather than one shared global value. Only
// *regular* Xiiso sessions count toward the score (out of
// ATTENDANCE_MAX_SCORE = 10) — Midterm/Final never do, and filtering by
// this semester's own sessions (not academic_year_id) avoids mixing an
// already-ended semester's marks in with the current one when two
// semesters share an academic year.
// ---------------------------------------------------------------------
$alertsBaseSql = "SELECT s.id AS student_id, s.full_name, s.student_no, sem.name AS semester_name,
                          f.id AS faculty_id, f.name AS faculty_name,
                          c.id AS course_id, c.code, c.name AS course_name,
                          LEAST(10, SUM(a.status = 'present')) AS attendance_pct
                   FROM attendance a
                   JOIN students s ON s.id = a.student_id
                   JOIN faculties f ON f.id = s.faculty_id
                   JOIN courses c ON c.id = a.course_id
                   JOIN sessions xsess ON xsess.id = a.session_id AND xsess.type = 'regular'
                   LEFT JOIN semesters sem ON sem.id = s.semester_id
                   WHERE xsess.semester_id = ? AND s.faculty_id = ?
                   GROUP BY s.id, s.full_name, s.student_no, sem.name, f.id, f.name, c.id, c.code, c.name
                   HAVING attendance_pct < ?
                   ORDER BY attendance_pct ASC, s.full_name";

$alerts = [];
if ($role === 'dean') {
    if ($deanCurrentSemesterId > 0) {
        $stmt = $conn->prepare($alertsBaseSql);
        $stmt->bind_param('iid', $deanCurrentSemesterId, $deanFacultyId, $minAttendancePct);
        $stmt->execute();
        $alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    foreach ($semesterIdByFaculty as $facultyId => $facultySemesterId) {
        if ($facultySemesterId <= 0) {
            continue;
        }
        $stmt = $conn->prepare($alertsBaseSql);
        $stmt->bind_param('iid', $facultySemesterId, $facultyId, $minAttendancePct);
        $stmt->execute();
        $alerts = array_merge($alerts, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
    }
    usort($alerts, static fn ($a, $b) => $a['attendance_pct'] <=> $b['attendance_pct']);
}

// Which of today's rows already have a notification raised, so the Notify
// button/status can reflect it without an N+1 query per row.
$notifiedTodayKeys = [];
$todayResult = $conn->query('SELECT student_id, course_id FROM notifications WHERE DATE(created_at) = CURDATE()');
if ($todayResult) {
    while ($row = $todayResult->fetch_assoc()) {
        $notifiedTodayKeys[$row['student_id'] . '|' . $row['course_id']] = true;
    }
}

// ---------------------------------------------------------------------
// KPI cards
// ---------------------------------------------------------------------
$studentsBelowThreshold = count(array_unique(array_column($alerts, 'student_id')));

$unreadConditions = ['n.is_read = 0'];
$unreadParams = [];
$unreadTypes = '';
if ($role === 'dean') {
    $unreadConditions[] = 's.faculty_id = ?';
    $unreadParams[] = $deanFacultyId;
    $unreadTypes .= 'i';
}
$unreadSql = 'SELECT COUNT(*) AS c FROM notifications n JOIN students s ON s.id = n.student_id WHERE ' . implode(' AND ', $unreadConditions);
if ($unreadTypes !== '') {
    $unreadStmt = $conn->prepare($unreadSql);
    $unreadStmt->bind_param($unreadTypes, ...$unreadParams);
    $unreadStmt->execute();
    $unreadAlertsCount = (int) ($unreadStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $unreadStmt->close();
} else {
    $unreadAlertsCount = (int) ($conn->query($unreadSql)->fetch_assoc()['c'] ?? 0);
}

$scopeBanner = match ($role) {
    'system_admin' => 'Access scope: Full system — all faculties, departments, and courses',
    'head_academic' => 'Access scope: All faculties (cross-faculty alerts)',
    'dean' => 'Access scope: ' . $deanFacultyName . ' Faculty only',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                <?= htmlspecialchars($scopeBanner) ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Notifications / Alerts</h4>
                    <p class="text-muted mb-0">Students whose attendance has dropped below the minimum threshold for the current academic year.</p>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($successMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($errorMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($role === 'dean' && $deanCurrentSemesterId <= 0): ?>
                <div class="alert alert-warning">No current semester is set for <?= htmlspecialchars($deanFacultyName) ?>. Ask an administrator to set one on the Semesters page before alerts can be computed.</div>
            <?php elseif ($role !== 'dean' && !array_filter($semesterIdByFaculty)): ?>
                <div class="alert alert-warning">No faculty has a current semester set yet. Configure one for at least one faculty on the Semesters page before alerts can be computed.</div>
            <?php endif; ?>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-4">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-amber"><i class="bi bi-exclamation-triangle-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($studentsBelowThreshold) ?></div>
                            <div class="kpi-label">Students Below Threshold</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-navy"><i class="bi bi-bell-fill"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($unreadAlertsCount) ?></div>
                            <div class="kpi-label">Unread Alerts</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <div class="admas-card kpi-card h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-speedometer"></i></div>
                        <div>
                            <div class="kpi-value"><?= htmlspecialchars((string) $minAttendancePct) ?>%</div>
                            <div class="kpi-label">Alert Threshold <span class="text-muted">(edit in Settings)</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admas-card p-4">
                <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Below-Threshold Students</h6>

                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Faculty</th>
                                <th>Course</th>
                                <th>Semester</th>
                                <th>Attendance %</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($alerts)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No students are currently below the <?= htmlspecialchars((string) $minAttendancePct) ?>% attendance threshold.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($alerts as $a): ?>
                                    <?php
                                    $pct = (float) $a['attendance_pct'];
                                    $key = $a['student_id'] . '|' . $a['course_id'];
                                    $alreadyNotified = isset($notifiedTodayKeys[$key]);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($a['full_name']) ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars($a['student_no']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($a['faculty_name']) ?></td>
                                        <td><?= htmlspecialchars($a['code'] . ' — ' . $a['course_name']) ?></td>
                                        <td>
                                            <?php if ($a['semester_name']): ?>
                                                <?= htmlspecialchars($a['semester_name']) ?>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge-pill <?= attendance_badge_class($pct, $minAttendancePct) ?>"><?= number_format($pct, 1) ?>%</span></td>
                                        <td>
                                            <?php if ($alreadyNotified): ?>
                                                <span class="badge-pill badge-active">Notified Today</span>
                                            <?php else: ?>
                                                <span class="badge-pill badge-inactive">Not Notified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($alreadyNotified): ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" disabled>
                                                    <i class="bi bi-check2"></i> Notified
                                                </button>
                                            <?php else: ?>
                                                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/notifications.php" style="display:inline;">
                                                    <input type="hidden" name="action" value="notify">
                                                    <input type="hidden" name="student_id" value="<?= (int) $a['student_id'] ?>">
                                                    <input type="hidden" name="course_id" value="<?= (int) $a['course_id'] ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                                        <i class="bi bi-send"></i> Notify
                                                    </button>
                                                </form>
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
