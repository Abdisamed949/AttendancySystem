<?php
/**
 * Teaching History (Lecturer only) — every semester this lecturer has held
 * a real course_offerings row in, most recent first, each with a per-course
 * breakdown (sessions recorded, enrolled students, class average score).
 * Unlike lecturer/courses.php (a management view with "Take Attendance"
 * actions, current-and-upcoming-focused), this is a purely retrospective
 * look-back at their own real teaching record — same relationship
 * student/attendance_history.php already has to student/courses.php.
 * Scoped via lecturers.user_id -> lecturers.id, resolved from
 * current_user()['id'], never from request input.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';
require_once __DIR__ . '/../includes/export_helpers.php';

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
$minAttendancePct = (float) ($settings['min_attendance_pct'] ?? 7.5);

$lecStmt = $conn->prepare('SELECT id, full_name FROM lecturers WHERE user_id = ?');
$lecStmt->bind_param('i', $currentUser['id']);
$lecStmt->execute();
$lecRow = $lecStmt->get_result()->fetch_assoc();
$lecStmt->close();
$lecturerId = $lecRow ? (int) $lecRow['id'] : 0;

// ---------------------------------------------------------------------
// One row per (semester, course) this lecturer has ever held a real
// course_offerings row in — any status (current/waiting/ended), so a
// semester they're no longer assigned to still shows up here as history.
// The class-average score reuses the exact same "average of each student's
// own capped out-of-10 score, regular sessions only" semantics used
// throughout the app (reports.php's Course Attendance Summary,
// student/dashboard.php, etc.) — never a pooled ratio.
// ---------------------------------------------------------------------
$semesters = [];
$coursesBySemester = [];
if ($lecturerId > 0) {
    $stmt = $conn->prepare(
        "SELECT DISTINCT se.id, se.name, se.start_date, se.end_date, se.status, ay.label AS academic_year_label
         FROM course_offerings co
         JOIN semesters se ON se.id = co.semester_id
         JOIN academic_years ay ON ay.id = se.academic_year_id
         WHERE co.lecturer_id = ?
         ORDER BY (se.status = 'current') DESC, se.start_date DESC, se.id DESC"
    );
    $stmt->bind_param('i', $lecturerId);
    $stmt->execute();
    $semesters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Faculty/Department per row is the OFFERING's own — se.faculty_id and
    // roster_department_id (falling back to the course's own catalog
    // department when unset) — same resolution lecturer/courses.php
    // already uses, since a lecturer may hold a cross-listed/guest-faculty
    // offering whose faculty differs from the course's catalog home.
    $courseStmt = $conn->prepare(
        "SELECT co.id AS offering_id, co.semester_id, c.id AS course_id, c.code, c.name, co.shift,
                se.faculty_id, f.name AS faculty_name,
                COALESCE(rd.id, d.id) AS department_id, COALESCE(rd.name, d.name) AS department_name,
                ROUND(AVG(t.present_score), 1) AS avg_score,
                COUNT(DISTINCT t.student_id) AS students_with_marks,
                COUNT(DISTINCT sess.id) AS sessions_recorded
         FROM course_offerings co
         JOIN courses c ON c.id = co.course_id
         JOIN departments d ON d.id = c.department_id
         JOIN semesters se ON se.id = co.semester_id
         JOIN faculties f ON f.id = se.faculty_id
         LEFT JOIN departments rd ON rd.id = co.roster_department_id
         LEFT JOIN sessions sess ON sess.semester_id = co.semester_id AND sess.type = 'regular'
             AND EXISTS (SELECT 1 FROM attendance a2 WHERE a2.course_id = c.id AND a2.session_id = sess.id)
         LEFT JOIN (
             SELECT a.student_id, a.course_id, s2.semester_id,
                    LEAST(10, SUM(a.status = 'present')) AS present_score
             FROM attendance a
             JOIN sessions s2 ON s2.id = a.session_id AND s2.type = 'regular'
             GROUP BY a.student_id, a.course_id, s2.semester_id
         ) t ON t.course_id = c.id AND t.semester_id = co.semester_id
         WHERE co.lecturer_id = ?
         GROUP BY co.id, co.semester_id, c.id, c.code, c.name, co.shift, se.faculty_id, f.name, department_id, department_name
         ORDER BY c.code"
    );
    $courseStmt->bind_param('i', $lecturerId);
    $courseStmt->execute();
    $res = $courseStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $semesterId = (int) $row['semester_id'];
        $rosterShift = ($row['shift'] !== null && $row['shift'] !== 'any') ? $row['shift'] : null;
        $row['enrolled_count'] = get_course_roster_count($conn, (int) $row['course_id'], $semesterId, $rosterShift);
        $coursesBySemester[$semesterId][] = $row;
    }
    $courseStmt->close();

    // ---------------------------------------------------------------------
    // This lecturer's own Check-In/Check-Out record (a distinct concern
    // from attendance-marking — see lecturer/checkin.php), one row per
    // (course, session) they've ever checked into, joined here per
    // (course_id, semester_id) so each course's card can show how many of
    // its 12 Xiiso the lecturer personally checked into, plus the exact
    // in/out timestamps.
    // ---------------------------------------------------------------------
    $checkinsByCourseSemester = [];
    $checkinStmt = $conn->prepare(
        "SELECT lc.course_id, sess.semester_id, sess.session_number, sess.type,
                lc.check_in_at, lc.check_out_at
         FROM lecturer_checkins lc
         JOIN sessions sess ON sess.id = lc.session_id
         WHERE lc.lecturer_id = ?
         ORDER BY sess.session_number"
    );
    $checkinStmt->bind_param('i', $lecturerId);
    $checkinStmt->execute();
    $checkinRes = $checkinStmt->get_result();
    while ($row = $checkinRes->fetch_assoc()) {
        $key = (int) $row['course_id'] . ':' . (int) $row['semester_id'];
        $checkinsByCourseSemester[$key][] = $row;
    }
    $checkinStmt->close();
}

// ---------------------------------------------------------------------
// Export (PDF/Excel) — flattens every semester's per-course rows into one
// table, most recent semester first. Must run before any HTML output.
// ---------------------------------------------------------------------
$exportFormat = (string) ($_GET['export'] ?? '');
if (($exportFormat === 'excel' || $exportFormat === 'pdf') && $lecRow) {
    $exportColumns = [
        ['key' => 'semester', 'label' => 'Semester'],
        ['key' => 'course', 'label' => 'Course'],
        ['key' => 'faculty', 'label' => 'Faculty'],
        ['key' => 'department', 'label' => 'Department'],
        ['key' => 'sessions', 'label' => 'Sessions Recorded (of 10)'],
        ['key' => 'enrolled', 'label' => 'Enrolled Students'],
        ['key' => 'avg_score', 'label' => 'Class Avg Score (of 10)'],
        ['key' => 'checkins', 'label' => 'Lecturer Check-Ins (of 10)'],
    ];
    $exportRows = [];
    foreach ($semesters as $sem) {
        $courses = $coursesBySemester[(int) $sem['id']] ?? [];
        foreach ($courses as $c) {
            $checkinKey = (int) $c['course_id'] . ':' . (int) $c['semester_id'];
            $checkinCount = count($checkinsByCourseSemester[$checkinKey] ?? []);
            $exportRows[] = [
                'semester' => $sem['name'] . ' (' . $sem['academic_year_label'] . ')',
                'course' => $c['code'] . ' — ' . $c['name'],
                'faculty' => $c['faculty_name'],
                'department' => $c['department_name'],
                'sessions' => (int) $c['sessions_recorded'],
                'enrolled' => (int) $c['enrolled_count'],
                'avg_score' => $c['avg_score'] !== null ? number_format((float) $c['avg_score'], 1) : '—',
                'checkins' => $checkinCount,
            ];
        }
    }

    $title = 'Teaching History — ' . $lecRow['full_name'];
    $subtitle = count($semesters) . ' semester' . (count($semesters) === 1 ? '' : 's');
    $filename = 'teaching_history_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $lecRow['full_name']);

    if ($exportFormat === 'excel') {
        stream_table_as_excel($exportColumns, $exportRows, $title, $subtitle, $filename);
    }
    $branding = export_branding($conn);
    stream_table_as_pdf($exportColumns, $exportRows, $title, $subtitle, $filename, $branding['university_name'], $branding['campus_line'], $branding['logo_base64']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teaching History — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        .history-wrap { max-width: 900px; margin: 0 auto; }

        .semester-card {
            border-left: 5px solid var(--admas-border);
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }

        .semester-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px var(--admas-shadow);
        }

        .semester-card.semester-current { border-left-color: #16a34a; }
        .semester-card.semester-accent-sky { border-left-color: var(--admas-sky); }
        .semester-card.semester-accent-navy { border-left-color: var(--admas-navy-start); }
        .semester-card.semester-accent-amber { border-left-color: #d97706; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <div class="page-body">
            <div class="history-wrap">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                Access scope: Your own teaching record only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Teaching History</h4>
                    <p class="text-muted mb-0">Every semester you've taught in, most recent first.</p>
                </div>
                <?php if (!empty($semesters)): ?>
                    <div class="d-flex gap-2">
                        <a href="?export=excel" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
                        <a href="?export=pdf" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($semesters)): ?>
                <div class="admas-card p-4 text-center text-muted py-5">
                    No teaching history yet.
                </div>
            <?php endif; ?>

            <?php
            $semAccents = ['semester-accent-sky', 'semester-accent-navy', 'semester-accent-amber'];
            $semIndex = 0;
            ?>
            <?php foreach ($semesters as $sem): ?>
                <?php
                $courses = $coursesBySemester[(int) $sem['id']] ?? [];
                $accentClass = $sem['status'] === 'current' ? 'semester-current' : $semAccents[$semIndex % count($semAccents)];
                $semIndex++;
                $statusLabel = ['current' => 'Current', 'waiting' => 'Waiting', 'ended' => 'Ended'][$sem['status']] ?? ucfirst((string) $sem['status']);
                $statusBadge = ['current' => 'badge-present', 'waiting' => 'badge-warning', 'ended' => 'badge-neutral'][$sem['status']] ?? 'badge-neutral';
                ?>
                <div class="admas-card semester-card <?= $accentClass ?> p-4 mb-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-1" style="color: var(--admas-text);">
                                <?= htmlspecialchars($sem['name']) ?>
                                <span class="badge-pill <?= $statusBadge ?> ms-2"><?= htmlspecialchars($statusLabel) ?></span>
                            </h5>
                            <p class="text-muted small mb-0">
                                <i class="bi bi-calendar3"></i>
                                <?= htmlspecialchars($sem['academic_year_label']) ?>
                                <?php if ($sem['start_date'] || $sem['end_date']): ?>
                                    &middot; <?= htmlspecialchars(($sem['start_date'] ?? '?') . ' to ' . ($sem['end_date'] ?? '?')) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="text-muted small"><?= count($courses) ?> course<?= count($courses) === 1 ? '' : 's' ?></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table admas-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Faculty</th>
                                    <th>Department</th>
                                    <th>Sessions Recorded</th>
                                    <th>Enrolled</th>
                                    <th>Class Avg Score</th>
                                    <th>My Check-Ins</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courses as $c): ?>
                                    <?php
                                    $checkinKey = (int) $c['course_id'] . ':' . (int) $c['semester_id'];
                                    $checkinRows = $checkinsByCourseSemester[$checkinKey] ?? [];
                                    $checkinRowId = 'checkins-' . (int) $c['course_id'] . '-' . (int) $c['semester_id'];
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="badge-pill badge-active"><?= htmlspecialchars($c['code']) ?></span>
                                            <span class="ms-1"><?= htmlspecialchars($c['name']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($c['faculty_name']) ?></td>
                                        <td><?= htmlspecialchars($c['department_name']) ?></td>
                                        <td><?= (int) $c['sessions_recorded'] ?> / 10</td>
                                        <td><?= number_format((int) $c['enrolled_count']) ?></td>
                                        <td>
                                            <?php if ($c['avg_score'] === null): ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php else: ?>
                                                <span class="badge-pill <?= attendance_badge_class((float) $c['avg_score'], $minAttendancePct) ?>"><?= number_format((float) $c['avg_score'], 1) ?> / 10</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (empty($checkinRows)): ?>
                                                <span class="text-muted">0 / 10</span>
                                            <?php else: ?>
                                                <a href="#" class="small fw-semibold text-decoration-none" style="color: var(--admas-sky);"
                                                   onclick="event.preventDefault(); document.getElementById('<?= htmlspecialchars($checkinRowId) ?>').classList.toggle('d-none');">
                                                    <?= count($checkinRows) ?> / 10 <i class="bi bi-chevron-down"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if (!empty($checkinRows)): ?>
                                        <tr class="d-none" id="<?= htmlspecialchars($checkinRowId) ?>">
                                            <td colspan="7">
                                                <div class="small text-muted mb-1">Check-in / check-out times:</div>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <?php foreach ($checkinRows as $ci): ?>
                                                        <?php
                                                        $ciLabel = $ci['type'] === 'midterm' ? 'Midterm' : ($ci['type'] === 'final' ? 'Final' : 'Xiiso ' . (int) $ci['session_number']);
                                                        $inTime = date('M j, g:i A', strtotime((string) $ci['check_in_at']));
                                                        $outTime = $ci['check_out_at'] !== null ? date('g:i A', strtotime((string) $ci['check_out_at'])) : null;
                                                        ?>
                                                        <span class="badge-pill badge-neutral">
                                                            <?= htmlspecialchars($ciLabel) ?>: <?= htmlspecialchars($inTime) ?><?= $outTime !== null ? ' &ndash; ' . htmlspecialchars($outTime) : ' (not checked out)' ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
