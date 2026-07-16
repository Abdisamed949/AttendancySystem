<?php
/**
 * Course Management — System Administrator (all faculties) and Dean (own
 * faculty only, per CLAUDE.md §4). Dean's faculty_id is always read from
 * $_SESSION, never trusted from request input (same pattern used across
 * attendance.php/reports.php/admin/departments.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';

require_role(['system_admin', 'dean']);

$conn = db();
$currentUser = current_user();
$role = current_role();

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
// Add / Edit side-panel form state
// ---------------------------------------------------------------------
$formMode = 'create';
$formValues = ['id' => 0, 'code' => '', 'name' => '', 'department_id' => 0, 'credit_hours' => 3];

/**
 * Shared by both the single-row "Delete" button and the bulk "Delete
 * Selected" action so the two can never drift on blocker/validation logic.
 * Returns $courseId === 0 semantics from the original inline code as
 * ok=false with a "not found" message (covers both "doesn't exist" and
 * "a Dean tried to delete a course outside their faculty").
 */
function delete_course_row(mysqli $conn, int $courseId, string $role, int $deanFacultyId): array
{
    if ($role === 'dean') {
        $ownCheckStmt = $conn->prepare(
            'SELECT c.id, c.name FROM courses c JOIN departments d ON d.id = c.department_id WHERE c.id = ? AND d.faculty_id = ?'
        );
        $ownCheckStmt->bind_param('ii', $courseId, $deanFacultyId);
        $ownCheckStmt->execute();
        $courseRow = $ownCheckStmt->get_result()->fetch_assoc();
        $ownCheckStmt->close();
    } else {
        $courseStmt = $conn->prepare('SELECT id, name FROM courses WHERE id = ?');
        $courseStmt->bind_param('i', $courseId);
        $courseStmt->execute();
        $courseRow = $courseStmt->get_result()->fetch_assoc();
        $courseStmt->close();
    }

    if (!$courseRow) {
        return ['ok' => false, 'message' => 'Course not found.'];
    }

    $label = (string) $courseRow['name'];

    $blockers = [];
    foreach (['attendance' => 'attendance record', 'course_enrollments' => 'student enrollment'] as $table => $blockerLabel) {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE course_id = ?");
        $countStmt->bind_param('i', $courseId);
        $countStmt->execute();
        $count = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $countStmt->close();
        if ($count > 0) {
            $blockers[] = $count . ' ' . $blockerLabel . ($count === 1 ? '' : 's');
        }
    }

    if (!empty($blockers)) {
        return ['ok' => false, 'message' => $label . ': still has ' . implode(', ', $blockers) . '.'];
    }

    $deleteStmt = $conn->prepare('DELETE FROM courses WHERE id = ?');
    $deleteStmt->bind_param('i', $courseId);
    $deleteStmt->execute();
    $deleteStmt->close();

    return ['ok' => true, 'message' => $label . ' deleted.'];
}

// ---------------------------------------------------------------------
// Handle POST actions: create, update, delete, bulk_delete
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $courseId = $action === 'update' ? (int) ($_POST['course_id'] ?? 0) : 0;
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $creditHours = (int) ($_POST['credit_hours'] ?? 3);

        $formMode = $action === 'update' ? 'edit' : 'create';
        $formValues = [
            'id' => $courseId,
            'code' => $code,
            'name' => $name,
            'department_id' => $departmentId,
            'credit_hours' => $creditHours,
        ];

        $validationError = '';
        if ($name === '') {
            $validationError = 'Course name is required.';
        } elseif ($code === '') {
            $validationError = 'Course code is required.';
        } elseif ($departmentId <= 0) {
            $validationError = 'Please select a department.';
        } elseif ($creditHours < 1 || $creditHours > 10) {
            $validationError = 'Credit hours must be between 1 and 10.';
        } elseif ($action === 'update' && $courseId <= 0) {
            $validationError = 'Invalid course selected for editing.';
        }

        if ($validationError === '') {
            if ($role === 'dean') {
                $deptCheckStmt = $conn->prepare('SELECT id FROM departments WHERE id = ? AND faculty_id = ?');
                $deptCheckStmt->bind_param('ii', $departmentId, $deanFacultyId);
            } else {
                $deptCheckStmt = $conn->prepare('SELECT id FROM departments WHERE id = ?');
                $deptCheckStmt->bind_param('i', $departmentId);
            }
            $deptCheckStmt->execute();
            if (!$deptCheckStmt->get_result()->fetch_assoc()) {
                $validationError = $role === 'dean'
                    ? 'Selected department does not belong to your faculty.'
                    : 'Selected department does not exist.';
            }
            $deptCheckStmt->close();
        }

        // A Dean editing an existing course must currently own it (i.e. its
        // current department is inside their faculty) — blocks a crafted
        // course_id belonging to another faculty from being "adopted" via
        // this form even though the posted department_id itself is in-scope.
        if ($validationError === '' && $role === 'dean' && $action === 'update') {
            $ownCheckStmt = $conn->prepare(
                'SELECT c.id FROM courses c JOIN departments d ON d.id = c.department_id WHERE c.id = ? AND d.faculty_id = ?'
            );
            $ownCheckStmt->bind_param('ii', $courseId, $deanFacultyId);
            $ownCheckStmt->execute();
            if (!$ownCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'Invalid course selected for editing.';
            }
            $ownCheckStmt->close();
        }

        if ($validationError === '') {
            // Code only needs to be unique within the same department (schema: uq_course_code_per_department).
            $dupStmt = $conn->prepare('SELECT id FROM courses WHERE department_id = ? AND UPPER(code) = ? AND id != ?');
            $dupStmt->bind_param('isi', $departmentId, $code, $courseId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $validationError = 'This course code is already used within the selected department.';
            }
            $dupStmt->close();
        }

        if ($validationError === '') {
            if ($action === 'create') {
                $insertStmt = $conn->prepare('INSERT INTO courses (code, name, department_id, credit_hours) VALUES (?, ?, ?, ?)');
                $insertStmt->bind_param('ssii', $code, $name, $departmentId, $creditHours);
                $insertStmt->execute();
                $insertStmt->close();
                $_SESSION['flash_success'] = 'Course added successfully. Use "Manage Offerings" to assign a lecturer for a semester.';
                redirect_to('admin/courses.php');
            } else {
                $updateStmt = $conn->prepare('UPDATE courses SET code = ?, name = ?, department_id = ?, credit_hours = ? WHERE id = ?');
                $updateStmt->bind_param('ssiii', $code, $name, $departmentId, $creditHours, $courseId);
                $updateStmt->execute();
                $updateStmt->close();
                $_SESSION['flash_success'] = 'Course updated successfully.';
                redirect_to('admin/courses.php');
            }
        }

        $errorMessage = $validationError;
    } elseif ($action === 'delete') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $result = delete_course_row($conn, $courseId, $role, $deanFacultyId);
        if ($result['ok']) {
            $_SESSION['flash_success'] = 'Course deleted successfully.';
            redirect_to('admin/courses.php');
        } else {
            $errorMessage = $result['message'];
        }
    } elseif ($action === 'bulk_delete') {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['course_ids'] ?? [])),
            static fn ($id) => $id > 0
        )));

        if (empty($ids)) {
            $_SESSION['flash_error'] = 'No courses were selected.';
        } else {
            $deletedCount = 0;
            $skippedMessages = [];
            foreach ($ids as $cid) {
                $result = delete_course_row($conn, $cid, $role, $deanFacultyId);
                if ($result['ok']) {
                    $deletedCount++;
                } else {
                    $skippedMessages[] = $result['message'];
                }
            }

            $summary = $deletedCount . ' of ' . count($ids) . ' selected course' . (count($ids) === 1 ? '' : 's') . ' deleted.';
            if (!empty($skippedMessages)) {
                $summary .= ' Skipped: ' . implode(' | ', $skippedMessages);
            }
            if ($deletedCount > 0) {
                $_SESSION['flash_success'] = $summary;
            } else {
                $_SESSION['flash_error'] = $summary;
            }
        }
        redirect_to('admin/courses.php');
    }
}

// ---------------------------------------------------------------------
// GET ?edit=ID switches the side panel into edit mode (skipped if a
// failed POST above already put the form into edit mode).
// ---------------------------------------------------------------------
if ($formMode === 'create' && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    if ($role === 'dean') {
        $editStmt = $conn->prepare(
            'SELECT c.id, c.code, c.name, c.department_id, c.credit_hours
             FROM courses c JOIN departments d ON d.id = c.department_id
             WHERE c.id = ? AND d.faculty_id = ?'
        );
        $editStmt->bind_param('ii', $editId, $deanFacultyId);
    } else {
        $editStmt = $conn->prepare('SELECT id, code, name, department_id, credit_hours FROM courses WHERE id = ?');
        $editStmt->bind_param('i', $editId);
    }
    $editStmt->execute();
    $editRow = $editStmt->get_result()->fetch_assoc();
    $editStmt->close();

    if ($editRow) {
        $formMode = 'edit';
        $formValues = [
            'id' => (int) $editRow['id'],
            'code' => (string) $editRow['code'],
            'name' => (string) $editRow['name'],
            'department_id' => (int) $editRow['department_id'],
            'credit_hours' => (int) $editRow['credit_hours'],
        ];
    }
}

// ---------------------------------------------------------------------
// Data for rendering — Departments/Lecturers/Courses lists are all scoped
// to the Dean's own faculty (never trusted from request input).
// ---------------------------------------------------------------------
if ($role === 'dean') {
    $deptStmt = $conn->prepare(
        "SELECT d.id, d.code, d.name, d.faculty_id, f.name AS faculty_name
         FROM departments d
         JOIN faculties f ON f.id = d.faculty_id
         WHERE d.faculty_id = ?
         ORDER BY d.name"
    );
    $deptStmt->bind_param('i', $deanFacultyId);
    $deptStmt->execute();
    $departments = $deptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $deptStmt->close();

    // "Current Offering" per course: that course's own faculty's current
    // semester's course_offerings row (if any), never a permanent column —
    // NULL current_semester_name means the faculty has no current semester
    // set at all; NULL current_lecturer_name with a semester set means an
    // offering exists but is unassigned.
    $courseStmt = $conn->prepare(
        "SELECT c.id, c.code, c.name, c.credit_hours, c.department_id,
                d.name AS department_name, f.name AS faculty_name,
                cur_se.name AS current_semester_name, ay.label AS current_academic_year_label,
                ol.full_name AS current_lecturer_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         LEFT JOIN semesters cur_se ON cur_se.faculty_id = d.faculty_id AND cur_se.is_current = 1
         LEFT JOIN academic_years ay ON ay.id = cur_se.academic_year_id
         LEFT JOIN course_offerings co ON co.course_id = c.id AND co.semester_id = cur_se.id
         LEFT JOIN lecturers ol ON ol.id = co.lecturer_id
         WHERE d.faculty_id = ?
         ORDER BY d.name, c.code"
    );
    $courseStmt->bind_param('i', $deanFacultyId);
    $courseStmt->execute();
    $courses = $courseStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $courseStmt->close();
} else {
    $departments = $conn->query(
        "SELECT d.id, d.code, d.name, d.faculty_id, f.name AS faculty_name
         FROM departments d
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name"
    )->fetch_all(MYSQLI_ASSOC);

    $courses = $conn->query(
        "SELECT c.id, c.code, c.name, c.credit_hours, c.department_id,
                d.name AS department_name, f.name AS faculty_name,
                cur_se.name AS current_semester_name, ay.label AS current_academic_year_label,
                ol.full_name AS current_lecturer_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         LEFT JOIN semesters cur_se ON cur_se.faculty_id = d.faculty_id AND cur_se.is_current = 1
         LEFT JOIN academic_years ay ON ay.id = cur_se.academic_year_id
         LEFT JOIN course_offerings co ON co.course_id = c.id AND co.semester_id = cur_se.id
         LEFT JOIN lecturers ol ON ol.id = co.lecturer_id
         ORDER BY f.name, d.name, c.code"
    )->fetch_all(MYSQLI_ASSOC);
}

$departmentsByFaculty = [];
foreach ($departments as $dept) {
    $departmentsByFaculty[$dept['faculty_name']][] = $dept;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Management — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        .btn-icon {
            border: none;
            background: transparent;
            color: #64748b;
            padding: 0.25rem 0.4rem;
            border-radius: 6px;
        }

        .btn-icon:hover {
            background: var(--admas-bg);
            color: #0b1f3a;
        }

        .btn-icon.text-danger:hover {
            background: rgba(220, 38, 38, 0.08);
            color: #dc2626;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                <?= $role === 'dean'
                    ? 'Access scope: ' . htmlspecialchars($deanFacultyName) . ' Faculty only'
                    : 'Access scope: Full system — all faculties, departments, and courses' ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Course Management</h4>
                    <p class="text-muted mb-0">Create and manage courses.</p>
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

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="admas-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h6 class="fw-bold mb-0" style="color: #0b1f3a;">Courses</h6>
                            <div class="d-flex gap-2">
                                <button type="button" id="bulkDeleteCoursesBtn" class="btn btn-outline-danger btn-sm d-none">Delete Selected</button>
                                <?php if ($role === 'system_admin'): ?>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses_import.php" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-file-earmark-arrow-up"></i> Import from Excel
                                    </a>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="btn btn-primary btn-sm" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                                    <i class="bi bi-plus-lg"></i> Add Course
                                </a>
                            </div>
                        </div>

                        <form id="bulkDeleteCoursesForm" method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="d-none">
                            <input type="hidden" name="action" value="bulk_delete">
                            <div id="bulkDeleteCoursesIds"></div>
                        </form>

                        <div class="table-responsive">
                            <table class="table admas-table align-middle">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllCourses"></th>
                                        <th>Code</th>
                                        <th>Course Name</th>
                                        <th>Department</th>
                                        <th>Faculty</th>
                                        <th>Current Offering</th>
                                        <th>Credit Hours</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($courses)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">No courses have been created yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($courses as $c): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="row-check-course" value="<?= (int) $c['id'] ?>"
                                                           data-label="<?= htmlspecialchars($c['name'] . ' (' . $c['code'] . ')') ?>">
                                                </td>
                                                <td><span class="badge-pill badge-active"><?= htmlspecialchars($c['code']) ?></span></td>
                                                <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($c['name']) ?></td>
                                                <td><?= htmlspecialchars($c['department_name']) ?></td>
                                                <td><?= htmlspecialchars($c['faculty_name']) ?></td>
                                                <td>
                                                    <?php if (!$c['current_semester_name']): ?>
                                                        <span class="text-muted fst-italic">No current semester</span>
                                                    <?php elseif ($c['current_lecturer_name']): ?>
                                                        <?= htmlspecialchars($c['current_lecturer_name']) ?>
                                                        <div class="text-muted small"><?= htmlspecialchars($c['current_semester_name'] . ' (' . $c['current_academic_year_label'] . ')') ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic">Unassigned</span>
                                                        <div class="text-muted small"><?= htmlspecialchars($c['current_semester_name'] . ' (' . $c['current_academic_year_label'] . ')') ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= (int) $c['credit_hours'] ?></td>
                                                <td>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $c['id'] ?>" class="btn-icon" title="Manage Offerings">
                                                        <i class="bi bi-calendar2-week"></i>
                                                    </a>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php?edit=<?= (int) $c['id'] ?>" class="btn-icon" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" style="display:inline;"
                                                          onsubmit="return confirm('Delete this course? This cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
                                                        <button type="submit" class="btn-icon text-danger" title="Delete">
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

                <div class="col-lg-4">
                    <div class="admas-card p-4">
                        <h6 class="fw-bold mb-3" style="color: #0b1f3a;">
                            <?= $formMode === 'edit' ? 'Edit Course' : 'Add Course' ?>
                        </h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php">
                            <input type="hidden" name="action" value="<?= $formMode === 'edit' ? 'update' : 'create' ?>">
                            <?php if ($formMode === 'edit'): ?>
                                <input type="hidden" name="course_id" value="<?= (int) $formValues['id'] ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="courseCodeInput" class="form-label">Code</label>
                                <input type="text" class="form-control text-uppercase" id="courseCodeInput" name="code" maxlength="20"
                                       value="<?= htmlspecialchars($formValues['code']) ?>" required>
                                <div class="form-text">Must be unique within the selected department. The same code may repeat in other departments.</div>
                            </div>

                            <div class="mb-3">
                                <label for="courseNameInput" class="form-label">Course Name</label>
                                <input type="text" class="form-control" id="courseNameInput" name="name" maxlength="150"
                                       value="<?= htmlspecialchars($formValues['name']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="courseDepartmentSelect" class="form-label">Department</label>
                                <select class="form-select" id="courseDepartmentSelect" name="department_id" required>
                                    <option value="">Select department</option>
                                    <?php foreach ($departmentsByFaculty as $facultyName => $deptList): ?>
                                        <optgroup label="<?= htmlspecialchars($facultyName) ?>">
                                            <?php foreach ($deptList as $d): ?>
                                                <option value="<?= (int) $d['id'] ?>" <?= (int) $formValues['department_id'] === (int) $d['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($d['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($departments)): ?>
                                    <div class="form-text text-danger">No departments exist yet — create one first.</div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="courseCreditHoursInput" class="form-label">Credit Hours</label>
                                <input type="number" class="form-control" id="courseCreditHoursInput" name="credit_hours" min="1" max="10"
                                       value="<?= (int) $formValues['credit_hours'] ?>" required>
                            </div>

                            <?php if ($formMode === 'edit'): ?>
                                <div class="form-text mb-3">
                                    Lecturer assignment is per-semester now — use
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $formValues['id'] ?>">Manage Offerings</a>
                                    after saving.
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: #0ea5e9; border-color: #0ea5e9;" <?= empty($departments) ? 'disabled' : '' ?>>
                                <?= $formMode === 'edit' ? 'Update Course' : 'Save Course' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/bulk_delete.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            admasInitBulkDelete({
                checkboxSelector: '.row-check-course',
                selectAllSelector: '#selectAllCourses',
                buttonSelector: '#bulkDeleteCoursesBtn',
                formSelector: '#bulkDeleteCoursesForm',
                hiddenContainerSelector: '#bulkDeleteCoursesIds',
                hiddenInputName: 'course_ids[]',
                entityLabel: 'course',
                entityLabelPlural: 'courses',
            });
        });
    </script>
</body>
</html>
