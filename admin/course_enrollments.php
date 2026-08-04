<?php
/**
 * Enroll Students — manual course_enrollments management, per course.
 * Reachable via an "Enroll Students" icon-link on admin/courses.php's
 * course rows and on admin/course_offerings.php's own header (?course_id=X)
 * — deliberately no standalone sidebar item, same convention as
 * admin/course_offerings.php ("Manage Offerings"), which this page mirrors
 * structurally: a "currently enrolled" list on top, a student search/filter
 * + bulk-enroll form below.
 *
 * course_enrollments has no management UI anywhere else in this app —
 * every other page only reads it (counts, delete-blockers, roster
 * discovery in get_xiiso_grid_data()/student/courses.php). This is what
 * lets an admin backfill a real historical cohort's enrollment (e.g. "every
 * Information Technology student from Academic Year 2023/2024" took a
 * course under an already-ended semester) without writing SQL by hand.
 *
 * System Administrator: any course, any student. Dean: may VIEW any
 * course's enrollment (read-only for other faculties' students — this is
 * real student data, not schedule metadata, so unlike
 * admin/course_offerings.php a Dean never sees another faculty's enrolled
 * students at all), but may only browse/enroll/remove students from their
 * OWN faculty — re-verified server-side on every write, never trusted from
 * the filter alone.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['system_admin', 'dean']);

$conn = db();
$role = current_role();

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
$deanFacultyName = '';
if ($role === 'dean') {
    $deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
    if ($deanFacultyId > 0) {
        $fStmt = $conn->prepare('SELECT name FROM faculties WHERE id = ?');
        $fStmt->bind_param('i', $deanFacultyId);
        $fStmt->execute();
        $fRow = $fStmt->get_result()->fetch_assoc();
        $fStmt->close();
        $deanFacultyName = $fRow ? (string) $fRow['name'] : '';
    }
}

const ENROLL_SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

// ---------------------------------------------------------------------
// The course this page is scoped to — unrestricted lookup (same as
// admin/course_offerings.php), since a Dean may reach a course whose
// catalog home is a different faculty in order to enroll their OWN
// faculty's students into it (see the Multi-Faculty Course Offerings work).
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

    if ($action === 'bulk_enroll') {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['student_ids'] ?? [])),
            static fn ($id) => $id > 0
        )));

        if (empty($ids)) {
            $_SESSION['flash_error'] = 'No students were selected.';
        } else {
            // A Dean may only enroll students from their own faculty —
            // re-verified server-side here, never trusted from the filter
            // bar alone (which is only a UI convenience).
            $skippedOutOfScope = 0;
            if ($role === 'dean') {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $ownStmt = $conn->prepare("SELECT id FROM students WHERE id IN ({$placeholders}) AND faculty_id = ?");
                $ownStmt->bind_param(str_repeat('i', count($ids)) . 'i', ...array_merge($ids, [$deanFacultyId]));
                $ownStmt->execute();
                $ownIds = array_map(static fn ($r) => (int) $r['id'], $ownStmt->get_result()->fetch_all(MYSQLI_ASSOC));
                $ownStmt->close();
                $skippedOutOfScope = count($ids) - count($ownIds);
                $ids = $ownIds;
            }

            // INSERT IGNORE relies on the existing uq_student_course unique
            // key to silently skip anyone already enrolled — no separate
            // pre-check needed, and no risk of a duplicate row.
            $enrolledCount = 0;
            foreach ($ids as $sid) {
                $insStmt = $conn->prepare('INSERT IGNORE INTO course_enrollments (student_id, course_id) VALUES (?, ?)');
                $insStmt->bind_param('ii', $sid, $courseId);
                $insStmt->execute();
                if ($insStmt->affected_rows > 0) {
                    $enrolledCount++;
                }
                $insStmt->close();
            }

            $alreadyCount = count($ids) - $enrolledCount;
            $summary = $enrolledCount . ' student' . ($enrolledCount === 1 ? '' : 's') . ' enrolled.';
            if ($alreadyCount > 0) {
                $summary .= ' ' . $alreadyCount . ' already enrolled, skipped.';
            }
            if ($skippedOutOfScope > 0) {
                $summary .= ' ' . $skippedOutOfScope . ' outside your faculty, skipped.';
            }
            $_SESSION['flash_success'] = $summary;
        }
        redirect_to('admin/course_enrollments.php?course_id=' . $courseId);
    } elseif ($action === 'remove_enrollment') {
        $studentId = (int) ($_POST['student_id'] ?? 0);

        if ($role === 'dean') {
            $ownStmt = $conn->prepare('SELECT id FROM students WHERE id = ? AND faculty_id = ?');
            $ownStmt->bind_param('ii', $studentId, $deanFacultyId);
            $ownStmt->execute();
            $owned = $ownStmt->get_result()->fetch_assoc();
            $ownStmt->close();
            if (!$owned) {
                $_SESSION['flash_error'] = 'That student does not belong to your faculty.';
                redirect_to('admin/course_enrollments.php?course_id=' . $courseId);
            }
        }

        $delStmt = $conn->prepare('DELETE FROM course_enrollments WHERE student_id = ? AND course_id = ?');
        $delStmt->bind_param('ii', $studentId, $courseId);
        $delStmt->execute();
        $delStmt->close();

        $_SESSION['flash_success'] = 'Enrollment removed.';
        redirect_to('admin/course_enrollments.php?course_id=' . $courseId);
    }
}

// ---------------------------------------------------------------------
// Currently Enrolled — for a Dean, restricted to their own faculty's
// students only (real student data, unlike admin/course_offerings.php's
// schedule-metadata-only cross-faculty list — a Dean never sees another
// faculty's enrolled students here at all).
// ---------------------------------------------------------------------
if ($role === 'dean') {
    $enrolledStmt = $conn->prepare(
        'SELECT s.id, s.student_no, s.full_name, s.shift, s.semester_id AS student_semester_id,
                f.name AS faculty_name, d.name AS department_name,
                ay.label AS academic_year_label, sem.name AS semester_name
         FROM course_enrollments ce
         JOIN students s ON s.id = ce.student_id
         JOIN faculties f ON f.id = s.faculty_id
         JOIN departments d ON d.id = s.department_id
         JOIN academic_years ay ON ay.id = s.academic_year_id
         LEFT JOIN semesters sem ON sem.id = s.semester_id
         WHERE ce.course_id = ? AND s.faculty_id = ?
         ORDER BY s.full_name'
    );
    $enrolledStmt->bind_param('ii', $courseId, $deanFacultyId);
} else {
    $enrolledStmt = $conn->prepare(
        'SELECT s.id, s.student_no, s.full_name, s.shift, s.semester_id AS student_semester_id,
                f.name AS faculty_name, d.name AS department_name,
                ay.label AS academic_year_label, sem.name AS semester_name
         FROM course_enrollments ce
         JOIN students s ON s.id = ce.student_id
         JOIN faculties f ON f.id = s.faculty_id
         JOIN departments d ON d.id = s.department_id
         JOIN academic_years ay ON ay.id = s.academic_year_id
         LEFT JOIN semesters sem ON sem.id = s.semester_id
         WHERE ce.course_id = ?
         ORDER BY s.full_name'
    );
    $enrolledStmt->bind_param('i', $courseId);
}
$enrolledStmt->execute();
$enrolledStudents = $enrolledStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$enrolledStmt->close();

// ---------------------------------------------------------------------
// Which semester(s) this course is actively offered in right now (any
// faculty, any shift) — lets the "Currently Enrolled" list show, per
// student, whether their OWN current semester is one this course is
// actually being taught in (vs. an enrollment left over from a semester
// they've since moved past).
// ---------------------------------------------------------------------
$courseCurrentSemesterIds = [];
$curSemStmt = $conn->prepare(
    "SELECT DISTINCT co.semester_id
     FROM course_offerings co
     JOIN semesters se ON se.id = co.semester_id
     WHERE co.course_id = ? AND se.status = 'current'"
);
$curSemStmt->bind_param('i', $courseId);
$curSemStmt->execute();
foreach ($curSemStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $courseCurrentSemesterIds[] = (int) $row['semester_id'];
}
$curSemStmt->close();

// ---------------------------------------------------------------------
// Student filter bar — same shape/query-building pattern as
// admin/students.php, reused verbatim (Academic Year, Faculty, Department
// cascade, Semester cascade, Shift, search). A Dean's Faculty filter is
// always forced to their own — never trusted from the querystring.
// ---------------------------------------------------------------------
$filterAcademicYearId = (int) ($_GET['academic_year_id'] ?? 0);
$filterFacultyId = $role === 'dean' ? $deanFacultyId : (int) ($_GET['faculty_id'] ?? 0);
$filterDepartmentId = (int) ($_GET['department_id'] ?? 0);
$filterSemesterId = (int) ($_GET['semester_id'] ?? 0);
$filterShift = (string) ($_GET['shift'] ?? '');
if (!array_key_exists($filterShift, ENROLL_SHIFT_LABELS)) {
    $filterShift = '';
}
$filterSearch = trim((string) ($_GET['search'] ?? ''));

$academicYears = $conn->query('SELECT id, label, is_current FROM academic_years ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);

$faculties = $role === 'dean'
    ? array_filter($conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC), static fn ($f) => (int) $f['id'] === $deanFacultyId)
    : $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$departments = $role === 'dean'
    ? array_values(array_filter($conn->query(
        "SELECT d.id, d.name, d.faculty_id, f.name AS faculty_name
         FROM departments d
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name"
    )->fetch_all(MYSQLI_ASSOC), static fn ($d) => (int) $d['faculty_id'] === $deanFacultyId))
    : $conn->query(
        "SELECT d.id, d.name, d.faculty_id, f.name AS faculty_name
         FROM departments d
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name"
    )->fetch_all(MYSQLI_ASSOC);

$departmentsByFacultyId = [];
foreach ($departments as $dept) {
    $departmentsByFacultyId[(int) $dept['faculty_id']][] = ['id' => (int) $dept['id'], 'name' => $dept['name']];
}

if ($role === 'dean') {
    $semStmt = $conn->prepare(
        'SELECT se.id, se.name, se.faculty_id, se.status, ay.label AS academic_year_label
         FROM semesters se JOIN academic_years ay ON ay.id = se.academic_year_id
         WHERE se.faculty_id = ? ORDER BY se.start_date DESC'
    );
    $semStmt->bind_param('i', $deanFacultyId);
    $semStmt->execute();
    $semesters = $semStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $semStmt->close();
} else {
    $semesters = $conn->query(
        'SELECT se.id, se.name, se.faculty_id, se.status, ay.label AS academic_year_label
         FROM semesters se JOIN academic_years ay ON ay.id = se.academic_year_id
         ORDER BY se.start_date DESC'
    )->fetch_all(MYSQLI_ASSOC);
}

$semestersByFacultyId = [];
foreach ($semesters as $sem) {
    $semestersByFacultyId[(int) $sem['faculty_id']][] = [
        'id' => (int) $sem['id'],
        'name' => $sem['name'],
        'academic_year_label' => $sem['academic_year_label'],
        'status' => $sem['status'],
    ];
}

$conditions = [];
$params = [$courseId];
$types = 'i';

if ($filterAcademicYearId > 0) {
    $conditions[] = 's.academic_year_id = ?';
    $params[] = $filterAcademicYearId;
    $types .= 'i';
}
if ($filterFacultyId > 0) {
    $conditions[] = 's.faculty_id = ?';
    $params[] = $filterFacultyId;
    $types .= 'i';
}
if ($filterDepartmentId > 0) {
    $conditions[] = 's.department_id = ?';
    $params[] = $filterDepartmentId;
    $types .= 'i';
}
if ($filterSemesterId > 0) {
    $conditions[] = 's.semester_id = ?';
    $params[] = $filterSemesterId;
    $types .= 'i';
}
if ($filterShift !== '') {
    $conditions[] = 's.shift = ?';
    $params[] = $filterShift;
    $types .= 's';
}
if ($filterSearch !== '') {
    $conditions[] = '(s.full_name LIKE ? OR s.first_name LIKE ? OR s.father_name LIKE ? OR s.grandfather_name LIKE ? OR s.student_no LIKE ?)';
    $likeParam = '%' . $filterSearch . '%';
    $params[] = $likeParam;
    $params[] = $likeParam;
    $params[] = $likeParam;
    $params[] = $likeParam;
    $params[] = $likeParam;
    $types .= 'sssss';
}

$whereSql = empty($conditions) ? '' : ('AND ' . implode(' AND ', $conditions));

$studentsSql = "SELECT s.id, s.student_no, s.full_name, s.shift,
                       ay.label AS academic_year_label, f.name AS faculty_name, d.name AS department_name,
                       sem.name AS semester_name, u.status AS user_status,
                       ce.id AS enrollment_id
                FROM students s
                JOIN academic_years ay ON ay.id = s.academic_year_id
                JOIN faculties f ON f.id = s.faculty_id
                JOIN departments d ON d.id = s.department_id
                JOIN users u ON u.id = s.user_id
                LEFT JOIN semesters sem ON sem.id = s.semester_id
                LEFT JOIN course_enrollments ce ON ce.student_id = s.id AND ce.course_id = ?
                WHERE 1 = 1 {$whereSql}
                ORDER BY f.name, d.name, s.full_name";

$studentsStmt = $conn->prepare($studentsSql);
$studentsStmt->bind_param($types, ...$params);
$studentsStmt->execute();
$candidateStudents = $studentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$studentsStmt->close();

$hasAnyFilter = $filterAcademicYearId > 0 || $filterFacultyId > 0 || $filterDepartmentId > 0
    || $filterSemesterId > 0 || $filterShift !== '' || $filterSearch !== '';

/**
 * Renders the candidate-students <tbody> rows — shared by the normal
 * full-page render below and the AJAX partial branch right after this
 * function, so this page's own AJAX live-filtering (see the inline script
 * at the bottom of this file, fetching this same page with an
 * X-Requested-With header) can never drift from what a full page load
 * would show.
 */
function render_enroll_candidate_rows(array $candidateStudents, bool $hasAnyFilter): void
{
    if (empty($candidateStudents)) {
        ?>
        <tr>
            <td colspan="9" class="text-center text-muted py-4">
                <?= $hasAnyFilter ? 'No students match the current filters.' : 'Use the filters above to find students.' ?>
            </td>
        </tr>
        <?php
        return;
    }

    foreach ($candidateStudents as $s) {
        $alreadyEnrolled = $s['enrollment_id'] !== null;
        ?>
        <tr>
            <td>
                <?php if ($alreadyEnrolled): ?>
                    <input type="checkbox" disabled title="Already enrolled">
                <?php else: ?>
                    <input type="checkbox" class="row-check-candidate" value="<?= (int) $s['id'] ?>"
                           data-label="<?= htmlspecialchars($s['full_name'] . ' (' . $s['student_no'] . ')') ?>">
                <?php endif; ?>
            </td>
            <td><span class="badge-pill badge-active"><?= htmlspecialchars($s['student_no']) ?></span></td>
            <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($s['full_name']) ?></td>
            <td><?= htmlspecialchars($s['academic_year_label']) ?></td>
            <td><?= htmlspecialchars($s['faculty_name']) ?></td>
            <td><?= htmlspecialchars($s['department_name']) ?></td>
            <td>
                <?php if ($s['semester_name']): ?>
                    <?= htmlspecialchars($s['semester_name']) ?>
                <?php else: ?>
                    <span class="text-muted fst-italic">Not set</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(ENROLL_SHIFT_LABELS[$s['shift']] ?? $s['shift']) ?></td>
            <td>
                <?php if ($alreadyEnrolled): ?>
                    <span class="badge-pill badge-active">Already Enrolled</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

// A live-filter fetch() (the inline script at the bottom of this file)
// sends this header to ask for just the candidate-rows HTML instead of the
// whole page — every query/scoping rule above this point (dean's
// own-faculty restriction included) already ran identically either way, so
// a partial request is exactly as safe as a full page load, just cheaper
// to render and swap in without ever navigating the browser at all (so the
// page truly cannot move, not even a full-reload flash).
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    render_enroll_candidate_rows($candidateStudents, $hasAnyFilter);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enroll Students — ADMAS Attendance System</title>
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
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="text-decoration-none">&larr; Back to Courses</a>
                &nbsp;&middot;&nbsp;
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $courseId ?>" class="text-decoration-none">Manage Offerings</a>
                <?php if ($role === 'dean'): ?>
                    &nbsp;&middot;&nbsp;You may only enroll/view students from <?= htmlspecialchars($deanFacultyName) ?> Faculty
                <?php endif; ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">
                        Enroll Students — <?= htmlspecialchars($course['code'] . ' — ' . $course['name']) ?>
                    </h4>
                    <?= render_scope_breadcrumb([
                        $course['code'],
                        $course['department_name'],
                        $course['faculty_name'],
                    ]) ?>
                    <p class="text-muted mb-0">
                        Who takes this course, at all — not tied to one semester. Whether an enrolled student actually
                        sees it under a specific semester still depends on that semester having a real offering or
                        attendance record. Tip: to find a cohort from a <em>past</em> semester (students have since
                        moved on), filter by Faculty + Department + Academic Year (+ Shift) and leave Semester blank —
                        a student's Semester filter only matches their <em>current</em> position, not their history.
                    </p>
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

            <div class="admas-card p-4 mb-3">
                <h6 class="small text-uppercase text-muted mb-2">Currently Enrolled (<?= count($enrolledStudents) ?>)</h6>
                <?php if (!empty($enrolledStudents) && !empty($courseCurrentSemesterIds)): ?>
                    <p class="small text-muted mb-2">
                        <span class="badge-pill badge-present">Current</span> = this student's own current semester matches a semester this course is actively offered in right now.
                        <span class="badge-pill badge-neutral">Other</span> = enrolled, but their current semester differs (likely a past or future cohort).
                    </p>
                <?php endif; ?>
                <?php if (empty($enrolledStudents)): ?>
                    <p class="text-muted mb-0">No students enrolled yet — use the filter below to find and enroll some.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table admas-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Student No</th>
                                    <th>Full Name</th>
                                    <th>Faculty</th>
                                    <th>Department</th>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Shift</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enrolledStudents as $es): ?>
                                    <?php
                                    $studentSemesterId = $es['student_semester_id'] !== null ? (int) $es['student_semester_id'] : null;
                                    $isCurrentForCourse = $studentSemesterId !== null && in_array($studentSemesterId, $courseCurrentSemesterIds, true);
                                    ?>
                                    <tr>
                                        <td><span class="badge-pill badge-active"><?= htmlspecialchars($es['student_no']) ?></span></td>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($es['full_name']) ?></td>
                                        <td><?= htmlspecialchars($es['faculty_name']) ?></td>
                                        <td><?= htmlspecialchars($es['department_name']) ?></td>
                                        <td><?= htmlspecialchars($es['academic_year_label']) ?></td>
                                        <td>
                                            <?php if ($es['semester_name']): ?>
                                                <span class="badge-pill <?= $isCurrentForCourse ? 'badge-present' : 'badge-neutral' ?>">
                                                    <?= htmlspecialchars($es['semester_name']) ?><?= $isCurrentForCourse ? ' — Current' : '' ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars(ENROLL_SHIFT_LABELS[$es['shift']] ?? $es['shift']) ?></td>
                                        <td class="text-end">
                                            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/course_enrollments.php?course_id=<?= (int) $courseId ?>" style="display:inline;"
                                                  onsubmit="return confirm('Remove this student\'s enrollment?');">
                                                <input type="hidden" name="action" value="remove_enrollment">
                                                <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
                                                <input type="hidden" name="student_id" value="<?= (int) $es['id'] ?>">
                                                <button type="submit" class="btn-icon text-danger" title="Remove">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="admas-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Find Students to Enroll</h6>
                    <button type="button" id="bulkEnrollBtn" class="btn btn-primary btn-sm d-none" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">Enroll Selected</button>
                </div>

                <form id="bulkEnrollForm" method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/course_enrollments.php?course_id=<?= (int) $courseId ?>" class="d-none">
                    <input type="hidden" name="action" value="bulk_enroll">
                    <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
                    <div id="bulkEnrollIds"></div>
                </form>

                <!-- Filter bar: real SQL WHERE filters via GET -->
                <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/admin/course_enrollments.php" class="row g-2 mb-3" id="enrollFilterForm">
                    <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
                    <div class="col-sm-6 col-md-2">
                        <select class="form-select form-select-sm" name="academic_year_id">
                            <option value="0">All Academic Years</option>
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?= (int) $ay['id'] ?>" <?= $filterAcademicYearId === (int) $ay['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ay['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <?php if ($role === 'dean'): ?>
                            <select class="form-select form-select-sm" id="filterFacultySelect" disabled onchange="updateFilterDepartmentOptions(this.value, 0); updateFilterSemesterOptions(this.value, 0);">
                                <option value="<?= (int) $deanFacultyId ?>" selected><?= htmlspecialchars($deanFacultyName) ?></option>
                            </select>
                        <?php else: ?>
                            <select class="form-select form-select-sm" name="faculty_id" id="filterFacultySelect" onchange="updateFilterDepartmentOptions(this.value, 0); updateFilterSemesterOptions(this.value, 0);">
                                <option value="0">All Faculties</option>
                                <?php foreach ($faculties as $f): ?>
                                    <option value="<?= (int) $f['id'] ?>" <?= $filterFacultyId === (int) $f['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($f['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <select class="form-select form-select-sm" name="department_id" id="filterDepartmentSelect">
                            <option value="0">All Departments</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <select class="form-select form-select-sm" name="semester_id" id="filterSemesterSelect">
                            <option value="0">All Semesters</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <select class="form-select form-select-sm" name="shift">
                            <option value="">All Shifts</option>
                            <?php foreach (ENROLL_SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                <option value="<?= htmlspecialchars($shiftValue) ?>" <?= $filterShift === $shiftValue ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($shiftLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" name="search" placeholder="Search name or student no" data-live-search
                                   value="<?= htmlspecialchars($filterSearch) ?>">
                            <button type="submit" class="btn text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"><i class="bi bi-search"></i></button>
                        </div>
                    </div>
                    <?php if ($hasAnyFilter): ?>
                        <div class="col-12">
                            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_enrollments.php?course_id=<?= (int) $courseId ?>" class="small">Clear filters</a>
                        </div>
                    <?php endif; ?>
                </form>

                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllCandidates"></th>
                                <th>Student No</th>
                                <th>Full Name</th>
                                <th>Academic Year</th>
                                <th>Faculty</th>
                                <th>Department</th>
                                <th>Semester</th>
                                <th>Shift</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="candidateStudentsBody">
                            <?php render_enroll_candidate_rows($candidateStudents, $hasAnyFilter); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/bulk_enroll.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/semester_label.js"></script>
    <script>
        const departmentsByFacultyId = <?= json_encode($departmentsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const semestersByFacultyId = <?= json_encode($semestersByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function updateFilterDepartmentOptions(facultyId, selectedDepartmentId) {
            const select = document.getElementById('filterDepartmentSelect');
            const departments = departmentsByFacultyId[facultyId] || [];
            select.innerHTML = '';

            const allOption = document.createElement('option');
            allOption.value = '0';
            allOption.textContent = 'All Departments';
            select.appendChild(allOption);

            departments.forEach((dept) => {
                const opt = document.createElement('option');
                opt.value = String(dept.id);
                opt.textContent = dept.name;
                select.appendChild(opt);
            });

            select.value = String(selectedDepartmentId || 0);
            if (select.value !== String(selectedDepartmentId || 0)) {
                select.value = '0';
            }
        }

        function updateFilterSemesterOptions(facultyId, selectedSemesterId) {
            const select = document.getElementById('filterSemesterSelect');
            const semesters = semestersByFacultyId[facultyId] || [];
            select.innerHTML = '';

            const allOption = document.createElement('option');
            allOption.value = '0';
            allOption.textContent = 'All Semesters';
            select.appendChild(allOption);

            semesters.forEach((sem) => {
                const opt = document.createElement('option');
                opt.value = String(sem.id);
                opt.textContent = admasSemesterLabel(sem);
                select.appendChild(opt);
            });

            select.value = String(selectedSemesterId || 0);
            if (select.value !== String(selectedSemesterId || 0)) {
                select.value = '0';
            }
        }

        // Live-filtering here never navigates the browser at all — it
        // fetches this same page's own filtered rows in the background and
        // swaps just the <tbody>, so the page genuinely cannot move/flash/
        // reload while filtering, not even briefly.
        function admasWireCandidateRowCheckboxes() {
            admasInitBulkEnroll({
                checkboxSelector: '.row-check-candidate',
                selectAllSelector: '#selectAllCandidates',
                buttonSelector: '#bulkEnrollBtn',
                formSelector: '#bulkEnrollForm',
                hiddenContainerSelector: '#bulkEnrollIds',
                hiddenInputName: 'student_ids[]',
            });
        }

        function admasFetchCandidates() {
            const form = document.getElementById('enrollFilterForm');
            const tbody = document.getElementById('candidateStudentsBody');
            const query = new URLSearchParams(new FormData(form)).toString();
            const url = form.action + '?' + query;

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Request failed');
                    }
                    return response.text();
                })
                .then((html) => {
                    tbody.innerHTML = html;
                    history.replaceState(null, '', url);
                    admasWireCandidateRowCheckboxes();
                })
                .catch(() => {
                    // Network/server error — fall back to a real page load
                    // so the filter still works even if the fetch failed.
                    form.submit();
                });
        }

        window.addEventListener('DOMContentLoaded', () => {
            const filterFacultyId = document.getElementById('filterFacultySelect').value;
            updateFilterDepartmentOptions(filterFacultyId, <?= (int) $filterDepartmentId ?>);
            updateFilterSemesterOptions(filterFacultyId, <?= (int) $filterSemesterId ?>);

            const enrollFilterForm = document.getElementById('enrollFilterForm');
            enrollFilterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                admasFetchCandidates();
            });
            enrollFilterForm.querySelectorAll('select').forEach((select) => {
                select.addEventListener('change', admasFetchCandidates);
            });
            let debounceTimer = null;
            enrollFilterForm.querySelectorAll('[data-live-search]').forEach((input) => {
                input.addEventListener('input', () => {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(admasFetchCandidates, 500);
                });
            });

            admasWireCandidateRowCheckboxes();
        });
    </script>
</body>
</html>
