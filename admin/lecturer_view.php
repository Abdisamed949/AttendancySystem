<?php
/**
 * Read-only lecturer detail page for University Rector's supervisory/
 * oversight role. Reachable only via the "View" (eye icon) link on
 * admin/lecturers.php's Actions column for this one role — no other role
 * is granted access here.
 *
 * Course-offering list below is a direct adaptation of
 * lecturer/courses.php's own query shape (full teaching history — current,
 * waiting, and ended semesters alike, one row per (course, offering) pair)
 * with lecturer_id supplied directly from the querystring instead of
 * resolved from current_user(), since this page is looking at someone
 * else's assignments, not the viewer's own. No "Take Attendance" link is
 * shown — that's a write action, out of scope for a view-only page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/semester_helpers.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['university_rector']);

$currentUser = current_user();

const VIEW_LECTURER_SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

$conn = db();

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
// The lecturer this page is scoped to.
// ---------------------------------------------------------------------
$lecturerId = (int) ($_GET['lecturer_id'] ?? 0);

$lecturerStmt = $conn->prepare(
    'SELECT l.id, l.staff_no, l.full_name, l.department_id,
            d.name AS department_name, f.name AS faculty_name,
            u.email, u.status AS user_status, u.photo_path
     FROM lecturers l
     JOIN departments d ON d.id = l.department_id
     JOIN faculties f ON f.id = d.faculty_id
     JOIN users u ON u.id = l.user_id
     WHERE l.id = ?'
);
$lecturerStmt->bind_param('i', $lecturerId);
$lecturerStmt->execute();
$lecturer = $lecturerStmt->get_result()->fetch_assoc();
$lecturerStmt->close();

if (!$lecturer) {
    $_SESSION['flash_error'] = 'Lecturer not found.';
    redirect_to('admin/lecturers.php');
}

// ---------------------------------------------------------------------
// Full teaching history — one row per (course, offering) pair, same shape
// as lecturer/courses.php's own unfiltered query.
// ---------------------------------------------------------------------
$coursesStmt = $conn->prepare(
    "SELECT c.id AS course_id, c.code, c.name, c.credit_hours,
            COALESCE(rd.id, d.id) AS department_id, se.faculty_id, COALESCE(rd.name, d.name) AS department_name, offf.name AS faculty_name,
            se.id AS semester_id, se.name AS semester_name, se.status AS semester_status,
            ay.label AS academic_year_label,
            co.shift AS offering_shift
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     JOIN course_offerings co ON co.course_id = c.id AND co.lecturer_id = ?
     JOIN semesters se ON se.id = co.semester_id
     JOIN faculties offf ON offf.id = se.faculty_id
     LEFT JOIN departments rd ON rd.id = co.roster_department_id
     JOIN academic_years ay ON ay.id = se.academic_year_id
     ORDER BY (se.status = 'current') DESC, (se.status = 'waiting') DESC, offf.name, department_name, c.code, se.start_date DESC"
);
$coursesStmt->bind_param('i', $lecturerId);
$coursesStmt->execute();
$courseOfferingRows = $coursesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$coursesStmt->close();

// ---------------------------------------------------------------------
// Per-row roster size + session stats, same resolution as
// lecturer/courses.php.
// ---------------------------------------------------------------------
$sessionsBySemesterId = [];
$courses = [];
foreach ($courseOfferingRows as $row) {
    $courseId = (int) $row['course_id'];
    $semesterId = (int) $row['semester_id'];

    if (!isset($sessionsBySemesterId[$semesterId])) {
        $sessionsBySemesterId[$semesterId] = get_sessions_for_semester($conn, $semesterId);
    }
    $sessions = $sessionsBySemesterId[$semesterId];

    $rosterShift = ($row['offering_shift'] !== null && $row['offering_shift'] !== 'any') ? $row['offering_shift'] : null;
    $enrolledCount = get_course_roster_count($conn, $courseId, $semesterId, $rosterShift);
    $totalMarked = 0;
    $lastSessionDate = null;

    foreach ($sessions as $session) {
        if ($session['date'] === null) {
            continue;
        }
        $markedStmt = $conn->prepare('SELECT COUNT(*) AS c FROM attendance WHERE course_id = ? AND session_id = ?');
        $markedStmt->bind_param('ii', $courseId, $session['id']);
        $markedStmt->execute();
        $markedCount = (int) ($markedStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $markedStmt->close();

        if ($markedCount > 0) {
            $totalMarked++;
            if ($lastSessionDate === null || $session['date'] > $lastSessionDate) {
                $lastSessionDate = $session['date'];
            }
        }
    }

    $row['student_count'] = $enrolledCount;
    $row['total_sessions'] = $totalMarked;
    $row['last_session'] = $lastSessionDate;
    $courses[] = $row;
}

function chat_lecturer_initials(string $fullName): string
{
    $initials = '';
    foreach (preg_split('/\s+/', trim($fullName)) as $part) {
        if ($part !== '') {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    return mb_substr($initials, 0, 2) ?: '?';
}

// ---------------------------------------------------------------------
// Check-In/Out History — this lecturer's own Lecturer Check-In/Check-Out
// records (a distinct concept from student attendance above). Raw
// timestamps only, no "left early" judgement computed here — the Rector
// reviews and judges for themselves, same as lecturer_checkins.php's own
// report for Head of Academic Affairs/Dean.
// ---------------------------------------------------------------------
$checkinStmt = $conn->prepare(
    "SELECT lc.check_in_at, lc.check_out_at, c.code AS course_code, c.name AS course_name,
            sess.session_number, sess.type AS session_type
     FROM lecturer_checkins lc
     JOIN courses c ON c.id = lc.course_id
     JOIN sessions sess ON sess.id = lc.session_id
     WHERE lc.lecturer_id = ?
     ORDER BY lc.check_in_at DESC
     LIMIT 100"
);
$checkinStmt->bind_param('i', $lecturerId);
$checkinStmt->execute();
$checkins = $checkinStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$checkinStmt->close();

$totalCheckins = count($checkins);
$totalNotCheckedOut = 0;
foreach ($checkins as $ci) {
    if ($ci['check_out_at'] === null) {
        $totalNotCheckedOut++;
    }
}

function checkin_view_session_label(array $row): string
{
    return match ($row['session_type']) {
        'midterm' => 'Midterm',
        'final' => 'Final',
        default => 'Xiiso ' . (int) $row['session_number'],
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer — <?= htmlspecialchars($lecturer['full_name']) ?> — ADMAS Attendance System</title>
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
                Access scope: Full system — view only (oversight)
            </div>

            <div class="mb-3">
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Lecturers
                </a>
            </div>

            <div class="profile-hero">
                <?php if (!empty($lecturer['photo_path'])): ?>
                    <img class="profile-hero-photo" src="<?= htmlspecialchars(BASE_URL) ?>/uploads/profile_photos/<?= htmlspecialchars((string) $lecturer['photo_path']) ?>" alt="">
                <?php else: ?>
                    <div class="profile-hero-initials"><?= htmlspecialchars(chat_lecturer_initials((string) $lecturer['full_name'])) ?></div>
                <?php endif; ?>
                <div class="profile-hero-body">
                    <p class="profile-hero-name"><?= htmlspecialchars($lecturer['full_name']) ?></p>
                    <p class="profile-hero-meta"><i class="bi bi-person-vcard"></i> Staff No: <?= htmlspecialchars($lecturer['staff_no']) ?></p>
                    <p class="profile-hero-meta"><i class="bi bi-bank"></i> <?= htmlspecialchars($lecturer['faculty_name']) ?> · <?= htmlspecialchars($lecturer['department_name']) ?></p>
                    <span class="profile-hero-badge"><i class="bi bi-mortarboard-fill"></i> Lecturer</span>
                </div>
            </div>

            <div class="admas-card p-4 mb-4">
                <div class="section-heading"><i class="bi bi-person-lines-fill"></i> Profile Information</div>
                <div class="row g-3">
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Staff No</div>
                            <div class="info-tile-value"><?= htmlspecialchars($lecturer['staff_no']) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Full Name</div>
                            <div class="info-tile-value"><?= htmlspecialchars($lecturer['full_name']) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Email</div>
                            <div class="info-tile-value"><?= htmlspecialchars($lecturer['email'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Status</div>
                            <div>
                                <?php if ($lecturer['user_status'] === 'active'): ?>
                                    <span class="badge-pill badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge-pill badge-inactive">Inactive</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Home Department</div>
                            <div class="info-tile-value"><?= htmlspecialchars($lecturer['department_name']) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Home Faculty</div>
                            <div class="info-tile-value"><?= htmlspecialchars($lecturer['faculty_name']) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admas-card p-4">
                <div class="section-heading"><i class="bi bi-journal-bookmark-fill"></i> Assigned Courses (current + past)</div>
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Semester</th>
                                <th>Status</th>
                                <th>Faculty</th>
                                <th>Department</th>
                                <th>Academic Year</th>
                                <th>Shift</th>
                                <th>Students</th>
                                <th>Sessions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courses)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No course offerings recorded for this lecturer.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courses as $c): ?>
                                    <?php
                                    $statusBadgeClass = [
                                        'current' => 'badge-present',
                                        'waiting' => 'badge-warning',
                                        'ended' => 'badge-inactive',
                                    ][$c['semester_status']] ?? 'badge-inactive';
                                    ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);">
                                            <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
                                        </td>
                                        <td><?= htmlspecialchars($c['semester_name']) ?></td>
                                        <td><span class="badge-pill <?= $statusBadgeClass ?>"><?= htmlspecialchars(ucfirst((string) $c['semester_status'])) ?></span></td>
                                        <td><?= htmlspecialchars($c['faculty_name']) ?></td>
                                        <td><?= htmlspecialchars($c['department_name']) ?></td>
                                        <td><?= htmlspecialchars($c['academic_year_label']) ?></td>
                                        <td>
                                            <?php if ($c['offering_shift'] !== null && isset(VIEW_LECTURER_SHIFT_LABELS[$c['offering_shift']])): ?>
                                                <?= htmlspecialchars(VIEW_LECTURER_SHIFT_LABELS[$c['offering_shift']]) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= number_format((int) $c['student_count']) ?></td>
                                        <td>
                                            <?= number_format((int) $c['total_sessions']) ?>
                                            <?php if ($c['last_session']): ?>
                                                <div class="text-muted small">Last: <?= htmlspecialchars(date('M j, Y', strtotime((string) $c['last_session']))) ?></div>
                                            <?php else: ?>
                                                <div class="text-muted small fst-italic">Never</div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="admas-card p-4 mt-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div class="section-heading mb-0"><i class="bi bi-door-open-fill"></i> Check-In/Out History</div>
                    <div class="d-flex gap-2">
                        <span class="badge-pill badge-active"><?= $totalCheckins ?> total check-ins</span>
                        <?php if ($totalNotCheckedOut > 0): ?>
                            <span class="badge-pill badge-warning"><?= $totalNotCheckedOut ?> not checked out</span>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="text-muted small">Raw arrival/departure times this lecturer has recorded per class session — no automatic "late/early" judgement is made; review the times yourself.</p>
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Xiiso</th>
                                <th>Date</th>
                                <th>Check-In</th>
                                <th>Check-Out</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($checkins)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No check-in records for this lecturer yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($checkins as $ci): ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($ci['course_code'] . ' — ' . $ci['course_name']) ?></td>
                                        <td><?= htmlspecialchars(checkin_view_session_label($ci)) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($ci['check_in_at']))) ?></td>
                                        <td><?= htmlspecialchars(date('g:i A', strtotime($ci['check_in_at']))) ?></td>
                                        <td>
                                            <?php if ($ci['check_out_at']): ?>
                                                <?= htmlspecialchars(date('g:i A', strtotime($ci['check_out_at']))) ?>
                                            <?php else: ?>
                                                <span class="badge-pill badge-warning">Not checked out</span>
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
