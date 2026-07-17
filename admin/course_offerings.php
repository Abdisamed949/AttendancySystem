<?php
/**
 * Manage Offerings — per-course, per-semester lecturer assignment.
 * Reachable only via the "Manage Offerings" link on a course row in
 * admin/courses.php (?course_id=X) — deliberately no standalone sidebar
 * item, since this page is meaningless without a course already picked.
 * System Administrator (any course) and Dean (own faculty only, per
 * CLAUDE.md §4), same access pattern as admin/courses.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['system_admin', 'dean']);

$conn = db();
$role = current_role();

$deanFacultyId = 0;
if ($role === 'dean') {
    $deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
}

// ---------------------------------------------------------------------
// The course this page is scoped to (never a standalone browse — a
// missing or out-of-scope course_id bounces back to admin/courses.php).
// ---------------------------------------------------------------------
$courseId = (int) ($_POST['course_id'] ?? $_GET['course_id'] ?? 0);

if ($role === 'dean') {
    $courseStmt = $conn->prepare(
        'SELECT c.id, c.code, c.name, c.department_id, d.faculty_id, d.name AS department_name, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         WHERE c.id = ? AND d.faculty_id = ?'
    );
    $courseStmt->bind_param('ii', $courseId, $deanFacultyId);
} else {
    $courseStmt = $conn->prepare(
        'SELECT c.id, c.code, c.name, c.department_id, d.faculty_id, d.name AS department_name, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         WHERE c.id = ?'
    );
    $courseStmt->bind_param('i', $courseId);
}
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

    if ($action === 'save_offering') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $lecturerId = (int) ($_POST['lecturer_id'] ?? 0);
        $startDateRaw = trim((string) ($_POST['start_date'] ?? ''));
        $endDateRaw = trim((string) ($_POST['end_date'] ?? ''));

        // Semester must belong to THIS course's own faculty (D1: a course
        // is only ever offered under its own faculty's semester track).
        $semStmt = $conn->prepare('SELECT id, name, start_date, end_date FROM semesters WHERE id = ? AND faculty_id = ?');
        $semStmt->bind_param('ii', $semesterId, $courseFacultyId);
        $semStmt->execute();
        $semRow = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        $startDate = null;
        $endDate = null;

        $validationError = '';
        if (!$semRow) {
            $validationError = 'Please select a valid semester for this course\'s faculty.';
        } elseif ($lecturerId > 0) {
            // Lecturer must belong to this course's own department, same
            // validation admin/courses.php used to do for the old
            // permanent lecturer_id field.
            $lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE id = ? AND department_id = ?');
            $lecStmt->bind_param('ii', $lecturerId, $courseDepartmentId);
            $lecStmt->execute();
            if (!$lecStmt->get_result()->fetch_assoc()) {
                $validationError = 'Selected lecturer does not belong to this course\'s department.';
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
            // One offering per (course, semester) — upsert, since re-saving
            // an existing semester's row should update the lecturer/dates,
            // not error as a duplicate.
            $upsertStmt = $conn->prepare(
                'INSERT INTO course_offerings (course_id, semester_id, lecturer_id, start_date, end_date) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE lecturer_id = VALUES(lecturer_id), start_date = VALUES(start_date), end_date = VALUES(end_date)'
            );
            $upsertStmt->bind_param('iiiss', $courseId, $semesterId, $lecturerParam, $startDate, $endDate);
            $upsertStmt->execute();
            $upsertStmt->close();

            $_SESSION['flash_success'] = 'Offering saved for "' . $semRow['name'] . '".' . $rangeWarning;
            redirect_to('admin/course_offerings.php?course_id=' . $courseId);
        }

        $errorMessage = $validationError;
    } elseif ($action === 'delete_offering') {
        $offeringId = (int) ($_POST['offering_id'] ?? 0);

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
$semestersStmt = $conn->prepare(
    'SELECT s.id, s.name, s.is_current, ay.label AS academic_year_label
     FROM semesters s
     JOIN academic_years ay ON ay.id = s.academic_year_id
     WHERE s.faculty_id = ?
     ORDER BY s.start_date DESC'
);
$semestersStmt->bind_param('i', $courseFacultyId);
$semestersStmt->execute();
$semesters = $semestersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$semestersStmt->close();

$lecturersStmt = $conn->prepare("SELECT id, full_name FROM lecturers WHERE department_id = ? AND status = 'active' ORDER BY full_name");
$lecturersStmt->bind_param('i', $courseDepartmentId);
$lecturersStmt->execute();
$lecturers = $lecturersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lecturersStmt->close();

$offeringsStmt = $conn->prepare(
    'SELECT co.id, co.semester_id, co.lecturer_id, co.start_date, co.end_date,
            s.name AS semester_name, s.is_current,
            ay.label AS academic_year_label, l.full_name AS lecturer_name
     FROM course_offerings co
     JOIN semesters s ON s.id = co.semester_id
     JOIN academic_years ay ON ay.id = s.academic_year_id
     LEFT JOIN lecturers l ON l.id = co.lecturer_id
     WHERE co.course_id = ?
     ORDER BY s.start_date DESC'
);
$offeringsStmt->bind_param('i', $courseId);
$offeringsStmt->execute();
$offerings = $offeringsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$offeringsStmt->close();

$offeredSemesterIds = array_map(static fn ($o) => (int) $o['semester_id'], $offerings);
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
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="text-decoration-none">&larr; Back to Courses</a>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">
                        Manage Offerings — <?= htmlspecialchars($course['code'] . ' — ' . $course['name']) ?>
                    </h4>
                    <?= render_scope_breadcrumb([
                        $course['code'],
                        $course['department_name'],
                        $course['faculty_name'],
                    ]) ?>
                    <p class="text-muted mb-0">Which lecturer teaches this course, per semester — Academic Year in the table below is derived from each row's own Semester.</p>
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
                <div class="col-lg-7">
                    <div class="admas-card p-4">
                        <h6 class="small text-uppercase text-muted mb-2">Offerings</h6>
                        <div class="table-responsive">
                            <table class="table admas-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Semester</th>
                                        <th>Academic Year</th>
                                        <th>Lecturer</th>
                                        <th>Teaching Period</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($offerings)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3">No offerings yet — add one on the right.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($offerings as $o): ?>
                                            <tr>
                                                <td class="fw-semibold" style="color: #0b1f3a;">
                                                    <?= htmlspecialchars($o['semester_name']) ?>
                                                    <?php if ((int) $o['is_current'] === 1): ?>
                                                        <span class="badge-pill badge-active">Current</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($o['academic_year_label']) ?></td>
                                                <td>
                                                    <?php if ($o['lecturer_name']): ?>
                                                        <?= htmlspecialchars($o['lecturer_name']) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic">Unassigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($o['start_date'] || $o['end_date']): ?>
                                                        <span class="small"><?= htmlspecialchars(($o['start_date'] ?? '?') . ' to ' . ($o['end_date'] ?? '?')) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic small">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $courseId ?>" style="display:inline;"
                                                          onsubmit="return confirm('Remove this offering?');">
                                                        <input type="hidden" name="action" value="delete_offering">
                                                        <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
                                                        <input type="hidden" name="offering_id" value="<?= (int) $o['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="admas-card p-4">
                        <h6 class="small text-uppercase text-muted mb-2">Add / Update Offering</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $courseId ?>" class="d-flex flex-column gap-2">
                            <input type="hidden" name="action" value="save_offering">
                            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">

                            <div>
                                <label class="form-label small mb-1">Semester</label>
                                <select class="form-select form-select-sm" name="semester_id" id="offeringSemesterSelect" required onchange="admasUpdateAcademicYearDisplay()">
                                    <option value="">Select semester</option>
                                    <?php foreach ($semesters as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>" data-academic-year="<?= htmlspecialchars($s['academic_year_label']) ?>">
                                            <?= htmlspecialchars($s['name']) ?><?= in_array((int) $s['id'], $offeredSemesterIds, true) ? ' (already offered — editing lecturer)' : '' ?><?= (int) $s['is_current'] === 1 ? ' — Current' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($semesters)): ?>
                                    <div class="form-text text-danger">
                                        <?= htmlspecialchars($course['faculty_name']) ?> has no semesters yet — create one on the
                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">Semesters</a> page first.
                                    </div>
                                <?php endif; ?>
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
                                        <option value="<?= (int) $l['id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Only lecturers in this course's own department are shown.</div>
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small mb-1">Start Date</label>
                                    <input type="date" class="form-control form-control-sm" name="start_date">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">End Date</label>
                                    <input type="date" class="form-control form-control-sm" name="end_date">
                                </div>
                            </div>
                            <div class="form-text">Optional — this course's actual teaching period within the selected semester.</div>

                            <button type="submit" class="btn btn-primary text-nowrap mt-2" style="background-color: #0ea5e9; border-color: #0ea5e9;" <?= empty($semesters) ? 'disabled' : '' ?>>
                                <i class="bi bi-save"></i> Save Offering
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function admasUpdateAcademicYearDisplay() {
            const select = document.getElementById('offeringSemesterSelect');
            const display = document.getElementById('offeringAcademicYearDisplay');
            const selected = select.options[select.selectedIndex];
            display.value = selected ? (selected.getAttribute('data-academic-year') || '') : '';
        }
        window.addEventListener('DOMContentLoaded', admasUpdateAcademicYearDisplay);
    </script>
</body>
</html>
