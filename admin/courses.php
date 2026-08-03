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
require_once __DIR__ . '/../includes/attendance_helpers.php';

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
$formValues = [
    'id' => 0,
    'code' => '',
    'name' => '',
    'department_id' => 0,
    'credit_hours' => 3,
    // Optional "first offering" fields — only meaningful on create; see the
    // offering_semester_id > 0 branch in the create handler below.
    'offering_semester_id' => 0,
    'offering_shift' => '',
    'offering_lecturer_id' => 0,
];

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

        // Optional "first offering" fields — create-only, and the whole
        // section is opt-in: left blank, the course is created exactly as
        // before with no course_offerings row.
        $offeringSemesterId = $action === 'create' ? (int) ($_POST['offering_semester_id'] ?? 0) : 0;
        $offeringShift = $action === 'create' ? (string) ($_POST['offering_shift'] ?? '') : '';
        $offeringLecturerId = $action === 'create' ? (int) ($_POST['offering_lecturer_id'] ?? 0) : 0;

        $formMode = $action === 'update' ? 'edit' : 'create';
        $formValues = [
            'id' => $courseId,
            'code' => $code,
            'name' => $name,
            'department_id' => $departmentId,
            'credit_hours' => $creditHours,
            'offering_semester_id' => $offeringSemesterId,
            'offering_shift' => $offeringShift,
            'offering_lecturer_id' => $offeringLecturerId,
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

        $departmentFacultyId = 0;
        if ($validationError === '') {
            if ($role === 'dean') {
                $deptCheckStmt = $conn->prepare('SELECT id, faculty_id FROM departments WHERE id = ? AND faculty_id = ?');
                $deptCheckStmt->bind_param('ii', $departmentId, $deanFacultyId);
            } else {
                $deptCheckStmt = $conn->prepare('SELECT id, faculty_id FROM departments WHERE id = ?');
                $deptCheckStmt->bind_param('i', $departmentId);
            }
            $deptCheckStmt->execute();
            $departmentRow = $deptCheckStmt->get_result()->fetch_assoc();
            if (!$departmentRow) {
                $validationError = $role === 'dean'
                    ? 'Selected department does not belong to your faculty.'
                    : 'Selected department does not exist.';
            } else {
                $departmentFacultyId = (int) $departmentRow['faculty_id'];
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

        // Only validated at all if a Semester was actually chosen — this
        // whole section is opt-in (Semester left blank = no offering,
        // course created exactly as before).
        $offeringSemesterRow = null;
        if ($validationError === '' && $offeringSemesterId > 0) {
            $offeringSemStmt = $conn->prepare('SELECT id, name FROM semesters WHERE id = ? AND faculty_id = ?');
            $offeringSemStmt->bind_param('ii', $offeringSemesterId, $departmentFacultyId);
            $offeringSemStmt->execute();
            $offeringSemesterRow = $offeringSemStmt->get_result()->fetch_assoc();
            $offeringSemStmt->close();

            if (!$offeringSemesterRow) {
                $validationError = 'Please select a valid semester for the selected department\'s faculty.';
            } elseif (!array_key_exists($offeringShift, OFFERING_SHIFT_LABELS)) {
                $validationError = 'Please select a shift for the new offering.';
            } elseif ($offeringLecturerId > 0) {
                // Any active lecturer system-wide may be assigned, not just
                // ones in this course's own department — universities share
                // "common" courses across faculties via one lecturer, and a
                // Dean assigning an outside lecturer here only ever touches
                // a course_offerings row inside their own faculty (the
                // course + semester still belong to them), so this doesn't
                // widen what a Dean can see or edit elsewhere.
                $offeringLecStmt = $conn->prepare("SELECT id FROM lecturers WHERE id = ? AND status = 'active'");
                $offeringLecStmt->bind_param('i', $offeringLecturerId);
                $offeringLecStmt->execute();
                if (!$offeringLecStmt->get_result()->fetch_assoc()) {
                    $validationError = 'Selected lecturer is not a valid active lecturer.';
                }
                $offeringLecStmt->close();
            }
        }

        if ($validationError === '') {
            if ($action === 'create') {
                $conn->begin_transaction();
                try {
                    $insertStmt = $conn->prepare('INSERT INTO courses (code, name, department_id, credit_hours) VALUES (?, ?, ?, ?)');
                    $insertStmt->bind_param('ssii', $code, $name, $departmentId, $creditHours);
                    $insertStmt->execute();
                    $newCourseId = (int) $conn->insert_id;
                    $insertStmt->close();

                    $offeringCreated = false;
                    if ($offeringSemesterId > 0) {
                        $offeringLecturerParam = $offeringLecturerId > 0 ? $offeringLecturerId : null;
                        $offerStmt = $conn->prepare(
                            'INSERT INTO course_offerings (course_id, semester_id, lecturer_id, shift) VALUES (?, ?, ?, ?)'
                        );
                        $offerStmt->bind_param('iiis', $newCourseId, $offeringSemesterId, $offeringLecturerParam, $offeringShift);
                        $offerStmt->execute();
                        $offerStmt->close();
                        $offeringCreated = true;
                    }

                    $conn->commit();
                    $_SESSION['flash_success'] = $offeringCreated
                        ? ('Course added successfully, with an offering for "' . $offeringSemesterRow['name'] . '" (' . OFFERING_SHIFT_LABELS[$offeringShift] . ').')
                        : 'Course added successfully. Use "Manage Offerings" to assign a lecturer for a semester.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $_SESSION['flash_error'] = 'Could not save the course. Please try again.';
                }
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

    // Course list only — no offering/semester columns. "Current Offering"
    // is resolved separately below (see the 3-query note further down):
    // a faculty can have more than one concurrently-current semester, and
    // a course can now have more than one shift-offering per semester, so
    // a single flat LEFT JOIN here would fan out into duplicate course
    // rows once either of those is true.
    $courseStmt = $conn->prepare(
        "SELECT c.id, c.code, c.name, c.credit_hours, c.department_id,
                d.name AS department_name, f.name AS faculty_name, d.faculty_id
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
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
                d.name AS department_name, f.name AS faculty_name, d.faculty_id
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name, c.code"
    )->fetch_all(MYSQLI_ASSOC);
}

$departmentsByFaculty = [];
foreach ($departments as $dept) {
    $departmentsByFaculty[$dept['faculty_name']][] = $dept;
}

// ---------------------------------------------------------------------
// "Current Offering" per course — built as 2 more queries instead of
// folding into the course list above, since a faculty can have multiple
// concurrently-current semesters and a course can now have multiple
// shift-offerings per semester; either alone would fan out a flat JOIN
// into duplicate course rows, and combined they'd compound.
// ---------------------------------------------------------------------
$courseIdsForOfferings = array_map(static fn ($c) => (int) $c['id'], $courses);

// Every faculty each course actually has a real course_offerings row in,
// not just its own catalog/home faculty — a course can now be cross-listed
// into a different faculty's own semester track (see the Multi-Faculty
// Course Offerings plan), and this column should surface that too.
$offeringFacultyIdsByCourseForList = [];
if (!empty($courseIdsForOfferings)) {
    $cIdPlaceholders = implode(',', array_fill(0, count($courseIdsForOfferings), '?'));
    $offFacListStmt = $conn->prepare(
        "SELECT DISTINCT co.course_id, se.faculty_id
         FROM course_offerings co
         JOIN semesters se ON se.id = co.semester_id
         WHERE co.course_id IN ({$cIdPlaceholders})"
    );
    $offFacListStmt->bind_param(str_repeat('i', count($courseIdsForOfferings)), ...$courseIdsForOfferings);
    $offFacListStmt->execute();
    $offFacListRes = $offFacListStmt->get_result();
    while ($row = $offFacListRes->fetch_assoc()) {
        $offeringFacultyIdsByCourseForList[(int) $row['course_id']][] = (int) $row['faculty_id'];
    }
    $offFacListStmt->close();
}

$facultyIdsForOfferings = [];
foreach ($courses as $c) {
    $facultyIdsForOfferings[] = (int) $c['faculty_id'];
    foreach ($offeringFacultyIdsByCourseForList[(int) $c['id']] ?? [] as $fid) {
        $facultyIdsForOfferings[] = $fid;
    }
}
$facultyIdsForOfferings = array_values(array_unique($facultyIdsForOfferings));

$currentSemestersByFacultyId = [];
if (!empty($facultyIdsForOfferings)) {
    $placeholders = implode(',', array_fill(0, count($facultyIdsForOfferings), '?'));
    $semStmt = $conn->prepare(
        "SELECT f.id AS faculty_id, f.name AS faculty_name, se.id AS semester_id, se.name AS semester_name, ay.label AS academic_year_label
         FROM faculties f
         JOIN semesters se ON se.faculty_id = f.id AND se.is_current = 1
         JOIN academic_years ay ON ay.id = se.academic_year_id
         WHERE f.id IN ({$placeholders})
         ORDER BY se.name"
    );
    $semStmt->bind_param(str_repeat('i', count($facultyIdsForOfferings)), ...$facultyIdsForOfferings);
    $semStmt->execute();
    $semRes = $semStmt->get_result();
    while ($row = $semRes->fetch_assoc()) {
        $currentSemestersByFacultyId[(int) $row['faculty_id']][] = $row;
    }
    $semStmt->close();
}

$offeringsByCourseSemester = [];
if (!empty($courseIdsForOfferings) && !empty($currentSemestersByFacultyId)) {
    $semesterIdsForOfferings = [];
    foreach ($currentSemestersByFacultyId as $semList) {
        foreach ($semList as $sem) {
            $semesterIdsForOfferings[] = (int) $sem['semester_id'];
        }
    }
    $semesterIdsForOfferings = array_values(array_unique($semesterIdsForOfferings));

    $coursePlaceholders = implode(',', array_fill(0, count($courseIdsForOfferings), '?'));
    $semesterPlaceholders = implode(',', array_fill(0, count($semesterIdsForOfferings), '?'));
    $offStmt = $conn->prepare(
        "SELECT co.course_id, co.semester_id, co.shift, ol.full_name AS lecturer_name, co.start_date, co.end_date
         FROM course_offerings co
         LEFT JOIN lecturers ol ON ol.id = co.lecturer_id
         WHERE co.course_id IN ({$coursePlaceholders}) AND co.semester_id IN ({$semesterPlaceholders})
         ORDER BY co.shift"
    );
    $offStmt->bind_param(
        str_repeat('i', count($courseIdsForOfferings)) . str_repeat('i', count($semesterIdsForOfferings)),
        ...array_merge($courseIdsForOfferings, $semesterIdsForOfferings)
    );
    $offStmt->execute();
    $offRes = $offStmt->get_result();
    while ($row = $offRes->fetch_assoc()) {
        $offeringsByCourseSemester[(int) $row['course_id']][(int) $row['semester_id']][] = $row;
    }
    $offStmt->close();
}

$facultyIdByDepartmentId = [];
foreach ($departments as $dept) {
    $facultyIdByDepartmentId[(int) $dept['id']] = (int) $dept['faculty_id'];
}

// Semester options for the Add Course form's optional "first offering"
// section — grouped by faculty THEN by academic year for the
// Department -> Academic Year -> Semester cascade (Academic Year is a
// real, freely-selectable dropdown listing every academic year in the
// project, not derived from the Semester; the same semester NAME can
// legitimately repeat across different academic years for one faculty —
// see admin/courses_import.php's own (faculty, academic_year, name)
// lookup — so Academic Year genuinely narrows the Semester list rather
// than being decorative).
if ($role === 'dean') {
    $offeringSemStmt = $conn->prepare(
        'SELECT s.id, s.name, s.faculty_id, s.is_current, ay.id AS academic_year_id, ay.label AS academic_year_label
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         WHERE s.faculty_id = ?
         ORDER BY s.start_date DESC'
    );
    $offeringSemStmt->bind_param('i', $deanFacultyId);
    $offeringSemStmt->execute();
    $offeringSemesters = $offeringSemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $offeringSemStmt->close();
} else {
    $offeringSemesters = $conn->query(
        'SELECT s.id, s.name, s.faculty_id, s.is_current, ay.id AS academic_year_id, ay.label AS academic_year_label
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         WHERE s.faculty_id IS NOT NULL
         ORDER BY s.start_date DESC'
    )->fetch_all(MYSQLI_ASSOC);
}

// All academic years in the project — the Academic Year dropdown is
// unfiltered (every faculty/dean shares the same academic year list; it's
// the Semester dropdown below it that narrows by faculty + this choice).
$allAcademicYears = $conn->query('SELECT id, label FROM academic_years ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);

$semestersByFacultyId = [];
$offeringSemesterAcademicYearById = [];
foreach ($offeringSemesters as $sem) {
    $semestersByFacultyId[(int) $sem['faculty_id']][(int) $sem['academic_year_id']][] = [
        'id' => (int) $sem['id'],
        'name' => $sem['name'],
        'is_current' => (int) $sem['is_current'] === 1,
    ];
    $offeringSemesterAcademicYearById[(int) $sem['id']] = (int) $sem['academic_year_id'];
}

// Lecturer options for the Department -> Lecturer cascade. Every active
// lecturer system-wide is available (not just the picked department's own)
// — universities share "common" courses across faculties via one
// lecturer, and assigning an outside lecturer only ever creates a
// course_offerings row inside this course's own department/faculty, so it
// never widens what a Dean can see or edit elsewhere. Grouped by
// department for the JS cascade's "own department" list, plus one flat
// "every other active lecturer" list (labeled with their home faculty) the
// same JS appends below it.
$offeringLecturers = $conn->query(
    "SELECT l.id, l.full_name, l.department_id, f.name AS home_faculty_name
     FROM lecturers l
     JOIN departments d ON d.id = l.department_id
     JOIN faculties f ON f.id = d.faculty_id
     WHERE l.status = 'active'
     ORDER BY l.full_name"
)->fetch_all(MYSQLI_ASSOC);

$lecturersByDepartmentId = [];
$allActiveLecturers = [];
foreach ($offeringLecturers as $lec) {
    $lecturersByDepartmentId[(int) $lec['department_id']][] = ['id' => (int) $lec['id'], 'full_name' => $lec['full_name']];
    $allActiveLecturers[] = [
        'id' => (int) $lec['id'],
        'full_name' => $lec['full_name'],
        'department_id' => (int) $lec['department_id'],
        'home_faculty_name' => $lec['home_faculty_name'],
    ];
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
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Course Management</h4>
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
                            <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Courses</h6>
                            <div class="d-flex gap-2">
                                <button type="button" id="bulkDeleteCoursesBtn" class="btn btn-outline-danger btn-sm d-none">Delete Selected</button>
                                <?php if ($role === 'system_admin'): ?>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses_import.php" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-file-earmark-arrow-up"></i> Import from Excel
                                    </a>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings_search.php" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" title="Cross-list a course whose catalog home is a different faculty into <?= $role === 'dean' ? 'your own faculty' : 'any faculty' ?>'s semester">
                                    <i class="bi bi-signpost-2"></i> Add Existing Course
                                </a>
                                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
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
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($c['name']) ?></td>
                                                <td><?= htmlspecialchars($c['department_name']) ?></td>
                                                <td><?= htmlspecialchars($c['faculty_name']) ?></td>
                                                <td>
                                                    <?php
                                                    // Every faculty this course is actually offered in (home + any
                                                    // cross-listed/guest faculties, see the Multi-Faculty Course
                                                    // Offerings plan), not just its own catalog faculty.
                                                    $courseRelevantFacultyIds = array_values(array_unique(array_merge(
                                                        [(int) $c['faculty_id']],
                                                        $offeringFacultyIdsByCourseForList[(int) $c['id']] ?? []
                                                    )));
                                                    $courseCurrentSemesters = [];
                                                    foreach ($courseRelevantFacultyIds as $relFacId) {
                                                        foreach ($currentSemestersByFacultyId[$relFacId] ?? [] as $sem) {
                                                            $sem['__faculty_id'] = $relFacId;
                                                            $courseCurrentSemesters[] = $sem;
                                                        }
                                                    }
                                                    ?>
                                                    <?php if (empty($courseCurrentSemesters)): ?>
                                                        <span class="text-muted fst-italic">No current semester</span>
                                                    <?php else: ?>
                                                        <?php foreach ($courseCurrentSemesters as $sem): ?>
                                                            <?php
                                                            $semOfferings = $offeringsByCourseSemester[(int) $c['id']][(int) $sem['semester_id']] ?? [];
                                                            $isGuestFacultySem = (int) $sem['__faculty_id'] !== (int) $c['faculty_id'];
                                                            ?>
                                                            <div class="mb-1">
                                                                <?php if ($isGuestFacultySem): ?>
                                                                    <span class="badge-pill badge-warning">Guest: <?= htmlspecialchars($sem['faculty_name']) ?></span>
                                                                <?php endif; ?>
                                                                <?php if (empty($semOfferings)): ?>
                                                                    <span class="text-muted fst-italic">No offering yet</span>
                                                                <?php else: ?>
                                                                    <?php foreach ($semOfferings as $off): ?>
                                                                        <div>
                                                                            <?php if ($off['shift'] !== 'any'): ?>
                                                                                <span class="text-muted"><?= htmlspecialchars(OFFERING_SHIFT_LABELS[$off['shift']] ?? $off['shift']) ?>:</span>
                                                                            <?php endif; ?>
                                                                            <?= $off['lecturer_name'] ? htmlspecialchars($off['lecturer_name']) : '<span class="text-muted fst-italic">Unassigned</span>' ?>
                                                                            <?php if ($off['start_date'] || $off['end_date']): ?>
                                                                                <span class="text-muted small">(<?= htmlspecialchars(($off['start_date'] ?? '?') . ' to ' . ($off['end_date'] ?? '?')) ?>)</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                                <div class="text-muted small"><?= htmlspecialchars($sem['semester_name'] . ' (' . $sem['academic_year_label'] . ')') ?></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= (int) $c['credit_hours'] ?></td>
                                                <td>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $c['id'] ?>" class="btn-icon text-sky" title="Manage Offerings">
                                                        <i class="bi bi-calendar2-week"></i>
                                                    </a>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_enrollments.php?course_id=<?= (int) $c['id'] ?>" class="btn-icon text-sky" title="Enroll Students">
                                                        <i class="bi bi-person-check"></i>
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
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">
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
                                <select class="form-select" id="courseDepartmentSelect" name="department_id" required onchange="admasUpdateOfferingFieldsForDepartment(this.value)">
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
                            <?php else: ?>
                                <hr class="my-3">
                                <p class="small text-muted mb-2">Optionally create this course's first offering now, instead of using "Manage Offerings" afterward.</p>

                                <div class="mb-3">
                                    <label for="offeringAcademicYearSelect" class="form-label">Academic Year <span class="text-muted fw-normal">(optional)</span></label>
                                    <select class="form-select" id="offeringAcademicYearSelect" onchange="admasRebuildOfferingSemesterOptions()">
                                        <option value="">Select academic year</option>
                                        <?php foreach ($allAcademicYears as $ay): ?>
                                            <option value="<?= (int) $ay['id'] ?>"><?= htmlspecialchars($ay['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="offeringSemesterSelect" class="form-label">Semester <span class="text-muted fw-normal">(optional)</span></label>
                                    <select class="form-select" id="offeringSemesterSelect" name="offering_semester_id" onchange="admasUpdateOfferingSemesterChange()">
                                        <option value="">Select a department and academic year first</option>
                                    </select>
                                </div>

                                <div id="offeringDetailsBlock" class="d-none">
                                    <div class="mb-3">
                                        <label for="offeringShiftSelect" class="form-label">Shift</label>
                                        <select class="form-select" id="offeringShiftSelect" name="offering_shift">
                                            <option value="">Select shift</option>
                                            <?php foreach (OFFERING_SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                                <option value="<?= htmlspecialchars($shiftValue) ?>" <?= $formValues['offering_shift'] === $shiftValue ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($shiftLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label for="offeringLecturerSelect" class="form-label">Lecturer</label>
                                        <select class="form-select" id="offeringLecturerSelect" name="offering_lecturer_id">
                                            <option value="0">Unassigned</option>
                                        </select>
                                        <div class="form-text">Only lecturers in the selected department are shown.</div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= empty($departments) ? 'disabled' : '' ?>>
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

        // Add Course form's optional "first offering" section (create mode
        // only — these elements don't exist at all in edit mode, so every
        // function here is a no-op if it can't find them).
        const facultyIdByDepartmentId = <?= json_encode($facultyIdByDepartmentId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        // Nested by faculty THEN academic year — Semester is cascaded from
        // BOTH (Department's faculty, chosen Academic Year), never from
        // Semester alone, since the same semester name can legitimately
        // repeat across different academic years for one faculty.
        const semestersByFacultyId = <?= json_encode($semestersByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const offeringSemesterAcademicYearById = <?= json_encode($offeringSemesterAcademicYearById, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const lecturersByDepartmentId = <?= json_encode($lecturersByDepartmentId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const allActiveLecturers = <?= json_encode($allActiveLecturers, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function admasRebuildOfferingSemesterOptions(selectedSemesterId) {
            const semesterSelect = document.getElementById('offeringSemesterSelect');
            const departmentSelect = document.getElementById('courseDepartmentSelect');
            const academicYearSelect = document.getElementById('offeringAcademicYearSelect');
            if (!semesterSelect || !departmentSelect || !academicYearSelect) {
                return;
            }

            const departmentId = departmentSelect.value;
            const academicYearId = academicYearSelect.value;
            const facultyId = facultyIdByDepartmentId[departmentId];
            const semesters = (facultyId !== undefined && academicYearId && semestersByFacultyId[facultyId])
                ? (semestersByFacultyId[facultyId][academicYearId] || [])
                : [];

            semesterSelect.innerHTML = '';
            const blank = document.createElement('option');
            blank.value = '';
            if (!departmentId || !academicYearId) {
                blank.textContent = 'Select a department and academic year first';
            } else {
                blank.textContent = semesters.length === 0
                    ? 'No semesters for this faculty in this academic year yet'
                    : 'No offering yet — select a semester below';
            }
            semesterSelect.appendChild(blank);
            semesters.forEach((sem) => {
                const opt = document.createElement('option');
                opt.value = String(sem.id);
                opt.textContent = sem.name + (sem.is_current ? ' — Current' : '');
                semesterSelect.appendChild(opt);
            });

            semesterSelect.value = String(selectedSemesterId || '');
            if (semesterSelect.value !== String(selectedSemesterId || '')) {
                semesterSelect.value = '';
            }
            admasUpdateOfferingSemesterChange();
        }

        function admasUpdateOfferingFieldsForDepartment(departmentId, selectedSemesterId) {
            const lecturerSelect = document.getElementById('offeringLecturerSelect');
            if (!lecturerSelect) {
                return;
            }

            lecturerSelect.innerHTML = '';
            const unassigned = document.createElement('option');
            unassigned.value = '0';
            unassigned.textContent = 'Unassigned';
            lecturerSelect.appendChild(unassigned);
            const ownDepartmentLecturers = lecturersByDepartmentId[departmentId] || [];
            const ownDepartmentIds = new Set(ownDepartmentLecturers.map((lec) => lec.id));
            ownDepartmentLecturers.forEach((lec) => {
                const opt = document.createElement('option');
                opt.value = String(lec.id);
                opt.textContent = lec.full_name;
                lecturerSelect.appendChild(opt);
            });

            // Every other active lecturer (outside this department) is
            // still selectable — labeled with their home faculty — for
            // "common" courses one lecturer teaches across faculties.
            const otherLecturers = allActiveLecturers.filter((lec) => !ownDepartmentIds.has(lec.id));
            if (otherLecturers.length > 0) {
                const separator = document.createElement('option');
                separator.disabled = true;
                separator.textContent = '── Other faculties ──';
                lecturerSelect.appendChild(separator);
                otherLecturers.forEach((lec) => {
                    const opt = document.createElement('option');
                    opt.value = String(lec.id);
                    opt.textContent = lec.full_name + ' (' + lec.home_faculty_name + ')';
                    lecturerSelect.appendChild(opt);
                });
            }

            admasRebuildOfferingSemesterOptions(selectedSemesterId);
        }

        function admasUpdateOfferingSemesterChange() {
            const semesterSelect = document.getElementById('offeringSemesterSelect');
            const detailsBlock = document.getElementById('offeringDetailsBlock');
            if (!semesterSelect || !detailsBlock) {
                return;
            }

            if (semesterSelect.value) {
                detailsBlock.classList.remove('d-none');
            } else {
                detailsBlock.classList.add('d-none');
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const departmentSelect = document.getElementById('courseDepartmentSelect');
            const academicYearSelect = document.getElementById('offeringAcademicYearSelect');
            const offeringLecturerSelect = document.getElementById('offeringLecturerSelect');
            if (departmentSelect && document.getElementById('offeringSemesterSelect')) {
                const priorSemesterId = <?= (int) $formValues['offering_semester_id'] ?>;
                // Re-select whichever Academic Year that prior Semester
                // belonged to (failed-submit re-render only — Academic Year
                // itself isn't a submitted field, since course_offerings
                // only stores semester_id).
                if (academicYearSelect && priorSemesterId && offeringSemesterAcademicYearById[priorSemesterId]) {
                    academicYearSelect.value = String(offeringSemesterAcademicYearById[priorSemesterId]);
                }
                admasUpdateOfferingFieldsForDepartment(departmentSelect.value, priorSemesterId);
                if (offeringLecturerSelect) {
                    offeringLecturerSelect.value = <?= json_encode((string) $formValues['offering_lecturer_id']) ?>;
                }
            }
        });
    </script>
</body>
</html>
