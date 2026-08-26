<?php
/**
 * Assign Courses (one lecturer, every faculty) — the lecturer-first mirror
 * of admin/course_offerings.php's course-first "Manage Offerings" flow.
 * Both write the exact same course_offerings table; this page exists
 * because a lecturer who teaches "common" courses shared across faculties
 * had no single place to see or manage everything they teach — it was
 * scattered across each course's own Manage Offerings page. Reachable only
 * via an "Assign Courses" link on a lecturer row in admin/lecturers.php or
 * head_academic/lecturers.php — no standalone sidebar item, same pattern
 * as admin/course_offerings.php.
 *
 * Shared by University Rector, Head of Academic Affairs (both: any
 * lecturer, any faculty), and Dean (own faculty only for adding/removing
 * an assignment — a Dean CAN see a lecturer's full cross-faculty teaching
 * list read-only, since that's just schedule metadata, not another
 * faculty's student/attendance data, but can only create or delete an
 * offering inside their own faculty).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/includes/timetable_helpers.php';

require_role(['university_rector', 'dean', 'head_academic']);

$conn = db();
$role = current_role();
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

$deanFacultyId = 0;
if ($role === 'dean') {
    $deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
}

$backUrl = BASE_URL . '/' . ($role === 'head_academic' ? 'head_academic/lecturers.php' : 'admin/lecturers.php');

// ---------------------------------------------------------------------
// The lecturer this page is about — never trusted beyond existence; every
// role may look up any lecturer (read-only cross-faculty visibility), the
// role check only narrows what they can change below.
// ---------------------------------------------------------------------
$lecturerId = (int) ($_GET['lecturer_id'] ?? $_POST['lecturer_id'] ?? 0);

$lecStmt = $conn->prepare(
    'SELECT l.id, l.staff_no, l.full_name, l.status AS lecturer_status,
            d.id AS department_id, d.name AS department_name, f.id AS faculty_id, f.name AS faculty_name,
            u.username
     FROM lecturers l
     JOIN departments d ON d.id = l.department_id
     JOIN faculties f ON f.id = d.faculty_id
     JOIN users u ON u.id = l.user_id
     WHERE l.id = ?'
);
$lecStmt->bind_param('i', $lecturerId);
$lecStmt->execute();
$lecturer = $lecStmt->get_result()->fetch_assoc();
$lecStmt->close();

if (!$lecturer) {
    $_SESSION['flash_error'] = 'Lecturer not found.';
    redirect_to(($role === 'head_academic' ? 'head_academic/lecturers.php' : 'admin/lecturers.php'));
}

/**
 * True if $facultyId is one this role may create/delete an offering in —
 * university_rector and head_academic may touch any faculty; dean has
 * full CRUD again within their own faculty only (restored per explicit
 * request, after an earlier session's temporary Viewer conversion). Used
 * to gate both POST actions below.
 */
function role_may_edit_faculty(string $role, int $deanFacultyId, int $facultyId): bool
{
    if ($role === 'dean') {
        return $facultyId === $deanFacultyId;
    }
    if ($role === 'university_rector') {
        // University Rector is a supervisory, read-only Viewer everywhere
        // else in this app (except User Management/Settings) — this page
        // was the one remaining write path that had never been converted;
        // closing it here matches that same boundary. Head of Academic
        // Affairs keeps full cross-faculty write access (falls through to
        // the final `return true`).
        return false;
    }

    return true;
}

// ---------------------------------------------------------------------
// Flash messages (post-redirect-get)
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
// Handle POST actions
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'assign_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $shift = (string) ($_POST['shift'] ?? '');
        $rosterDepartmentIdRaw = (int) ($_POST['roster_department_id'] ?? 0);
        $startDateRaw = trim((string) ($_POST['start_date'] ?? ''));
        $endDateRaw = trim((string) ($_POST['end_date'] ?? ''));
        $dayOfWeekRaw = trim((string) ($_POST['day_of_week'] ?? ''));
        $startTimeRaw = trim((string) ($_POST['start_time'] ?? ''));
        $endTimeRaw = trim((string) ($_POST['end_time'] ?? ''));
        $roomRaw = trim((string) ($_POST['room'] ?? ''));

        $courseStmt = $conn->prepare(
            'SELECT c.id, d.faculty_id FROM courses c JOIN departments d ON d.id = c.department_id WHERE c.id = ?'
        );
        $courseStmt->bind_param('i', $courseId);
        $courseStmt->execute();
        $courseRow = $courseStmt->get_result()->fetch_assoc();
        $courseStmt->close();

        $validationError = '';
        if (!$courseRow) {
            $validationError = 'Please select a valid course.';
        } elseif (!array_key_exists($shift, OFFERING_SHIFT_LABELS)) {
            $validationError = 'Please select a valid shift.';
        }

        // Semester scoping: a Dean may only target their OWN faculty's
        // semester; University Rector/Head of Academic Affairs may target
        // ANY faculty's semester — when that differs from the course's own
        // catalog faculty, this is a cross-faculty "guest" offering, the
        // same concept admin/course_offerings.php already supports, and a
        // Roster Department (from the semester's own faculty) is required
        // so the correct students end up on this offering's roster.
        $semRow = null;
        $isGuestOffering = false;
        if ($validationError === '') {
            if ($role === 'dean') {
                $semStmt = $conn->prepare('SELECT id, name, faculty_id, start_date, end_date FROM semesters WHERE id = ? AND faculty_id = ?');
                $semStmt->bind_param('ii', $semesterId, $deanFacultyId);
            } else {
                $semStmt = $conn->prepare('SELECT id, name, faculty_id, start_date, end_date FROM semesters WHERE id = ?');
                $semStmt->bind_param('i', $semesterId);
            }
            $semStmt->execute();
            $semRow = $semStmt->get_result()->fetch_assoc();
            $semStmt->close();

            if (!$semRow) {
                $validationError = $role === 'dean'
                    ? 'Please select a valid semester from your own faculty.'
                    : 'Please select a valid semester.';
            } else {
                $isGuestOffering = (int) $semRow['faculty_id'] !== (int) $courseRow['faculty_id'];
            }
        }

        // The real write boundary is the semester's own faculty (where the
        // course_offerings row actually lives), not the course's catalog
        // home faculty — matches admin/course_offerings.php's own reasoning.
        if ($validationError === '' && !role_may_edit_faculty($role, $deanFacultyId, (int) $semRow['faculty_id'])) {
            $validationError = 'You can only assign courses within your own faculty.';
        }

        $rosterDepartmentId = null;
        if ($validationError === '' && $rosterDepartmentIdRaw > 0) {
            $rdStmt = $conn->prepare('SELECT id FROM departments WHERE id = ? AND faculty_id = ?');
            $rdStmt->bind_param('ii', $rosterDepartmentIdRaw, $semRow['faculty_id']);
            $rdStmt->execute();
            $rdRow = $rdStmt->get_result()->fetch_assoc();
            $rdStmt->close();
            if (!$rdRow) {
                $validationError = 'Roster Department must belong to the selected semester\'s own faculty.';
            } else {
                $rosterDepartmentId = $rosterDepartmentIdRaw;
            }
        } elseif ($validationError === '' && $isGuestOffering) {
            $validationError = 'This course\'s catalog home is a different faculty — please select a Roster Department so the correct students are used for this offering.';
        }

        $startDate = null;
        $endDate = null;
        if ($validationError === '' && $startDateRaw !== '') {
            if (!DateTime::createFromFormat('Y-m-d', $startDateRaw)) {
                $validationError = 'Please provide a valid start date.';
            } else {
                $startDate = $startDateRaw;
            }
        }
        if ($validationError === '' && $endDateRaw !== '') {
            if (!DateTime::createFromFormat('Y-m-d', $endDateRaw)) {
                $validationError = 'Please provide a valid end date.';
            } else {
                $endDate = $endDateRaw;
            }
        }
        if ($validationError === '' && $startDate !== null && $endDate !== null && $endDate < $startDate) {
            $validationError = 'End date must be on or after start date.';
        }

        // Class Time Table — all optional; room is cosmetic only (no
        // double-booking check by design, matching admin/course_offerings.php).
        $dayOfWeek = null;
        $startTime = null;
        $endTime = null;
        $room = null;
        if ($validationError === '') {
            if ($dayOfWeekRaw !== '' && !array_key_exists($dayOfWeekRaw, DAY_OF_WEEK_LABELS)) {
                $validationError = 'Please select a valid day.';
            } elseif (($startTimeRaw !== '') !== ($endTimeRaw !== '')) {
                $validationError = 'Please provide both a start time and an end time, or leave both blank.';
            } elseif ($startTimeRaw !== '' && $endTimeRaw !== '' && $startTimeRaw >= $endTimeRaw) {
                $validationError = 'Class end time must be after the start time.';
            } else {
                $dayOfWeek = $dayOfWeekRaw !== '' ? $dayOfWeekRaw : null;
                $startTime = $startTimeRaw !== '' ? $startTimeRaw : null;
                $endTime = $endTimeRaw !== '' ? $endTimeRaw : null;
                $room = $roomRaw !== '' ? mb_substr($roomRaw, 0, 50) : null;
            }
        }

        if ($validationError === '') {
            $upsertStmt = $conn->prepare(
                'INSERT INTO course_offerings (course_id, semester_id, lecturer_id, roster_department_id, shift, start_date, end_date, day_of_week, start_time, end_time, room) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE lecturer_id = VALUES(lecturer_id), roster_department_id = VALUES(roster_department_id), start_date = VALUES(start_date), end_date = VALUES(end_date), day_of_week = VALUES(day_of_week), start_time = VALUES(start_time), end_time = VALUES(end_time), room = VALUES(room)'
            );
            $upsertStmt->bind_param('iiiisssssss', $courseId, $semesterId, $lecturerId, $rosterDepartmentId, $shift, $startDate, $endDate, $dayOfWeek, $startTime, $endTime, $room);
            $upsertStmt->execute();
            $upsertStmt->close();

            $_SESSION['flash_success'] = $lecturer['full_name'] . ' assigned to "' . $semRow['name'] . '" (' . OFFERING_SHIFT_LABELS[$shift] . ') successfully.';
        } else {
            $_SESSION['flash_error'] = $validationError;
        }
        redirect_to('lecturer_courses.php?lecturer_id=' . $lecturerId);
    } elseif ($action === 'remove_offering') {
        $offeringId = (int) ($_POST['offering_id'] ?? 0);

        // The offering's real faculty is the SEMESTER's own faculty (where
        // it actually lives, which for a guest/cross-listed offering can
        // differ from the course's own catalog department) — not the
        // course's home department's faculty.
        $offStmt = $conn->prepare(
            'SELECT co.id, se.faculty_id
             FROM course_offerings co
             JOIN semesters se ON se.id = co.semester_id
             WHERE co.id = ? AND co.lecturer_id = ?'
        );
        $offStmt->bind_param('ii', $offeringId, $lecturerId);
        $offStmt->execute();
        $offRow = $offStmt->get_result()->fetch_assoc();
        $offStmt->close();

        if (!$offRow) {
            $_SESSION['flash_error'] = 'That assignment no longer exists.';
        } elseif (!role_may_edit_faculty($role, $deanFacultyId, (int) $offRow['faculty_id'])) {
            $_SESSION['flash_error'] = 'You can only remove assignments within your own faculty.';
        } else {
            $deleteStmt = $conn->prepare('DELETE FROM course_offerings WHERE id = ?');
            $deleteStmt->bind_param('i', $offeringId);
            $deleteStmt->execute();
            $deleteStmt->close();
            $_SESSION['flash_success'] = 'Assignment removed.';
        }
        redirect_to('lecturer_courses.php?lecturer_id=' . $lecturerId);
    }
}

// ---------------------------------------------------------------------
// "Everything this lecturer teaches" — every course_offerings row for
// this lecturer, any faculty, most recent semester first.
// ---------------------------------------------------------------------
// "Faculty" here is the SEMESTER's own faculty — where this offering
// actually lives — not the course's catalog home department's faculty.
// The two differ for a cross-faculty "guest" offering, flagged below via
// home_faculty_id vs offering_faculty_id.
$teachingStmt = $conn->prepare(
    'SELECT co.id AS offering_id, co.start_date, co.end_date, co.shift, co.roster_department_id,
            co.day_of_week, co.start_time, co.end_time, co.room,
            c.code, c.name AS course_name, d.faculty_id AS home_faculty_id,
            se.name AS semester_name, se.is_current, se.faculty_id AS offering_faculty_id,
            of.name AS faculty_name, ay.label AS academic_year_label, rd.name AS roster_department_name
     FROM course_offerings co
     JOIN courses c ON c.id = co.course_id
     JOIN departments d ON d.id = c.department_id
     JOIN semesters se ON se.id = co.semester_id
     JOIN faculties of ON of.id = se.faculty_id
     JOIN academic_years ay ON ay.id = se.academic_year_id
     LEFT JOIN departments rd ON rd.id = co.roster_department_id
     WHERE co.lecturer_id = ?
     ORDER BY se.start_date DESC, co.shift'
);
$teachingStmt->bind_param('i', $lecturerId);
$teachingStmt->execute();
$teaching = $teachingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$teachingStmt->close();

// ---------------------------------------------------------------------
// Cascade data for the "Assign to a new course" form: Faculty ->
// Department -> Course, and Faculty -> Semester in parallel.
// ---------------------------------------------------------------------
$faculties = $role === 'dean'
    ? array_filter($conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC), static fn ($f) => (int) $f['id'] === $deanFacultyId)
    : $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$departments = $conn->query(
    'SELECT id, name, faculty_id FROM departments ORDER BY name'
)->fetch_all(MYSQLI_ASSOC);
$departmentsByFacultyId = [];
foreach ($departments as $d) {
    $departmentsByFacultyId[(int) $d['faculty_id']][] = ['id' => (int) $d['id'], 'name' => $d['name']];
}

$courses = $conn->query(
    'SELECT id, code, name, department_id FROM courses ORDER BY code'
)->fetch_all(MYSQLI_ASSOC);
$coursesByDepartmentId = [];
foreach ($courses as $c) {
    $coursesByDepartmentId[(int) $c['department_id']][] = ['id' => (int) $c['id'], 'label' => $c['code'] . ' — ' . $c['name']];
}

$semesters = $conn->query(
    'SELECT s.id, s.name, s.faculty_id, s.is_current, s.status, ay.label AS academic_year_label
     FROM semesters s
     JOIN academic_years ay ON ay.id = s.academic_year_id
     WHERE s.faculty_id IS NOT NULL
     ORDER BY s.start_date DESC'
)->fetch_all(MYSQLI_ASSOC);
$semestersByFacultyId = [];
foreach ($semesters as $sem) {
    $semestersByFacultyId[(int) $sem['faculty_id']][] = [
        'id' => (int) $sem['id'],
        'name' => $sem['name'],
        'academic_year_label' => $sem['academic_year_label'],
        'is_current' => (int) $sem['is_current'] === 1,
        'status' => $sem['status'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Courses — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div class="page-body">
            <a href="<?= htmlspecialchars($backUrl) ?>" class="scope-banner text-decoration-none d-inline-flex">
                <i class="bi bi-arrow-left"></i>&nbsp; Back to Lecturers
            </a>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1 mt-3">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">
                        Assign Courses — <?= htmlspecialchars($lecturer['full_name']) ?>
                    </h4>
                    <p class="text-muted mb-0">
                        <span class="badge-pill badge-active"><?= htmlspecialchars($lecturer['staff_no']) ?></span>
                        Home: <?= htmlspecialchars($lecturer['department_name']) ?>, <?= htmlspecialchars($lecturer['faculty_name']) ?>
                    </p>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                    <?= htmlspecialchars($successMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
                    <?= htmlspecialchars($errorMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row g-3 mt-1">
                <div class="col-lg-<?= $role !== 'university_rector' ? '8' : '12' ?>">
                    <div class="admas-card p-4">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Everything This Lecturer Teaches</h6>
                        <div class="table-responsive">
                            <table class="table admas-table align-middle">
                                <thead>
                                    <tr>
                                        <th>Course</th>
                                        <th>Semester</th>
                                        <th>Shift</th>
                                        <th>Faculty</th>
                                        <th>Academic Year</th>
                                        <th>Teaching Period</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($teaching)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">Not assigned to any course yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($teaching as $t): ?>
                                            <?php $isGuestRow = (int) $t['offering_faculty_id'] !== (int) $t['home_faculty_id']; ?>
                                            <tr>
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($t['code'] . ' — ' . $t['course_name']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars($t['semester_name']) ?>
                                                    <?php if ((int) $t['is_current'] === 1): ?>
                                                        <span class="badge-pill badge-active">Current</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars(OFFERING_SHIFT_LABELS[$t['shift']] ?? $t['shift']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars($t['faculty_name']) ?>
                                                    <?php if ($isGuestRow): ?>
                                                        <span class="badge-pill badge-warning" title="This course's catalog home is a different faculty">Guest</span>
                                                    <?php endif; ?>
                                                    <?php if ($t['roster_department_name']): ?>
                                                        <div class="text-muted small">Roster: <?= htmlspecialchars($t['roster_department_name']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($t['academic_year_label']) ?></td>
                                                <td>
                                                    <?php if ($t['start_date'] || $t['end_date']): ?>
                                                        <?= htmlspecialchars(($t['start_date'] ?? '?') . ' to ' . ($t['end_date'] ?? '?')) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic">Not set</span>
                                                    <?php endif; ?>
                                                    <?php if ($t['day_of_week'] && $t['start_time'] && $t['end_time']): ?>
                                                        <div class="text-muted small"><?= htmlspecialchars(DAY_OF_WEEK_LABELS[$t['day_of_week']] ?? $t['day_of_week']) ?>, <?= htmlspecialchars(format_timetable_time($t['start_time']) . ' - ' . format_timetable_time($t['end_time'])) ?><?= $t['room'] ? ' · ' . htmlspecialchars($t['room']) : '' ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (role_may_edit_faculty($role, $deanFacultyId, (int) $t['offering_faculty_id'])): ?>
                                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer_courses.php"
                                                              onsubmit="return confirm('Remove this assignment?');">
                                                            <input type="hidden" name="action" value="remove_offering">
                                                            <input type="hidden" name="lecturer_id" value="<?= (int) $lecturerId ?>">
                                                            <input type="hidden" name="offering_id" value="<?= (int) $t['offering_id'] ?>">
                                                            <button type="submit" class="btn-icon-label text-danger" title="Remove">
                                                                <i class="bi bi-trash"></i> Remove
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted small fst-italic">Other faculty</span>
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

                <?php if ($role !== 'university_rector'): ?>
                <div class="col-lg-4">
                    <div class="admas-card p-4">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Assign to a New Course</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer_courses.php">
                            <input type="hidden" name="action" value="assign_course">
                            <input type="hidden" name="lecturer_id" value="<?= (int) $lecturerId ?>">

                            <div class="mb-3">
                                <label class="form-label small mb-1">Faculty</label>
                                <?php if ($role === 'dean'): ?>
                                    <select class="form-select form-select-sm" id="assignFacultySelect" disabled>
                                        <?php foreach ($faculties as $f): ?>
                                            <option selected><?= htmlspecialchars($f['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" id="assignFacultyHidden" value="<?= (int) $deanFacultyId ?>">
                                    <div class="form-text">Locked to your own faculty.</div>
                                <?php else: ?>
                                    <select class="form-select form-select-sm" id="assignFacultySelect" onchange="admasUpdateAssignDepartments(this.value)">
                                        <option value="">Select faculty</option>
                                        <?php foreach ($faculties as $f): ?>
                                            <option value="<?= (int) $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small mb-1">Department</label>
                                <select class="form-select form-select-sm" id="assignDepartmentSelect" onchange="admasUpdateAssignCourses(this.value)">
                                    <option value="">Select faculty first</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small mb-1">Course</label>
                                <select class="form-select form-select-sm" name="course_id" id="assignCourseSelect">
                                    <option value="">Select department first</option>
                                </select>
                            </div>

                            <?php if ($role !== 'dean'): ?>
                            <div class="mb-3">
                                <label class="form-label small mb-1">Offering Faculty</label>
                                <select class="form-select form-select-sm" id="assignOfferingFacultySelect" onchange="admasUpdateAssignSemesters(this.value); admasUpdateAssignRosterVisibility();">
                                    <option value="">Select faculty</option>
                                    <?php foreach ($faculties as $f): ?>
                                        <option value="<?= (int) $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Defaults to the course's own faculty. Change it to assign this lecturer into a different faculty's semester (cross-listing).</div>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label small mb-1">Semester</label>
                                <select class="form-select form-select-sm" name="semester_id" id="assignSemesterSelect">
                                    <option value="">Select faculty first</option>
                                </select>
                            </div>

                            <?php if ($role !== 'dean'): ?>
                            <div class="mb-3 d-none" id="assignRosterDepartmentBlock">
                                <label class="form-label small mb-1">Roster Department</label>
                                <select class="form-select form-select-sm" name="roster_department_id" id="assignRosterDepartmentSelect">
                                    <option value="">Select roster department</option>
                                </select>
                                <div class="form-text">Required for a cross-faculty offering — decides which department's students form this offering's roster.</div>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label small mb-1">Shift</label>
                                <select class="form-select form-select-sm" name="shift" required>
                                    <?php foreach (OFFERING_SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                        <option value="<?= htmlspecialchars($shiftValue) ?>"><?= htmlspecialchars($shiftLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small mb-1">Start Date</label>
                                    <input type="date" class="form-control form-control-sm" name="start_date" id="assignStartDate">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">End Date</label>
                                    <input type="date" class="form-control form-control-sm" name="end_date" id="assignEndDate">
                                </div>
                            </div>
                            <div class="form-text mb-3">Optional — this course's actual teaching period within the selected semester. End Date auto-fills 3 months after Start Date (same as a semester's 12 Xiiso sessions); you can still edit it by hand.</div>

                            <div class="mb-3">
                                <label class="form-label small mb-1">Class Time Table — Day</label>
                                <select class="form-select form-select-sm" name="day_of_week">
                                    <option value="">Not scheduled</option>
                                    <?php foreach (DAY_OF_WEEK_LABELS as $dayValue => $dayLabel): ?>
                                        <option value="<?= htmlspecialchars($dayValue) ?>"><?= htmlspecialchars($dayLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small mb-1">Start Time</label>
                                    <input type="time" class="form-control form-control-sm" name="start_time">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">End Time</label>
                                    <input type="time" class="form-control form-control-sm" name="end_time">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small mb-1">Room <span class="text-muted small">(optional)</span></label>
                                <input type="text" class="form-control form-control-sm" name="room" maxlength="50" placeholder="e.g. Room 2">
                            </div>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                <i class="bi bi-plus-lg"></i> Assign
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/offering_dates.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/semester_label.js"></script>
    <script>
        const departmentsByFacultyId = <?= json_encode($departmentsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const coursesByDepartmentId = <?= json_encode($coursesByDepartmentId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const semestersByFacultyId = <?= json_encode($semestersByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function admasUpdateAssignDepartments(facultyId) {
            const deptSelect = document.getElementById('assignDepartmentSelect');
            deptSelect.innerHTML = '';
            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = facultyId ? 'Select department' : 'Select faculty first';
            deptSelect.appendChild(blank);
            (departmentsByFacultyId[facultyId] || []).forEach((d) => {
                const opt = document.createElement('option');
                opt.value = String(d.id);
                opt.textContent = d.name;
                deptSelect.appendChild(opt);
            });
            admasUpdateAssignCourses('');

            // Offering Faculty defaults to the course's own faculty (still
            // independently changeable afterward for cross-listing).
            const offeringFacultySelect = document.getElementById('assignOfferingFacultySelect');
            if (offeringFacultySelect) {
                offeringFacultySelect.value = facultyId;
                admasUpdateAssignSemesters(facultyId);
                admasUpdateAssignRosterVisibility();
            } else {
                admasUpdateAssignSemesters(facultyId);
            }
        }

        function admasUpdateAssignRosterVisibility() {
            const rosterBlock = document.getElementById('assignRosterDepartmentBlock');
            const rosterSelect = document.getElementById('assignRosterDepartmentSelect');
            const facultySelect = document.getElementById('assignFacultySelect');
            const offeringFacultySelect = document.getElementById('assignOfferingFacultySelect');
            if (!rosterBlock || !rosterSelect || !facultySelect || !offeringFacultySelect) {
                return;
            }

            const homeFacultyId = facultySelect.value;
            const offeringFacultyId = offeringFacultySelect.value;
            const isGuest = offeringFacultyId !== '' && offeringFacultyId !== homeFacultyId;

            if (isGuest) {
                rosterBlock.classList.remove('d-none');
                const depts = departmentsByFacultyId[offeringFacultyId] || [];
                const priorValue = rosterSelect.value;
                rosterSelect.innerHTML = '';
                const blank = document.createElement('option');
                blank.value = '';
                blank.textContent = depts.length ? 'Select roster department' : 'No departments in this faculty yet';
                rosterSelect.appendChild(blank);
                depts.forEach((d) => {
                    const opt = document.createElement('option');
                    opt.value = String(d.id);
                    opt.textContent = d.name;
                    rosterSelect.appendChild(opt);
                });
                rosterSelect.value = priorValue;
            } else {
                rosterBlock.classList.add('d-none');
                rosterSelect.value = '';
            }
        }

        function admasUpdateAssignCourses(departmentId) {
            const courseSelect = document.getElementById('assignCourseSelect');
            courseSelect.innerHTML = '';
            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = departmentId ? 'Select course' : 'Select department first';
            courseSelect.appendChild(blank);
            (coursesByDepartmentId[departmentId] || []).forEach((c) => {
                const opt = document.createElement('option');
                opt.value = String(c.id);
                opt.textContent = c.label;
                courseSelect.appendChild(opt);
            });
        }

        function admasUpdateAssignSemesters(facultyId) {
            const semSelect = document.getElementById('assignSemesterSelect');
            semSelect.innerHTML = '';
            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = facultyId ? 'Select semester' : 'Select faculty first';
            semSelect.appendChild(blank);
            (semestersByFacultyId[facultyId] || []).forEach((s) => {
                const opt = document.createElement('option');
                opt.value = String(s.id);
                opt.textContent = admasSemesterLabel(s);
                semSelect.appendChild(opt);
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            // The whole "Assign to a New Course" form (and every element
            // below) only exists in the DOM for non-Dean roles — Dean gets
            // a read-only teaching list with no form at all, so every
            // lookup here must be null-safe.
            const assignDepartmentSelect = document.getElementById('assignDepartmentSelect');
            if (assignDepartmentSelect) {
                const hiddenFaculty = document.getElementById('assignFacultyHidden');
                if (hiddenFaculty) {
                    admasUpdateAssignDepartments(hiddenFaculty.value);
                }
                assignDepartmentSelect.addEventListener('change', (e) => admasUpdateAssignCourses(e.target.value));
                admasWireOfferingDateAutoFill('assignStartDate', 'assignEndDate');
            }
        });
    </script>
</body>
</html>
