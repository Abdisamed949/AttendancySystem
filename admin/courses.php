<?php
/**
 * Course Management — University Rector (all faculties), Head of
 * Academic Affairs (all faculties, per the CLAUDE.md §4 revision granting
 * this role cross-faculty Course Management), and Dean (own faculty only).
 * Dean's faculty_id is always read from $_SESSION, never trusted from
 * request input (same pattern used across
 * attendance.php/reports.php/admin/departments.php). head_academic falls
 * through the same `else` branch as university_rector everywhere in this file
 * (the branching is on `$role === 'dean'`, not an allowlist of the
 * unrestricted roles), so it sees/edits every faculty with no extra code.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['university_rector', 'head_academic', 'dean']);

$conn = db();
$currentUser = current_user();
$role = current_role();
$isReadOnly = ($role === 'university_rector');

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
    // First-offering fields — only meaningful/submitted on create. Semester
    // is required on create (see the create handler below) — a course can
    // no longer be saved as catalog-only from this form.
    'offering_semester_id' => 0,
    'offering_shift' => '',
    'offering_lecturer_id' => 0,
    'roster_department_id' => 0,
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

    if ($isReadOnly) {
        $_SESSION['flash_error'] = 'Access scope: View only — this role cannot modify records.';
        redirect_to('admin/courses.php');
    }

    if ($action === 'create' || $action === 'update') {
        $courseId = $action === 'update' ? (int) ($_POST['course_id'] ?? 0) : 0;
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $creditHours = (int) ($_POST['credit_hours'] ?? 3);

        // First-offering fields — create-only. Semester is now REQUIRED on
        // create: a course must be given its first offering (Semester +
        // Shift) immediately rather than left catalog-only for a later
        // "Manage Offerings" trip.
        $offeringSemesterId = $action === 'create' ? (int) ($_POST['offering_semester_id'] ?? 0) : 0;
        $offeringShift = $action === 'create' ? (string) ($_POST['offering_shift'] ?? '') : '';
        $offeringLecturerId = $action === 'create' ? (int) ($_POST['offering_lecturer_id'] ?? 0) : 0;
        $rosterDepartmentIdRaw = $action === 'create' ? (int) ($_POST['roster_department_id'] ?? 0) : 0;

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
            'roster_department_id' => $rosterDepartmentIdRaw,
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

        // Semester is required on create (Head of Academic Affairs / Dean
        // must give the course a real first offering immediately). Dean is
        // still locked to their own faculty's semesters (mirrors
        // admin/course_offerings.php's own dean-vs-not branch exactly);
        // Head of Academic Affairs may target ANY faculty's semester —
        // when that semester's faculty differs from the course's own
        // department's faculty, this is a cross-faculty "guest" offering
        // and a Roster Department (from the semester's own faculty) is
        // required, same rule/reasoning as admin/course_offerings.php.
        $offeringSemesterRow = null;
        $rosterDepartmentId = null;
        if ($validationError === '' && $action === 'create') {
            if ($offeringSemesterId <= 0) {
                $validationError = 'Please select a semester for this course\'s first offering.';
            } else {
                if ($role === 'dean') {
                    $offeringSemStmt = $conn->prepare('SELECT id, name, faculty_id FROM semesters WHERE id = ? AND faculty_id = ?');
                    $offeringSemStmt->bind_param('ii', $offeringSemesterId, $deanFacultyId);
                } else {
                    $offeringSemStmt = $conn->prepare('SELECT id, name, faculty_id FROM semesters WHERE id = ?');
                    $offeringSemStmt->bind_param('i', $offeringSemesterId);
                }
                $offeringSemStmt->execute();
                $offeringSemesterRow = $offeringSemStmt->get_result()->fetch_assoc();
                $offeringSemStmt->close();

                if (!$offeringSemesterRow) {
                    $validationError = $role === 'dean'
                        ? 'Please select a valid semester from your own faculty.'
                        : 'Please select a valid semester.';
                } elseif (!array_key_exists($offeringShift, OFFERING_SHIFT_LABELS)) {
                    $validationError = 'Please select a shift for the new offering.';
                } else {
                    $isGuestOffering = (int) $offeringSemesterRow['faculty_id'] !== $departmentFacultyId;
                    if ($rosterDepartmentIdRaw > 0) {
                        $rdStmt = $conn->prepare('SELECT id FROM departments WHERE id = ? AND faculty_id = ?');
                        $rdStmt->bind_param('ii', $rosterDepartmentIdRaw, $offeringSemesterRow['faculty_id']);
                        $rdStmt->execute();
                        $rdRow = $rdStmt->get_result()->fetch_assoc();
                        $rdStmt->close();
                        if (!$rdRow) {
                            $validationError = 'Roster Department must belong to the selected semester\'s own faculty.';
                        } else {
                            $rosterDepartmentId = $rosterDepartmentIdRaw;
                        }
                    } elseif ($isGuestOffering) {
                        $validationError = 'This course\'s department is in a different faculty than the selected semester — please select a Roster Department so the correct students are used for this offering.';
                    }

                    if ($validationError === '' && $offeringLecturerId > 0) {
                        // Any active lecturer system-wide may be assigned, not
                        // just ones in this course's own department —
                        // universities share "common" courses across
                        // faculties via one lecturer.
                        $offeringLecStmt = $conn->prepare("SELECT id FROM lecturers WHERE id = ? AND status = 'active'");
                        $offeringLecStmt->bind_param('i', $offeringLecturerId);
                        $offeringLecStmt->execute();
                        if (!$offeringLecStmt->get_result()->fetch_assoc()) {
                            $validationError = 'Selected lecturer is not a valid active lecturer.';
                        }
                        $offeringLecStmt->close();
                    }
                }
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
                            'INSERT INTO course_offerings (course_id, semester_id, lecturer_id, roster_department_id, shift) VALUES (?, ?, ?, ?, ?)'
                        );
                        $offerStmt->bind_param('iiiis', $newCourseId, $offeringSemesterId, $offeringLecturerParam, $rosterDepartmentId, $offeringShift);
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
$filterSearch = trim((string) ($_GET['search'] ?? ''));

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
    $courseSql = "SELECT c.id, c.code, c.name, c.credit_hours, c.department_id,
                d.name AS department_name, f.name AS faculty_name, d.faculty_id
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         WHERE d.faculty_id = ?";
    $courseParams = [$deanFacultyId];
    $courseTypes = 'i';
    if ($filterSearch !== '') {
        $courseSql .= ' AND (c.code LIKE ? OR c.name LIKE ?)';
        $likeParam = '%' . $filterSearch . '%';
        $courseParams[] = $likeParam;
        $courseParams[] = $likeParam;
        $courseTypes .= 'ss';
    }
    $courseSql .= ' ORDER BY d.name, c.code';
    $courseStmt = $conn->prepare($courseSql);
    $courseStmt->bind_param($courseTypes, ...$courseParams);
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

    $courseSql = "SELECT c.id, c.code, c.name, c.credit_hours, c.department_id,
                d.name AS department_name, f.name AS faculty_name, d.faculty_id
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id";
    $courseParams = [];
    $courseTypes = '';
    if ($filterSearch !== '') {
        $courseSql .= ' WHERE (c.code LIKE ? OR c.name LIKE ?)';
        $likeParam = '%' . $filterSearch . '%';
        $courseParams = [$likeParam, $likeParam];
        $courseTypes = 'ss';
    }
    $courseSql .= ' ORDER BY f.name, d.name, c.code';
    if ($courseTypes !== '') {
        $courseStmt = $conn->prepare($courseSql);
        $courseStmt->bind_param($courseTypes, ...$courseParams);
        $courseStmt->execute();
        $courses = $courseStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $courseStmt->close();
    } else {
        $courses = $conn->query($courseSql)->fetch_all(MYSQLI_ASSOC);
    }
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

// Every real course_offerings row for each course, in ANY semester
// regardless of that semester's waiting/current/ended status. Previously
// this column only showed offerings inside a "current" semester — but
// "current" is one shared flag per faculty, and toggling it for an
// unrelated reason (e.g. correcting a dashboard chart) silently hid a
// completely different course's real, fully-set-up offering. The admin
// explicitly asked that a course's own offering data (shift/lecturer/
// students) decide whether it shows as Complete/Incomplete here — never
// which semester happens to be flagged current system-wide. Semester
// status is still shown alongside each offering as plain information, not
// as a filter.
$offeringsByCourse = [];
if (!empty($courseIdsForOfferings)) {
    $coursePlaceholders = implode(',', array_fill(0, count($courseIdsForOfferings), '?'));
    $offStmt = $conn->prepare(
        "SELECT co.course_id, co.semester_id, co.shift, ol.full_name AS lecturer_name, co.start_date, co.end_date,
                se.name AS semester_name, se.status AS semester_status, ay.label AS academic_year_label,
                se.faculty_id AS semester_faculty_id, f.name AS semester_faculty_name
         FROM course_offerings co
         JOIN semesters se ON se.id = co.semester_id
         JOIN academic_years ay ON ay.id = se.academic_year_id
         JOIN faculties f ON f.id = se.faculty_id
         LEFT JOIN lecturers ol ON ol.id = co.lecturer_id
         WHERE co.course_id IN ({$coursePlaceholders})
         ORDER BY se.id DESC, co.shift"
    );
    $offStmt->bind_param(str_repeat('i', count($courseIdsForOfferings)), ...$courseIdsForOfferings);
    $offStmt->execute();
    $offRes = $offStmt->get_result();
    while ($row = $offRes->fetch_assoc()) {
        $offeringsByCourse[(int) $row['course_id']][(int) $row['semester_id']][] = $row;
    }
    $offStmt->close();
}

// Enrolled-student count per course (from course_enrollments — the same
// real, explicit roster "Enroll Students" manages), used below to mark an
// offering "Complete" only once it has both a lecturer AND at least one
// enrolled student. Not split by shift/semester — course_enrollments has no
// shift column, so this is a per-course total, matching what
// admin/course_enrollments.php itself manages.
$enrolledCountByCourseId = [];
if (!empty($courseIdsForOfferings)) {
    $coursePlaceholders2 = implode(',', array_fill(0, count($courseIdsForOfferings), '?'));
    $enrollCountStmt = $conn->prepare(
        "SELECT course_id, COUNT(*) AS cnt FROM course_enrollments WHERE course_id IN ({$coursePlaceholders2}) GROUP BY course_id"
    );
    $enrollCountStmt->bind_param(str_repeat('i', count($courseIdsForOfferings)), ...$courseIdsForOfferings);
    $enrollCountStmt->execute();
    $enrollCountRes = $enrollCountStmt->get_result();
    while ($row = $enrollCountRes->fetch_assoc()) {
        $enrolledCountByCourseId[(int) $row['course_id']] = (int) $row['cnt'];
    }
    $enrollCountStmt->close();
}

$facultyIdByDepartmentId = [];
foreach ($departments as $dept) {
    $facultyIdByDepartmentId[(int) $dept['id']] = (int) $dept['faculty_id'];
}

// Departments grouped by faculty id (not name) — feeds the Roster
// Department cascade below, same shape as admin/course_offerings.php's own
// $departmentsByFacultyId. Faculty list for the "Offering Faculty" picker
// (Head of Academic Affairs only — Dean never sees this control, their
// offering is always inside their own single faculty) is derived from the
// same distinct faculty ids/names already present in $departments.
$departmentsByFacultyId = [];
$offeringFacultiesForForm = [];
$seenOfferingFacultyIds = [];
foreach ($departments as $dept) {
    $deptFacultyId = (int) $dept['faculty_id'];
    $departmentsByFacultyId[$deptFacultyId][] = ['id' => (int) $dept['id'], 'name' => $dept['name']];
    if (!isset($seenOfferingFacultyIds[$deptFacultyId])) {
        $seenOfferingFacultyIds[$deptFacultyId] = true;
        $offeringFacultiesForForm[] = ['id' => $deptFacultyId, 'name' => $dept['faculty_name']];
    }
}
usort($offeringFacultiesForForm, static fn ($a, $b) => strcmp($a['name'], $b['name']));

// Semester options for the Add Course form's "first offering"
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
        'SELECT s.id, s.name, s.faculty_id, s.is_current, s.status, ay.id AS academic_year_id, ay.label AS academic_year_label
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
        'SELECT s.id, s.name, s.faculty_id, s.is_current, s.status, ay.id AS academic_year_id, ay.label AS academic_year_label
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
$offeringSemesterFacultyById = [];
foreach ($offeringSemesters as $sem) {
    $semestersByFacultyId[(int) $sem['faculty_id']][(int) $sem['academic_year_id']][] = [
        'id' => (int) $sem['id'],
        'name' => $sem['name'],
        'is_current' => (int) $sem['is_current'] === 1,
        'status' => $sem['status'],
    ];
    $offeringSemesterAcademicYearById[(int) $sem['id']] = (int) $sem['academic_year_id'];
    $offeringSemesterFacultyById[(int) $sem['id']] = (int) $sem['faculty_id'];
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
                <?php if ($role === 'dean'): ?>
                    Access scope: <?= htmlspecialchars($deanFacultyName) ?> Faculty only
                <?php elseif ($isReadOnly): ?>
                    Access scope: Full system — view only (oversight)
                <?php else: ?>
                    Access scope: Full system — all faculties, departments, and courses
                <?php endif; ?>
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
                <div class="<?= $isReadOnly ? 'col-lg-12' : 'col-lg-8' ?>">
                    <div class="admas-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Courses</h6>
                            <div class="d-flex gap-2">
                                <?php if (!$isReadOnly): ?>
                                    <button type="button" id="bulkDeleteCoursesBtn" class="btn btn-outline-danger btn-sm d-none">Delete Selected</button>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses_import.php" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-file-earmark-arrow-up"></i> Import from Excel
                                    </a>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings_search.php" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" title="Cross-list a course whose catalog home is a different faculty into <?= $role === 'dean' ? 'your own faculty' : 'any faculty' ?>'s semester">
                                        <i class="bi bi-signpost-2"></i> Add Existing Course
                                    </a>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-plus-lg"></i> Add Course
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$isReadOnly): ?>
        <form id="bulkDeleteCoursesForm" method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="d-none">
                            <input type="hidden" name="action" value="bulk_delete">
                            <div id="bulkDeleteCoursesIds"></div>
                        </form>
                        <?php endif; ?>

                        <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" id="coursesFilterForm" class="mb-3">
                            <div class="input-group input-group-sm" style="max-width: 340px;">
                                <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" name="search" placeholder="Search by course code or name" data-live-search
                                       value="<?= htmlspecialchars($filterSearch) ?>">
                                <?php if ($filterSearch !== ''): ?>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="btn btn-outline-secondary" title="Clear search"><i class="bi bi-x-lg"></i></a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table admas-table align-middle">
                                <thead>
                                    <tr>
                                        <?php if (!$isReadOnly): ?><th><input type="checkbox" id="selectAllCourses"></th><?php endif; ?>
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
                                            <td colspan="8" class="text-center text-muted py-4"><?= $filterSearch !== '' ? 'No courses match "' . htmlspecialchars($filterSearch) . '".' : 'No courses have been created yet.' ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($courses as $c): ?>
                                            <tr>
                                                <?php if (!$isReadOnly): ?>
                                                <td>
                                                    <input type="checkbox" class="row-check-course" value="<?= (int) $c['id'] ?>"
                                                           data-label="<?= htmlspecialchars($c['name'] . ' (' . $c['code'] . ')') ?>">
                                                </td>
                                                <?php endif; ?>
                                                <td><span class="badge-pill badge-active"><?= htmlspecialchars($c['code']) ?></span></td>
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($c['name']) ?></td>
                                                <td><?= htmlspecialchars($c['department_name']) ?></td>
                                                <td><?= htmlspecialchars($c['faculty_name']) ?></td>
                                                <td>
                                                    <?php
                                                    // Every real offering this course has, in ANY semester (any status —
                                                    // Waiting/Current/Ended never filters what's shown here, only
                                                    // informs it — see the note on $offeringsByCourse above).
                                                    $courseOfferingsBySem = $offeringsByCourse[(int) $c['id']] ?? [];
                                                    $semStatusBadgeClass = ['current' => 'badge-active', 'waiting' => 'badge-neutral', 'ended' => 'badge-inactive'];
                                                    ?>
                                                    <?php if (empty($courseOfferingsBySem)): ?>
                                                        <span class="text-muted fst-italic">No offering yet</span>
                                                    <?php else: ?>
                                                        <?php foreach ($courseOfferingsBySem as $semOfferings): ?>
                                                            <?php
                                                            $semMeta = $semOfferings[0];
                                                            $isGuestFacultySem = (int) $semMeta['semester_faculty_id'] !== (int) $c['faculty_id'];
                                                            ?>
                                                            <div class="mb-1">
                                                                <?php if ($isGuestFacultySem): ?>
                                                                    <span class="badge-pill badge-warning">Guest: <?= htmlspecialchars($semMeta['semester_faculty_name']) ?></span>
                                                                <?php endif; ?>
                                                                <?php foreach ($semOfferings as $off): ?>
                                                                    <?php
                                                                    $offEnrolledCount = $enrolledCountByCourseId[(int) $c['id']] ?? 0;
                                                                    $offHasLecturer = $off['lecturer_name'] !== null;
                                                                    $offIsComplete = $offHasLecturer && $offEnrolledCount > 0;
                                                                    ?>
                                                                    <div class="mb-1">
                                                                        <?php if ($off['shift'] !== 'any'): ?>
                                                                            <span class="text-muted"><?= htmlspecialchars(OFFERING_SHIFT_LABELS[$off['shift']] ?? $off['shift']) ?>:</span>
                                                                        <?php endif; ?>
                                                                        <?= $offHasLecturer ? htmlspecialchars($off['lecturer_name']) : '<span class="text-muted fst-italic">Unassigned</span>' ?>
                                                                        <?php if ($off['start_date'] || $off['end_date']): ?>
                                                                            <span class="text-muted small">(<?= htmlspecialchars(($off['start_date'] ?? '?') . ' to ' . ($off['end_date'] ?? '?')) ?>)</span>
                                                                        <?php endif; ?>
                                                                        <div class="small mt-1">
                                                                            <span class="text-muted"><i class="bi bi-people-fill"></i> <?= number_format($offEnrolledCount) ?> enrolled</span>
                                                                            <?php if ($offIsComplete): ?>
                                                                                <span class="badge-pill badge-active ms-1"><i class="bi bi-check-circle-fill"></i> Complete</span>
                                                                            <?php else: ?>
                                                                                <span class="badge-pill badge-warning ms-1" title="Missing: <?= htmlspecialchars(trim(($offHasLecturer ? '' : 'lecturer ') . ($offEnrolledCount > 0 ? '' : 'enrolled students'))) ?>">
                                                                                    <i class="bi bi-exclamation-triangle-fill"></i> Incomplete
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                                <div class="text-muted small">
                                                                    <?= htmlspecialchars($semMeta['semester_name'] . ' (' . $semMeta['academic_year_label'] . ')') ?>
                                                                    <span class="badge-pill <?= $semStatusBadgeClass[$semMeta['semester_status']] ?? 'badge-neutral' ?>"><?= htmlspecialchars(ucfirst($semMeta['semester_status'])) ?></span>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= (int) $c['credit_hours'] ?></td>
                                                <td>
                                                    <div class="d-flex flex-column align-items-start gap-1">
                                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $c['id'] ?>" class="btn-icon-label text-sky" title="<?= $isReadOnly ? 'View Offerings' : 'Manage Offerings' ?>">
                                                            <i class="bi bi-calendar2-week"></i> Offerings
                                                        </a>
                                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_enrollments.php?course_id=<?= (int) $c['id'] ?>" class="btn-icon-label text-sky" title="<?= $isReadOnly ? 'View Enrolled Students' : 'Enroll Students' ?>">
                                                            <i class="bi bi-person-check"></i> <?= $isReadOnly ? 'Enrolled' : 'Enroll' ?>
                                                        </a>
                                                        <?php if (!$isReadOnly): ?>
                                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php?edit=<?= (int) $c['id'] ?>" class="btn-icon-label" title="Edit">
                                                            <i class="bi bi-pencil"></i> Edit
                                                        </a>
                                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" style="display:inline;"
                                                              onsubmit="return confirm('Delete this course? This cannot be undone.');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
                                                            <button type="submit" class="btn-icon-label text-danger" title="Delete">
                                                                <i class="bi bi-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if (!$isReadOnly): ?>
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
                                <select class="form-select" id="courseDepartmentSelect" name="department_id" required onchange="admasOnCourseDepartmentChange(this.value)">
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
                                <p class="small text-muted mb-2">This course's first offering (Semester + Shift) is required now — it can no longer be left catalog-only.</p>

                                <?php if ($role !== 'dean'): ?>
                                <div class="mb-3">
                                    <label for="offeringFacultySelect" class="form-label">Offering Faculty</label>
                                    <select class="form-select" id="offeringFacultySelect" onchange="admasRebuildOfferingSemesterOptions(); admasUpdateRosterDepartmentVisibility();">
                                        <option value="">Select faculty</option>
                                        <?php foreach ($offeringFacultiesForForm as $fac): ?>
                                            <option value="<?= (int) $fac['id'] ?>"><?= htmlspecialchars($fac['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Defaults to the selected department's own faculty. Change it to offer this course into a different faculty's semester instead (cross-listing).</div>
                                </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label for="offeringAcademicYearSelect" class="form-label">Academic Year</label>
                                    <select class="form-select" id="offeringAcademicYearSelect" onchange="admasRebuildOfferingSemesterOptions()" required>
                                        <option value="">Select academic year</option>
                                        <?php foreach ($allAcademicYears as $ay): ?>
                                            <option value="<?= (int) $ay['id'] ?>"><?= htmlspecialchars($ay['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="offeringSemesterSelect" class="form-label">Semester</label>
                                    <select class="form-select" id="offeringSemesterSelect" name="offering_semester_id" onchange="admasUpdateOfferingSemesterChange()" required>
                                        <option value="">Select a department and academic year first</option>
                                    </select>
                                </div>

                                <div id="offeringDetailsBlock" class="d-none">
                                    <div class="mb-3">
                                        <label for="offeringShiftSelect" class="form-label">Shift</label>
                                        <select class="form-select" id="offeringShiftSelect" name="offering_shift" required>
                                            <option value="">Select shift</option>
                                            <?php foreach (OFFERING_SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                                <option value="<?= htmlspecialchars($shiftValue) ?>" <?= $formValues['offering_shift'] === $shiftValue ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($shiftLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <?php if ($role !== 'dean'): ?>
                                    <div class="mb-3 d-none" id="offeringRosterDepartmentBlock">
                                        <label for="offeringRosterDepartmentSelect" class="form-label">Roster Department</label>
                                        <select class="form-select" id="offeringRosterDepartmentSelect" name="roster_department_id">
                                            <option value="">Select roster department</option>
                                        </select>
                                        <div class="form-text">Required for a cross-faculty offering — decides which department's students form this offering's roster.</div>
                                    </div>
                                    <?php endif; ?>

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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/bulk_delete.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/semester_label.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/live_filter.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('bulkDeleteCoursesBtn')) {
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
            }
            admasInitLiveFilter('#coursesFilterForm');
        });

        // Add Course form's "first offering" section (create mode only —
        // these elements don't exist at all in edit mode, so every
        // function here is a no-op if it can't find them).
        const facultyIdByDepartmentId = <?= json_encode($facultyIdByDepartmentId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        // Nested by faculty THEN academic year — Semester is cascaded from
        // BOTH (the Offering Faculty — Head of Academic Affairs only, else
        // the Department's own faculty — and the chosen Academic Year),
        // never from Semester alone, since the same semester name can
        // legitimately repeat across different academic years for one
        // faculty.
        const semestersByFacultyId = <?= json_encode($semestersByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const offeringSemesterAcademicYearById = <?= json_encode($offeringSemesterAcademicYearById, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const offeringSemesterFacultyById = <?= json_encode($offeringSemesterFacultyById, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const departmentsByFacultyId = <?= json_encode($departmentsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const lecturersByDepartmentId = <?= json_encode($lecturersByDepartmentId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const allActiveLecturers = <?= json_encode($allActiveLecturers, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function admasCurrentOfferingFacultyId() {
            const facultySelect = document.getElementById('offeringFacultySelect');
            const departmentSelect = document.getElementById('courseDepartmentSelect');
            if (facultySelect) {
                return facultySelect.value;
            }
            return departmentSelect ? String(facultyIdByDepartmentId[departmentSelect.value] ?? '') : '';
        }

        function admasUpdateRosterDepartmentVisibility() {
            const rosterBlock = document.getElementById('offeringRosterDepartmentBlock');
            const rosterSelect = document.getElementById('offeringRosterDepartmentSelect');
            const departmentSelect = document.getElementById('courseDepartmentSelect');
            if (!rosterBlock || !rosterSelect || !departmentSelect) {
                return;
            }

            const homeFacultyId = String(facultyIdByDepartmentId[departmentSelect.value] ?? '');
            const offeringFacultyId = admasCurrentOfferingFacultyId();
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

        function admasRebuildOfferingSemesterOptions(selectedSemesterId) {
            const semesterSelect = document.getElementById('offeringSemesterSelect');
            const departmentSelect = document.getElementById('courseDepartmentSelect');
            const academicYearSelect = document.getElementById('offeringAcademicYearSelect');
            if (!semesterSelect || !departmentSelect || !academicYearSelect) {
                return;
            }

            const departmentId = departmentSelect.value;
            const academicYearId = academicYearSelect.value;
            const facultyId = admasCurrentOfferingFacultyId();
            const semesters = (facultyId !== '' && academicYearId && semestersByFacultyId[facultyId])
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
                opt.textContent = admasSemesterLabel(sem);
                semesterSelect.appendChild(opt);
            });

            semesterSelect.value = String(selectedSemesterId || '');
            if (semesterSelect.value !== String(selectedSemesterId || '')) {
                semesterSelect.value = '';
            }
            admasUpdateOfferingSemesterChange();
        }

        // Fired directly by the Department <select>'s onchange — resets the
        // Offering Faculty picker back to the newly-picked department's own
        // faculty (the user can still change it afterward to cross-list
        // into a different faculty's semester). Kept separate from
        // admasUpdateOfferingFieldsForDepartment() itself, which is also
        // called on page load to RESTORE a prior cross-faculty submission
        // and must NOT reset the faculty back to the department's own.
        function admasOnCourseDepartmentChange(departmentId) {
            const facultySelect = document.getElementById('offeringFacultySelect');
            if (facultySelect) {
                facultySelect.value = String(facultyIdByDepartmentId[departmentId] ?? '');
            }
            admasUpdateOfferingFieldsForDepartment(departmentId);
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
            admasUpdateRosterDepartmentVisibility();
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
            admasUpdateRosterDepartmentVisibility();
        }

        window.addEventListener('DOMContentLoaded', () => {
            const departmentSelect = document.getElementById('courseDepartmentSelect');
            const academicYearSelect = document.getElementById('offeringAcademicYearSelect');
            const offeringLecturerSelect = document.getElementById('offeringLecturerSelect');
            const facultySelect = document.getElementById('offeringFacultySelect');
            const rosterSelect = document.getElementById('offeringRosterDepartmentSelect');
            if (departmentSelect && document.getElementById('offeringSemesterSelect')) {
                const priorSemesterId = <?= (int) $formValues['offering_semester_id'] ?>;
                const priorRosterDepartmentId = <?= (int) $formValues['roster_department_id'] ?>;
                // Re-select whichever Academic Year that prior Semester
                // belonged to (failed-submit re-render only — Academic Year
                // itself isn't a submitted field, since course_offerings
                // only stores semester_id).
                if (academicYearSelect && priorSemesterId && offeringSemesterAcademicYearById[priorSemesterId]) {
                    academicYearSelect.value = String(offeringSemesterAcademicYearById[priorSemesterId]);
                }
                // Re-select whichever faculty that prior Semester actually
                // belonged to (may differ from the department's own faculty
                // on a failed cross-faculty submit) before rebuilding.
                if (facultySelect && priorSemesterId && offeringSemesterFacultyById[priorSemesterId] !== undefined) {
                    facultySelect.value = String(offeringSemesterFacultyById[priorSemesterId]);
                }
                admasUpdateOfferingFieldsForDepartment(departmentSelect.value, priorSemesterId);
                if (offeringLecturerSelect) {
                    offeringLecturerSelect.value = <?= json_encode((string) $formValues['offering_lecturer_id']) ?>;
                }
                if (rosterSelect && priorRosterDepartmentId) {
                    rosterSelect.value = String(priorRosterDepartmentId);
                }
            }
        });
    </script>
</body>
</html>
