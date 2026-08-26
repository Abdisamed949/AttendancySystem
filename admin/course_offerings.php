<?php
/**
 * Manage Offerings — per-course, per-semester lecturer assignment.
 * Reachable via the "Manage Offerings" link on a course row in
 * admin/courses.php (?course_id=X), or via
 * admin/course_offerings_search.php's cross-faculty course search —
 * deliberately no standalone sidebar item, since this page is meaningless
 * without a course already picked.
 *
 * University Rector and Head of Academic Affairs (per the CLAUDE.md §4
 * revision granting this role cross-faculty Course Management): any
 * course, any faculty's semester — head_academic falls through the same
 * unrestricted branch as university_rector throughout this file. Dean: may
 * VIEW any course's offerings (read-only, schedule-metadata-only —
 * same precedent as lecturer_courses.php), but may only ADD/EDIT/DELETE
 * an offering whose semester belongs to their OWN faculty — never the
 * course's own catalog faculty. This is what makes cross-listing possible:
 * a course whose catalog home is a different faculty can still get an
 * offering inside the Dean's own faculty's semester track. See the
 * Multi-Faculty Course Offerings plan.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';
require_once __DIR__ . '/../includes/timetable_helpers.php';

require_role(['university_rector', 'head_academic', 'dean']);

$conn = db();
$currentUser = current_user();
$role = current_role();
// University Rector is a supervisory, read-only Viewer everywhere. Dean
// has full CRUD again within their own faculty (restored per explicit
// request, after an earlier session's temporary Viewer conversion) — the
// existing $role === 'dean' scoping throughout this file still narrows
// everything to their own faculty only.
$isReadOnly = $role === 'university_rector';

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

// ---------------------------------------------------------------------
// The course this page is scoped to (never a standalone browse — a
// missing course_id bounces back to admin/courses.php). Viewing is NOT
// faculty-restricted for either role — a Dean may look up any course
// system-wide in order to cross-list it into their own faculty's
// semester (see admin/course_offerings_search.php); write actions below
// are what actually enforce the Dean's own-faculty boundary.
// ---------------------------------------------------------------------
$courseId = (int) ($_POST['course_id'] ?? $_GET['course_id'] ?? 0);

$courseStmt = $conn->prepare(
    'SELECT c.id, c.code, c.name, c.department_id, d.faculty_id, d.name AS department_name, f.name AS faculty_name
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     JOIN faculties f ON f.id = d.faculty_id
     WHERE c.id = ?'
);
$courseStmt->bind_param('i', $courseId);
$courseStmt->execute();
$course = $courseStmt->get_result()->fetch_assoc();
$courseStmt->close();

if (!$course) {
    $_SESSION['flash_error'] = 'Course not found.';
    redirect_to('admin/courses.php');
}

$courseFacultyId = (int) $course['faculty_id'];
$courseDepartmentId = (int) $course['department_id'];

// ---------------------------------------------------------------------
// Flash messages (post-redirect-get, same pattern as the other admin pages)
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

    if ($isReadOnly) {
        $_SESSION['flash_error'] = 'Access scope: View only — this role cannot modify records.';
        redirect_to('admin/course_offerings.php?course_id=' . $courseId);
    }

    if ($action === 'save_offering') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $lecturerId = (int) ($_POST['lecturer_id'] ?? 0);
        $rosterDepartmentIdRaw = (int) ($_POST['roster_department_id'] ?? 0);
        $shift = (string) ($_POST['shift'] ?? '');
        $startDateRaw = trim((string) ($_POST['start_date'] ?? ''));
        $endDateRaw = trim((string) ($_POST['end_date'] ?? ''));
        $dayOfWeekRaw = trim((string) ($_POST['day_of_week'] ?? ''));
        $startTimeRaw = trim((string) ($_POST['start_time'] ?? ''));
        $endTimeRaw = trim((string) ($_POST['end_time'] ?? ''));
        $roomRaw = trim((string) ($_POST['room'] ?? ''));

        // Semester scoping: System Admin may target any faculty's
        // semester; a Dean may only target their OWN faculty's semester —
        // never the course's own catalog faculty — which is precisely
        // what makes cross-listing a foreign course into the Dean's own
        // faculty possible (see the Multi-Faculty Course Offerings plan).
        if ($role === 'dean') {
            $semStmt = $conn->prepare('SELECT id, name, start_date, end_date, faculty_id FROM semesters WHERE id = ? AND faculty_id = ?');
            $semStmt->bind_param('ii', $semesterId, $deanFacultyId);
        } else {
            $semStmt = $conn->prepare('SELECT id, name, start_date, end_date, faculty_id FROM semesters WHERE id = ?');
            $semStmt->bind_param('i', $semesterId);
        }
        $semStmt->execute();
        $semRow = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        $startDate = null;
        $endDate = null;
        $rosterDepartmentId = null;
        $isGuestOffering = $semRow && (int) $semRow['faculty_id'] !== $courseFacultyId;

        $validationError = '';
        if (!$semRow) {
            $validationError = $role === 'dean'
                ? 'Please select a valid semester from your own faculty.'
                : 'Please select a valid semester.';
        } elseif (!array_key_exists($shift, OFFERING_SHIFT_LABELS)) {
            $validationError = 'Please select a valid shift.';
        } elseif ($rosterDepartmentIdRaw > 0) {
            // Roster Department, when given, must belong to the semester's
            // own faculty — it decides which department's students form
            // THIS offering's roster (see get_xiiso_grid_data()'s fallback).
            $deptStmt = $conn->prepare('SELECT id FROM departments WHERE id = ? AND faculty_id = ?');
            $deptStmt->bind_param('ii', $rosterDepartmentIdRaw, $semRow['faculty_id']);
            $deptStmt->execute();
            $deptRow = $deptStmt->get_result()->fetch_assoc();
            $deptStmt->close();
            if (!$deptRow) {
                $validationError = 'Roster Department must belong to the selected semester\'s own faculty.';
            } else {
                $rosterDepartmentId = $rosterDepartmentIdRaw;
            }
        } elseif ($isGuestOffering) {
            $validationError = 'This course\'s catalog home is a different faculty — please select a Roster Department so the correct students are used for this offering.';
        }

        if ($validationError === '' && $lecturerId > 0) {
            // Any active lecturer system-wide may be assigned, not just
            // ones in this course's own department — universities share
            // "common" courses across faculties via one lecturer, and this
            // only ever creates a course_offerings row inside the TARGET
            // semester's faculty (never grants access to the lecturer's
            // home faculty's data), so a Dean's "own faculty only" write
            // scope still holds even though the course itself may be
            // catalogued elsewhere.
            $lecStmt = $conn->prepare("SELECT id FROM lecturers WHERE id = ? AND status = 'active'");
            $lecStmt->bind_param('i', $lecturerId);
            $lecStmt->execute();
            if (!$lecStmt->get_result()->fetch_assoc()) {
                $validationError = 'Selected lecturer is not a valid active lecturer.';
            }
            $lecStmt->close();
        }

        // Start/End Date are both optional, but if given must be real dates,
        // and together must not run backwards.
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

        // Class Time Table — all optional, all-or-nothing only for the
        // time pair (a day picked with no time, or a time with no day,
        // isn't a usable slot). Room is cosmetic only — no validation
        // beyond a length cap, and no double-booking check by design.
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

        // Soft check only: dates outside the semester's own range are
        // flagged to the user but do not block the save — a lecturer's
        // real teaching period can legitimately run a little short of, or
        // (rarely) past, the semester's nominal boundaries.
        $rangeWarning = '';
        if ($validationError === '' && $semRow && ($startDate !== null || $endDate !== null)) {
            $outOfRange = ($startDate !== null && ($startDate < $semRow['start_date'] || $startDate > $semRow['end_date']))
                || ($endDate !== null && ($endDate < $semRow['start_date'] || $endDate > $semRow['end_date']));
            if ($outOfRange) {
                $rangeWarning = ' Warning: the dates you entered fall outside "' . $semRow['name'] . '"\'s own range ('
                    . $semRow['start_date'] . ' to ' . $semRow['end_date'] . ') — saved anyway, double-check they\'re correct.';
            }
        }

        if ($validationError === '') {
            $lecturerParam = $lecturerId > 0 ? $lecturerId : null;
            $rosterDepartmentParam = $rosterDepartmentId;
            // One offering per (course, semester, shift) — upsert, since
            // re-saving an existing semester+shift's row should update the
            // lecturer/roster department/dates, not error as a duplicate. A
            // different shift for the same course+semester is a separate
            // row entirely, not a collision.
            $upsertStmt = $conn->prepare(
                'INSERT INTO course_offerings (course_id, semester_id, lecturer_id, roster_department_id, shift, start_date, end_date, day_of_week, start_time, end_time, room) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE lecturer_id = VALUES(lecturer_id), roster_department_id = VALUES(roster_department_id), start_date = VALUES(start_date), end_date = VALUES(end_date), day_of_week = VALUES(day_of_week), start_time = VALUES(start_time), end_time = VALUES(end_time), room = VALUES(room)'
            );
            $upsertStmt->bind_param('iiiisssssss', $courseId, $semesterId, $lecturerParam, $rosterDepartmentParam, $shift, $startDate, $endDate, $dayOfWeek, $startTime, $endTime, $room);
            $upsertStmt->execute();
            $upsertStmt->close();

            $_SESSION['flash_success'] = 'Offering saved for "' . $semRow['name'] . '" (' . OFFERING_SHIFT_LABELS[$shift] . ').' . $rangeWarning;
            redirect_to('admin/course_offerings.php?course_id=' . $courseId);
        }

        $errorMessage = $validationError;
    } elseif ($action === 'delete_offering') {
        $offeringId = (int) ($_POST['offering_id'] ?? 0);

        // A Dean may view offerings across every faculty (see the course
        // lookup above) but may only DELETE one that belongs to their own
        // faculty's semester — never the course's catalog faculty.
        if ($role === 'dean') {
            $ownStmt = $conn->prepare(
                'SELECT co.id FROM course_offerings co JOIN semesters se ON se.id = co.semester_id
                 WHERE co.id = ? AND co.course_id = ? AND se.faculty_id = ?'
            );
            $ownStmt->bind_param('iii', $offeringId, $courseId, $deanFacultyId);
            $ownStmt->execute();
            $owned = $ownStmt->get_result()->fetch_assoc();
            $ownStmt->close();
            if (!$owned) {
                $_SESSION['flash_error'] = 'That offering does not belong to your faculty.';
                redirect_to('admin/course_offerings.php?course_id=' . $courseId);
            }
        }

        $deleteStmt = $conn->prepare('DELETE FROM course_offerings WHERE id = ? AND course_id = ?');
        $deleteStmt->bind_param('ii', $offeringId, $courseId);
        $deleteStmt->execute();
        $deleteStmt->close();

        $_SESSION['flash_success'] = 'Offering removed.';
        redirect_to('admin/course_offerings.php?course_id=' . $courseId);
    }
}

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------

// Semester options: System Admin sees every faculty's semesters (grouped by
// faculty below); a Dean sees only their OWN faculty's semesters — never
// the course's own catalog faculty — which is what makes cross-listing a
// foreign course into the Dean's own faculty possible.
if ($role === 'dean') {
    $semestersStmt = $conn->prepare(
        'SELECT s.id, s.name, s.is_current, s.status, s.faculty_id, f.name AS faculty_name, ay.label AS academic_year_label
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         JOIN faculties f ON f.id = s.faculty_id
         WHERE s.faculty_id = ?
         ORDER BY s.start_date DESC'
    );
    $semestersStmt->bind_param('i', $deanFacultyId);
} else {
    $semestersStmt = $conn->prepare(
        'SELECT s.id, s.name, s.is_current, s.status, s.faculty_id, f.name AS faculty_name, ay.label AS academic_year_label
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         JOIN faculties f ON f.id = s.faculty_id
         ORDER BY f.name, s.start_date DESC'
    );
}
$semestersStmt->execute();
$semesters = $semestersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$semestersStmt->close();

// Group semesters by faculty for the <optgroup>-per-faculty select below —
// for a Dean this always resolves to exactly one group (their own faculty).
$semestersByFaculty = [];
foreach ($semesters as $s) {
    $semestersByFaculty[$s['faculty_name']][] = $s;
}

// Every department system-wide, grouped by faculty — feeds the Roster
// Department field's JS cascade (rebuilt client-side to whichever faculty
// the currently-selected semester belongs to).
$allDepartmentsStmt = $conn->prepare('SELECT id, name, faculty_id FROM departments ORDER BY faculty_id, name');
$allDepartmentsStmt->execute();
$allDepartments = $allDepartmentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$allDepartmentsStmt->close();

$departmentsByFacultyId = [];
foreach ($allDepartments as $d) {
    $departmentsByFacultyId[(int) $d['faculty_id']][] = ['id' => (int) $d['id'], 'name' => $d['name']];
}

// Every active lecturer system-wide, not just this course's own department
// — universities share "common" courses across faculties via one
// lecturer (see the ownership note on the lecturer validation below). Each
// lecturer's own home faculty is fetched too, so the dropdown can label
// outside lecturers (e.g. "Jane Doe (Health Sciences)") instead of just
// listing everyone with no context.
$lecturersStmt = $conn->prepare(
    "SELECT l.id, l.full_name, f.name AS home_faculty_name, (l.department_id = ?) AS is_own_department
     FROM lecturers l
     JOIN departments d ON d.id = l.department_id
     JOIN faculties f ON f.id = d.faculty_id
     WHERE l.status = 'active'
     ORDER BY is_own_department DESC, l.full_name"
);
$lecturersStmt->bind_param('i', $courseDepartmentId);
$lecturersStmt->execute();
$lecturers = $lecturersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lecturersStmt->close();

// Every faculty's offerings for this course, not just the course's own
// catalog faculty — schedule metadata only (semester/shift/faculty/
// academic year/lecturer/teaching period), the same "cross-faculty
// visibility is fine, cross-faculty student/attendance data is not"
// precedent already established by lecturer_courses.php.
$offeringsStmt = $conn->prepare(
    'SELECT co.id, co.semester_id, co.lecturer_id, co.roster_department_id, co.shift, co.start_date, co.end_date,
            co.day_of_week, co.start_time, co.end_time, co.room,
            s.name AS semester_name, s.is_current, s.status AS semester_status, s.start_date AS semester_start_date, s.end_date AS semester_end_date,
            s.faculty_id AS offering_faculty_id, f.name AS offering_faculty_name,
            ay.label AS academic_year_label, l.full_name AS lecturer_name, rd.name AS roster_department_name
     FROM course_offerings co
     JOIN semesters s ON s.id = co.semester_id
     JOIN faculties f ON f.id = s.faculty_id
     JOIN academic_years ay ON ay.id = s.academic_year_id
     LEFT JOIN lecturers l ON l.id = co.lecturer_id
     LEFT JOIN departments rd ON rd.id = co.roster_department_id
     WHERE co.course_id = ?
     ORDER BY s.start_date DESC, co.shift'
);
$offeringsStmt->bind_param('i', $courseId);
$offeringsStmt->execute();
$offerings = $offeringsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$offeringsStmt->close();


// (semester_id, shift) pairs already offered — used both by the "already
// offered" JS annotation below and, indirectly, by uniqueness itself. This
// must always reflect EVERY offering, not just the current page, so it's
// built from the full $offerings result before pagination is applied below.
$offeredSemesterShiftPairs = [];
foreach ($offerings as $o) {
    $offeredSemesterShiftPairs[(int) $o['semester_id']][$o['shift']] = $o['lecturer_name'] ?: 'Unassigned';
}

// Pagination — a course cross-listed across many faculties/semesters/
// shifts over the years can accumulate a long offerings list. Applied
// in-memory to the render slice only (not a second query) since the
// "already offered" annotation above needs the full, unpaginated set
// regardless.
const OFFERINGS_PER_PAGE = 20;
$totalOfferings = count($offerings);
$totalOfferingPages = max(1, (int) ceil($totalOfferings / OFFERINGS_PER_PAGE));
$offeringsPage = max(1, min($totalOfferingPages, (int) ($_GET['page'] ?? 1)));
$offeringsOffset = ($offeringsPage - 1) * OFFERINGS_PER_PAGE;
$offeringsForDisplay = array_slice($offerings, $offeringsOffset, OFFERINGS_PER_PAGE);

// ---------------------------------------------------------------------
// Group the current page's offerings into one card per semester — same
// visual pattern as lecturer/teaching_history.php / lecturer/courses.php —
// applied only within this page's own slice (a single course+semester can
// hold at most a handful of shifts, so a semester being split across two
// pagination pages in practice never happens).
// ---------------------------------------------------------------------
$offeringsBySemesterId = [];
$offeringSemesterMeta = [];
foreach ($offeringsForDisplay as $o) {
    $sid = (int) $o['semester_id'];
    if (!isset($offeringSemesterMeta[$sid])) {
        $offeringSemesterMeta[$sid] = [
            'id' => $sid,
            'name' => $o['semester_name'],
            'status' => $o['semester_status'],
            'academic_year_label' => $o['academic_year_label'],
            'start_date' => $o['semester_start_date'],
            'end_date' => $o['semester_end_date'],
        ];
    }
    $offeringsBySemesterId[$sid][] = $o;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Offerings — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        /* Same semester-card visual pattern as lecturer/teaching_history.php
           and lecturer/courses.php — applied here so a course's offerings
           read as one card per semester instead of one long flat table. */
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
            <div class="d-flex flex-wrap gap-2 mb-4">
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="btn btn-primary px-4 py-2" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                    <i class="bi bi-arrow-left"></i> Back to Courses
                </a>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_enrollments.php?course_id=<?= (int) $courseId ?>" class="btn btn-primary px-4 py-2" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                    <i class="bi bi-person-plus"></i> Enroll Students
                </a>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">
                        Manage Offerings — <?= htmlspecialchars($course['code'] . ' — ' . $course['name']) ?>
                    </h4>
                    <?= render_scope_breadcrumb([
                        $course['code'],
                        $course['department_name'],
                        $course['faculty_name'],
                    ]) ?>
                    <p class="text-muted mb-0">Which lecturer teaches this course, per semester — Academic Year in the table below is derived from each row's own Semester. A course can now also be cross-listed into a different faculty's own semester track (shown in the Faculty column below).</p>
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

            <div class="row g-4">
                <div class="<?= $isReadOnly ? 'col-lg-12' : 'col-lg-7' ?>">
                    <h6 class="small text-uppercase text-muted mb-2">Offerings</h6>

                    <?php if (empty($offerings)): ?>
                        <div class="admas-card p-4 text-center text-muted py-5">No offerings yet — add one on the right.</div>
                    <?php endif; ?>

                    <?php
                    $semAccents = ['semester-accent-sky', 'semester-accent-navy', 'semester-accent-amber'];
                    $semIndex = 0;
                    ?>
                    <?php foreach ($offeringSemesterMeta as $sem): ?>
                        <?php
                        $semOfferings = $offeringsBySemesterId[$sem['id']] ?? [];
                        $accentClass = $sem['status'] === 'current' ? 'semester-current' : $semAccents[$semIndex % count($semAccents)];
                        $semIndex++;
                        $statusLabel = ['current' => 'Current', 'waiting' => 'Waiting', 'ended' => 'Ended'][$sem['status']] ?? ucfirst((string) $sem['status']);
                        $statusBadge = ['current' => 'badge-present', 'waiting' => 'badge-warning', 'ended' => 'badge-inactive'][$sem['status']] ?? 'badge-inactive';
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
                                <span class="text-muted small"><?= count($semOfferings) ?> offering<?= count($semOfferings) === 1 ? '' : 's' ?></span>
                            </div>

                            <div class="table-responsive">
                                <table class="table admas-table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Faculty</th>
                                            <th>Shift</th>
                                            <th>Lecturer</th>
                                            <th>Enrolled</th>
                                            <th>Roster Department</th>
                                            <th>Teaching Period</th>
                                            <th>Class Time Table</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($semOfferings as $o): ?>
                                            <?php $isGuestRow = (int) $o['offering_faculty_id'] !== $courseFacultyId; ?>
                                            <tr>
                                                <td>
                                                    <?= htmlspecialchars($o['offering_faculty_name']) ?>
                                                    <?php if ($isGuestRow): ?>
                                                        <span class="badge-pill badge-warning">Guest</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars(OFFERING_SHIFT_LABELS[$o['shift']] ?? $o['shift']) ?></td>
                                                <td>
                                                    <?php if ($o['lecturer_name']): ?>
                                                        <?= htmlspecialchars($o['lecturer_name']) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic">Unassigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    // Real roster size for THIS specific offering — same
                                                    // get_course_roster_count() resolution (explicit
                                                    // course_enrollments first, else the offering's own
                                                    // roster_department_id/course's own department) as
                                                    // admin/courses.php's own "Current Offering" column uses,
                                                    // so the two pages can never disagree on the same course
                                                    // again (previously this page only ever counted explicit
                                                    // course_enrollments, one flat number shared by every row,
                                                    // regardless of that row's own semester/shift/roster
                                                    // department).
                                                    $offEnrolledCount = get_course_roster_count(
                                                        $conn,
                                                        $courseId,
                                                        (int) $o['semester_id'],
                                                        $o['shift'] !== 'any' ? $o['shift'] : null
                                                    );
                                                    $offeringIsComplete = $o['lecturer_name'] !== null && $offEnrolledCount > 0;
                                                    ?>
                                                    <div class="fw-semibold" style="color: var(--admas-text);">
                                                        <i class="bi bi-people-fill text-muted"></i> <?= number_format($offEnrolledCount) ?>
                                                    </div>
                                                    <?php if ($offeringIsComplete): ?>
                                                        <span class="badge-pill badge-active"><i class="bi bi-check-circle-fill"></i> Complete</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill badge-warning" title="Missing: <?= htmlspecialchars(trim(($o['lecturer_name'] !== null ? '' : 'lecturer ') . ($offEnrolledCount > 0 ? '' : 'enrolled students'))) ?>">
                                                            <i class="bi bi-exclamation-triangle-fill"></i> Incomplete
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($offEnrolledCount === 0): ?>
                                                        <div><a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_enrollments.php?course_id=<?= (int) $courseId ?>" class="small">Enroll students &rarr;</a></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($o['roster_department_name']): ?>
                                                        <?= htmlspecialchars($o['roster_department_name']) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic small">Course's own department</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($o['start_date'] || $o['end_date']): ?>
                                                        <span class="small"><?= htmlspecialchars(($o['start_date'] ?? '?') . ' to ' . ($o['end_date'] ?? '?')) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic small">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($o['day_of_week'] && $o['start_time'] && $o['end_time']): ?>
                                                        <span class="small fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars(DAY_OF_WEEK_LABELS[$o['day_of_week']] ?? $o['day_of_week']) ?></span><br>
                                                        <span class="small text-muted"><?= htmlspecialchars(format_timetable_time($o['start_time']) . ' - ' . format_timetable_time($o['end_time'])) ?><?= $o['room'] ? ' · ' . htmlspecialchars($o['room']) : '' ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic small">Not scheduled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($isReadOnly): ?>
                                                        <span class="text-muted small fst-italic">View only</span>
                                                    <?php elseif ($role !== 'dean' || (int) $o['offering_faculty_id'] === $deanFacultyId): ?>
                                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $courseId ?>" style="display:inline;"
                                                              onsubmit="return confirm('Remove this offering?');">
                                                            <input type="hidden" name="action" value="delete_offering">
                                                            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
                                                            <input type="hidden" name="offering_id" value="<?= (int) $o['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted small fst-italic">Other faculty</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($totalOfferingPages > 1): ?>
                        <div class="admas-card p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span class="text-muted small">
                                Showing <?= number_format($offeringsOffset + 1) ?>&ndash;<?= number_format(min($offeringsOffset + OFFERINGS_PER_PAGE, $totalOfferings)) ?>
                                of <?= number_format($totalOfferings) ?> offering<?= $totalOfferings === 1 ? '' : 's' ?>
                            </span>
                            <nav aria-label="Offerings pages">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?= $offeringsPage <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $courseId ?>&page=<?= max(1, $offeringsPage - 1) ?>">&lsaquo;</a>
                                    </li>
                                    <?php for ($p = 1; $p <= $totalOfferingPages; $p++): ?>
                                        <li class="page-item <?= $p === $offeringsPage ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $courseId ?>&page=<?= $p ?>"><?= $p ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $offeringsPage >= $totalOfferingPages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $courseId ?>&page=<?= min($totalOfferingPages, $offeringsPage + 1) ?>">&rsaquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$isReadOnly): ?>
                <div class="col-lg-5">
                    <div class="admas-card p-4">
                        <h6 class="small text-uppercase text-muted mb-2">Add / Update Offering</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $courseId ?>" class="d-flex flex-column gap-2">
                            <input type="hidden" name="action" value="save_offering">
                            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">

                            <div>
                                <label class="form-label small mb-1">Semester</label>
                                <select class="form-select form-select-sm" name="semester_id" id="offeringSemesterSelect" required onchange="admasUpdateAcademicYearDisplay(); admasUpdateAlreadyOfferedNote(); admasUpdateRosterDepartmentOptions();">
                                    <option value="">Select semester</option>
                                    <?php foreach ($semestersByFaculty as $facultyName => $facultySemesters): ?>
                                        <optgroup label="<?= htmlspecialchars($facultyName) ?>">
                                            <?php foreach ($facultySemesters as $s): ?>
                                                <option value="<?= (int) $s['id'] ?>" data-academic-year="<?= htmlspecialchars($s['academic_year_label']) ?>" data-faculty-id="<?= (int) $s['faculty_id'] ?>">
                                                    <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['academic_year_label']) ?> · <?= htmlspecialchars(ucfirst($s['status'])) ?>) — <?= htmlspecialchars($s['faculty_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($semesters)): ?>
                                    <div class="form-text text-danger">
                                        <?= $role === 'dean' ? 'Your faculty' : htmlspecialchars($course['faculty_name']) ?> has no semesters yet — create one on the
                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">Semesters</a> page first.
                                    </div>
                                <?php endif; ?>
                                <?php if ($role === 'dean'): ?>
                                    <div class="form-text">You may only create/edit offerings inside your own faculty's semesters, even for a course catalogued elsewhere.</div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label class="form-label small mb-1">Shift</label>
                                <select class="form-select form-select-sm" name="shift" id="offeringShiftSelect" required onchange="admasUpdateAlreadyOfferedNote()">
                                    <?php foreach (OFFERING_SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                        <option value="<?= htmlspecialchars($shiftValue) ?>"><?= htmlspecialchars($shiftLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="offeringAlreadyOfferedNote"></div>
                            </div>

                            <div>
                                <label class="form-label small mb-1">Academic Year</label>
                                <input type="text" class="form-control form-control-sm" id="offeringAcademicYearDisplay" value="" disabled placeholder="Derived from the selected semester">
                            </div>

                            <div>
                                <label class="form-label small mb-1">Lecturer</label>
                                <select class="form-select form-select-sm" name="lecturer_id">
                                    <option value="0">Unassigned</option>
                                    <?php foreach ($lecturers as $l): ?>
                                        <option value="<?= (int) $l['id'] ?>">
                                            <?= htmlspecialchars($l['full_name']) ?><?= $l['is_own_department'] ? '' : ' (' . htmlspecialchars($l['home_faculty_name']) . ')' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Any active lecturer may be assigned — outside lecturers are labeled with their home faculty.</div>
                            </div>

                            <div>
                                <label class="form-label small mb-1">Roster Department</label>
                                <select class="form-select form-select-sm" name="roster_department_id" id="offeringRosterDepartmentSelect">
                                    <option value="0">Use course's own department (default)</option>
                                </select>
                                <div class="form-text" id="offeringRosterDepartmentHint">Only needed for a cross-faculty (guest) offering — determines which department's students form this offering's roster.</div>
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small mb-1">Start Date</label>
                                    <input type="date" class="form-control form-control-sm" name="start_date" id="offeringStartDate">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">End Date</label>
                                    <input type="date" class="form-control form-control-sm" name="end_date" id="offeringEndDate">
                                </div>
                            </div>
                            <div class="form-text">Optional — this course's actual teaching period within the selected semester. End Date auto-fills 3 months after Start Date (same as a semester's 12 Xiiso sessions); you can still edit it by hand.</div>

                            <div class="mt-2">
                                <label class="form-label small mb-1">Class Time Table — Day</label>
                                <select class="form-select form-select-sm" name="day_of_week">
                                    <option value="">Not scheduled</option>
                                    <?php foreach (DAY_OF_WEEK_LABELS as $dayValue => $dayLabel): ?>
                                        <option value="<?= htmlspecialchars($dayValue) ?>"><?= htmlspecialchars($dayLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small mb-1">Start Time</label>
                                    <input type="time" class="form-control form-control-sm" name="start_time">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">End Time</label>
                                    <input type="time" class="form-control form-control-sm" name="end_time">
                                </div>
                            </div>
                            <div>
                                <label class="form-label small mb-1">Room <span class="text-muted small">(optional)</span></label>
                                <input type="text" class="form-control form-control-sm" name="room" maxlength="50" placeholder="e.g. Room 3">
                            </div>
                            <div class="form-text">Optional — shown on the Class Time Table. Leave the day/time blank if this offering isn't scheduled into a weekly slot yet.</div>

                            <button type="submit" class="btn btn-primary text-nowrap mt-2" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= empty($semesters) ? 'disabled' : '' ?>>
                                <i class="bi bi-save"></i> Save Offering
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
    <?php if (!$isReadOnly): ?>
    <script>
        function admasUpdateAcademicYearDisplay() {
            const select = document.getElementById('offeringSemesterSelect');
            const display = document.getElementById('offeringAcademicYearDisplay');
            const selected = select.options[select.selectedIndex];
            display.value = selected ? (selected.getAttribute('data-academic-year') || '') : '';
        }

        // (semester_id, shift) -> lecturer name, so picking that exact pair
        // shows "already offered — editing lecturer" instead of silently
        // looking like a new row is being created.
        const offeredSemesterShiftPairs = <?= json_encode($offeredSemesterShiftPairs, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function admasUpdateAlreadyOfferedNote() {
            const semesterId = document.getElementById('offeringSemesterSelect').value;
            const shift = document.getElementById('offeringShiftSelect').value;
            const note = document.getElementById('offeringAlreadyOfferedNote');
            const existing = (offeredSemesterShiftPairs[semesterId] || {})[shift];
            note.textContent = existing ? ('Already offered — editing lecturer (currently: ' + existing + ').') : '';
        }

        // faculty_id -> [{id, name}] departments, feeding the Roster
        // Department cascade below.
        const departmentsByFacultyId = <?= json_encode($departmentsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const courseFacultyId = <?= (int) $courseFacultyId ?>;
        const courseDepartmentId = <?= (int) $courseDepartmentId ?>;

        // Rebuilds the Roster Department options to the currently-selected
        // semester's own faculty, and marks the field required whenever
        // that faculty differs from the course's own catalog faculty (a
        // guest/cross-listed offering) — mirrors the exact same rule the
        // server re-validates on save.
        function admasUpdateRosterDepartmentOptions() {
            const semSelect = document.getElementById('offeringSemesterSelect');
            const selected = semSelect.options[semSelect.selectedIndex];
            const facultyId = selected ? parseInt(selected.getAttribute('data-faculty-id') || '0', 10) : 0;
            const deptSelect = document.getElementById('offeringRosterDepartmentSelect');
            const hint = document.getElementById('offeringRosterDepartmentHint');
            const isGuest = facultyId > 0 && facultyId !== courseFacultyId;

            const previousValue = deptSelect.value;
            deptSelect.innerHTML = '';
            const defaultOpt = document.createElement('option');
            defaultOpt.value = '0';
            defaultOpt.textContent = isGuest ? 'Select roster department (required)' : "Use course's own department (default)";
            deptSelect.appendChild(defaultOpt);

            const depts = departmentsByFacultyId[facultyId] || [];
            depts.forEach((d) => {
                const opt = document.createElement('option');
                opt.value = String(d.id);
                opt.textContent = d.name;
                deptSelect.appendChild(opt);
            });
            if (depts.some((d) => String(d.id) === previousValue)) {
                deptSelect.value = previousValue;
            }

            deptSelect.required = isGuest;
            hint.textContent = isGuest
                ? 'This course’s catalog home is a different faculty — pick which department’s students make up this offering’s roster.'
                : 'Only needed for a cross-faculty (guest) offering — leave as default to use the course’s own department.';
        }

        window.addEventListener('DOMContentLoaded', () => {
            admasUpdateAcademicYearDisplay();
            admasUpdateAlreadyOfferedNote();
            admasUpdateRosterDepartmentOptions();
            admasWireOfferingDateAutoFill('offeringStartDate', 'offeringEndDate');
        });
    </script>
    <?php endif; ?>
</body>
</html>
