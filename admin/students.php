<?php
/**
 * Student Management — System Administrator and Registration Office (both
 * university-wide / "All faculties") and Dean (own faculty only, per
 * CLAUDE.md §4 "Full CRUD on ... Students within their faculty"). Dean's
 * faculty_id is always read from $_SESSION, never trusted from request
 * input (same pattern used across attendance.php/reports.php/the other
 * admin/*.php CRUD pages).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/lecturer_accounts.php';

// Registration Office also has "Add/edit students, bulk Excel import of
// students" per CLAUDE.md §4 — its scope is already university-wide
// ("All faculties"), matching this page's existing unscoped queries
// exactly, so no additional query changes are needed for that role.
require_role(['system_admin', 'registration', 'dean']);

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

const SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

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
// Student role id
// ---------------------------------------------------------------------
$studentRoleId = 0;
$roleResult = $conn->query("SELECT id FROM roles WHERE name = 'student'");
if ($roleResult && ($roleRow = $roleResult->fetch_assoc())) {
    $studentRoleId = (int) $roleRow['id'];
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
    'student_no' => '',
    'first_name' => '',
    'father_name' => '',
    'grandfather_name' => '',
    'email' => '',
    'academic_year_id' => 0,
    'faculty_id' => $deanFacultyId,
    'department_id' => 0,
    'semester_id' => 0,
    'shift' => 'morning',
];

/**
 * Shared by both the single-row "Delete" button and the bulk "Delete
 * Selected" action so the two can never drift on blocker/validation logic.
 */
function delete_student_row(mysqli $conn, int $studentId, string $role, int $deanFacultyId): array
{
    if ($role === 'dean') {
        $studentStmt = $conn->prepare('SELECT user_id, full_name FROM students WHERE id = ? AND faculty_id = ?');
        $studentStmt->bind_param('ii', $studentId, $deanFacultyId);
    } else {
        $studentStmt = $conn->prepare('SELECT user_id, full_name FROM students WHERE id = ?');
        $studentStmt->bind_param('i', $studentId);
    }
    $studentStmt->execute();
    $studentRow = $studentStmt->get_result()->fetch_assoc();
    $studentStmt->close();

    if (!$studentRow) {
        return ['ok' => false, 'message' => 'Student not found.'];
    }

    $userId = (int) $studentRow['user_id'];
    $label = (string) $studentRow['full_name'];

    $attendanceCountStmt = $conn->prepare('SELECT COUNT(*) AS c FROM attendance WHERE student_id = ?');
    $attendanceCountStmt->bind_param('i', $studentId);
    $attendanceCountStmt->execute();
    $attendanceCount = (int) ($attendanceCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $attendanceCountStmt->close();

    $enrollmentCountStmt = $conn->prepare('SELECT COUNT(*) AS c FROM course_enrollments WHERE student_id = ?');
    $enrollmentCountStmt->bind_param('i', $studentId);
    $enrollmentCountStmt->execute();
    $enrollmentCount = (int) ($enrollmentCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $enrollmentCountStmt->close();

    $blockers = [];
    if ($attendanceCount > 0) {
        $blockers[] = $attendanceCount . ' attendance record' . ($attendanceCount === 1 ? '' : 's');
    }
    if ($enrollmentCount > 0) {
        $blockers[] = $enrollmentCount . ' course enrollment' . ($enrollmentCount === 1 ? '' : 's');
    }

    if (!empty($blockers)) {
        return ['ok' => false, 'message' => $label . ': still has ' . implode(', ', $blockers) . '.'];
    }

    $conn->begin_transaction();
    try {
        $deleteStmt = $conn->prepare('DELETE FROM students WHERE id = ?');
        $deleteStmt->bind_param('i', $studentId);
        $deleteStmt->execute();
        $deleteStmt->close();

        $deactivateStmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
        $deactivateStmt->bind_param('i', $userId);
        $deactivateStmt->execute();
        $deactivateStmt->close();

        $conn->commit();
        return ['ok' => true, 'message' => $label . ' deleted.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => $label . ': could not be deleted, please try again.'];
    }
}

// ---------------------------------------------------------------------
// Handle POST actions: create, update, delete, bulk_delete, reset_password
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $studentId = $action === 'update' ? (int) ($_POST['student_id'] ?? 0) : 0;
        $studentNo = strtoupper(trim((string) ($_POST['student_no'] ?? '')));
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $fatherName = trim((string) ($_POST['father_name'] ?? ''));
        $grandfatherName = trim((string) ($_POST['grandfather_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $fatherName . ' ' . $grandfatherName);
        $email = trim((string) ($_POST['email'] ?? ''));
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
        // A Dean's faculty is always the session's own faculty_id — never the
        // posted value — so a crafted faculty_id cannot move/create a student
        // in a faculty they don't oversee.
        $facultyId = $role === 'dean' ? $deanFacultyId : (int) ($_POST['faculty_id'] ?? 0);
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $shift = (string) ($_POST['shift'] ?? '');

        $formMode = $action === 'update' ? 'edit' : 'create';
        $existingStudentNo = '';
        if ($formMode === 'edit' && $studentId > 0) {
            $studentNoStmt = $conn->prepare('SELECT student_no FROM students WHERE id = ?');
            $studentNoStmt->bind_param('i', $studentId);
            $studentNoStmt->execute();
            $existingStudentNo = (string) ($studentNoStmt->get_result()->fetch_assoc()['student_no'] ?? '');
            $studentNoStmt->close();
        }
        $formValues = [
            'id' => $studentId,
            'student_no' => $formMode === 'edit' ? $existingStudentNo : $studentNo,
            'first_name' => $firstName,
            'father_name' => $fatherName,
            'grandfather_name' => $grandfatherName,
            'email' => $email,
            'academic_year_id' => $academicYearId,
            'faculty_id' => $facultyId,
            'department_id' => $departmentId,
            'semester_id' => $semesterId,
            'shift' => $shift,
        ];

        $validationError = '';
        if ($firstName === '' || $fatherName === '') {
            $validationError = 'First Name and Father\'s Name are required.';
        } elseif ($action === 'create' && $studentNo === '') {
            $validationError = 'Student No is required.';
        } elseif ($academicYearId <= 0) {
            $validationError = 'Please select an academic year.';
        } elseif ($facultyId <= 0) {
            $validationError = 'Please select a faculty.';
        } elseif ($departmentId <= 0) {
            $validationError = 'Please select a department.';
        } elseif ($semesterId <= 0) {
            $validationError = 'Please select a semester.';
        } elseif (!array_key_exists($shift, SHIFT_LABELS)) {
            $validationError = 'Please select a valid shift.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $validationError = 'Please enter a valid email address.';
        } elseif ($action === 'update' && $studentId <= 0) {
            $validationError = 'Invalid student selected for editing.';
        }

        if ($validationError === '') {
            $yearCheckStmt = $conn->prepare('SELECT id FROM academic_years WHERE id = ?');
            $yearCheckStmt->bind_param('i', $academicYearId);
            $yearCheckStmt->execute();
            if (!$yearCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'Selected academic year does not exist.';
            }
            $yearCheckStmt->close();
        }

        if ($validationError === '') {
            $deptCheckStmt = $conn->prepare('SELECT id FROM departments WHERE id = ? AND faculty_id = ?');
            $deptCheckStmt->bind_param('ii', $departmentId, $facultyId);
            $deptCheckStmt->execute();
            if (!$deptCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'Selected department does not belong to the selected faculty.';
            }
            $deptCheckStmt->close();
        }

        if ($validationError === '') {
            $semCheckStmt = $conn->prepare('SELECT id FROM semesters WHERE id = ? AND faculty_id = ?');
            $semCheckStmt->bind_param('ii', $semesterId, $facultyId);
            $semCheckStmt->execute();
            if (!$semCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'Selected semester does not belong to the selected faculty.';
            }
            $semCheckStmt->close();
        }

        if ($validationError === '' && $action === 'create') {
            $dupNoStmt = $conn->prepare('SELECT id FROM students WHERE UPPER(student_no) = ?');
            $dupNoStmt->bind_param('s', $studentNo);
            $dupNoStmt->execute();
            if ($dupNoStmt->get_result()->fetch_assoc()) {
                $validationError = 'A student with this Student No already exists.';
            }
            $dupNoStmt->close();
        }

        $existingUserId = 0;
        if ($validationError === '' && $action === 'update') {
            // A Dean editing an existing student must currently own them —
            // blocks a crafted student_id belonging to another faculty from
            // being "adopted" into the Dean's own faculty via this form.
            if ($role === 'dean') {
                $existingStmt = $conn->prepare('SELECT user_id FROM students WHERE id = ? AND faculty_id = ?');
                $existingStmt->bind_param('ii', $studentId, $deanFacultyId);
            } else {
                $existingStmt = $conn->prepare('SELECT user_id FROM students WHERE id = ?');
                $existingStmt->bind_param('i', $studentId);
            }
            $existingStmt->execute();
            $existingRow = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();

            if (!$existingRow) {
                $validationError = 'Invalid student selected for editing.';
            } else {
                $existingUserId = (int) $existingRow['user_id'];
            }
        }

        if ($validationError === '' && $email !== '') {
            $emailCheckStmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $emailCheckStmt->bind_param('si', $email, $existingUserId);
            $emailCheckStmt->execute();
            if ($emailCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'This email address is already used by another account.';
            }
            $emailCheckStmt->close();
        }

        if ($validationError === '') {
            $emailParam = $email !== '' ? $email : null;

            if ($action === 'create') {
                $conn->begin_transaction();
                try {
                    $username = generate_student_username($conn, $firstName, $studentNo);
                    $tempPassword = $studentNo;
                    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

                    $insertUserStmt = $conn->prepare(
                        'INSERT INTO users (username, password_hash, full_name, email, role_id, status) VALUES (?, ?, ?, ?, ?, "active")'
                    );
                    $insertUserStmt->bind_param('ssssi', $username, $passwordHash, $fullName, $emailParam, $studentRoleId);
                    $insertUserStmt->execute();
                    $newUserId = (int) $conn->insert_id;
                    $insertUserStmt->close();

                    $insertStudentStmt = $conn->prepare(
                        'INSERT INTO students (student_no, first_name, father_name, grandfather_name, user_id, academic_year_id, faculty_id, department_id, semester_id, shift, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
                    );
                    $grandfatherParam = $grandfatherName !== '' ? $grandfatherName : null;
                    $insertStudentStmt->bind_param(
                        'ssssiiiiis',
                        $studentNo,
                        $firstName,
                        $fatherName,
                        $grandfatherParam,
                        $newUserId,
                        $academicYearId,
                        $facultyId,
                        $departmentId,
                        $semesterId,
                        $shift
                    );
                    $insertStudentStmt->execute();
                    $insertStudentStmt->close();

                    $conn->commit();
                    $_SESSION['flash_success'] = 'Student added successfully. Student No: ' . $studentNo
                        . ' — Username: ' . $username
                        . ' — Temporary Password: ' . $tempPassword
                        . ' — share these credentials with the student now; the password will not be shown again.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $_SESSION['flash_error'] = 'Could not save the student. Please try again.';
                }
            } else {
                $conn->begin_transaction();
                try {
                    $updateStudentStmt = $conn->prepare(
                        'UPDATE students SET first_name = ?, father_name = ?, grandfather_name = ?, academic_year_id = ?, faculty_id = ?, department_id = ?, semester_id = ?, shift = ? WHERE id = ?'
                    );
                    $grandfatherParam = $grandfatherName !== '' ? $grandfatherName : null;
                    $updateStudentStmt->bind_param(
                        'sssiiiisi',
                        $firstName,
                        $fatherName,
                        $grandfatherParam,
                        $academicYearId,
                        $facultyId,
                        $departmentId,
                        $semesterId,
                        $shift,
                        $studentId
                    );
                    $updateStudentStmt->execute();
                    $updateStudentStmt->close();

                    $updateUserStmt = $conn->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
                    $updateUserStmt->bind_param('ssi', $fullName, $emailParam, $existingUserId);
                    $updateUserStmt->execute();
                    $updateUserStmt->close();

                    $conn->commit();
                    $_SESSION['flash_success'] = 'Student updated successfully.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $_SESSION['flash_error'] = 'Could not update the student. Please try again.';
                }
            }

            redirect_to('admin/students.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'delete') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $result = delete_student_row($conn, $studentId, $role, $deanFacultyId);
        if ($result['ok']) {
            $_SESSION['flash_success'] = 'Student deleted successfully. Their login account has been deactivated.';
            redirect_to('admin/students.php');
        } else {
            $errorMessage = $result['message'];
        }
    } elseif ($action === 'bulk_delete') {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['student_ids'] ?? [])),
            static fn ($id) => $id > 0
        )));

        if (empty($ids)) {
            $_SESSION['flash_error'] = 'No students were selected.';
        } else {
            $deletedCount = 0;
            $skippedMessages = [];
            foreach ($ids as $sid) {
                $result = delete_student_row($conn, $sid, $role, $deanFacultyId);
                if ($result['ok']) {
                    $deletedCount++;
                } else {
                    $skippedMessages[] = $result['message'];
                }
            }

            $summary = $deletedCount . ' of ' . count($ids) . ' selected student' . (count($ids) === 1 ? '' : 's') . ' deleted.';
            if (!empty($skippedMessages)) {
                $summary .= ' Skipped: ' . implode(' | ', $skippedMessages);
            }
            if ($deletedCount > 0) {
                $_SESSION['flash_success'] = $summary;
            } else {
                $_SESSION['flash_error'] = $summary;
            }
        }
        redirect_to('admin/students.php');
    } elseif ($action === 'reset_password') {
        $studentId = (int) ($_POST['student_id'] ?? 0);

        if ($role === 'dean') {
            $studentStmt = $conn->prepare(
                'SELECT s.user_id, s.student_no, u.username FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ? AND s.faculty_id = ?'
            );
            $studentStmt->bind_param('ii', $studentId, $deanFacultyId);
        } else {
            $studentStmt = $conn->prepare(
                'SELECT s.user_id, s.student_no, u.username FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?'
            );
            $studentStmt->bind_param('i', $studentId);
        }
        $studentStmt->execute();
        $studentRow = $studentStmt->get_result()->fetch_assoc();
        $studentStmt->close();

        if (!$studentRow) {
            $errorMessage = 'Student not found.';
        } else {
            $newPassword = $studentRow['student_no'];
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $updatePassStmt = $conn->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?');
            $updatePassStmt->bind_param('si', $newHash, $studentRow['user_id']);
            $updatePassStmt->execute();
            $updatePassStmt->close();

            $_SESSION['flash_success'] = 'Password reset for ' . $studentRow['username']
                . '. New temporary password: ' . $newPassword
                . ' — share this with the student now; it will not be shown again.';
            redirect_to('admin/students.php');
        }
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
            'SELECT s.id, s.student_no, s.first_name, s.father_name, s.grandfather_name, s.academic_year_id, s.faculty_id, s.department_id, s.semester_id, s.shift, u.email
             FROM students s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.faculty_id = ?'
        );
        $editStmt->bind_param('ii', $editId, $deanFacultyId);
    } else {
        $editStmt = $conn->prepare(
            'SELECT s.id, s.student_no, s.first_name, s.father_name, s.grandfather_name, s.academic_year_id, s.faculty_id, s.department_id, s.semester_id, s.shift, u.email
             FROM students s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ?'
        );
        $editStmt->bind_param('i', $editId);
    }
    $editStmt->execute();
    $editRow = $editStmt->get_result()->fetch_assoc();
    $editStmt->close();

    if ($editRow) {
        $formMode = 'edit';
        $formValues = [
            'id' => (int) $editRow['id'],
            'student_no' => (string) $editRow['student_no'],
            'first_name' => (string) $editRow['first_name'],
            'father_name' => (string) $editRow['father_name'],
            'grandfather_name' => (string) ($editRow['grandfather_name'] ?? ''),
            'email' => (string) ($editRow['email'] ?? ''),
            'academic_year_id' => (int) $editRow['academic_year_id'],
            'faculty_id' => (int) $editRow['faculty_id'],
            'department_id' => (int) $editRow['department_id'],
            'semester_id' => (int) ($editRow['semester_id'] ?? 0),
            'shift' => (string) $editRow['shift'],
        ];
    }
}

// ---------------------------------------------------------------------
// Filter bar state (real SQL WHERE filters, not client-side JS). A Dean's
// faculty filter is always their own faculty_id — never trusted from the
// querystring — so a crafted ?faculty_id=X cannot show another faculty's
// students.
// ---------------------------------------------------------------------
$filterAcademicYearId = (int) ($_GET['academic_year_id'] ?? 0);
$filterFacultyId = $role === 'dean' ? $deanFacultyId : (int) ($_GET['faculty_id'] ?? 0);
$filterDepartmentId = (int) ($_GET['department_id'] ?? 0);
$filterSemesterId = (int) ($_GET['semester_id'] ?? 0);
$filterShift = (string) ($_GET['shift'] ?? '');
if (!array_key_exists($filterShift, SHIFT_LABELS)) {
    $filterShift = '';
}
$filterSearch = trim((string) ($_GET['search'] ?? ''));

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------
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

// Semester options are scoped to the selected Faculty, same cascade
// pattern as Department — a student's semester is a position within
// their own faculty's semester track, never a university-wide 1-5 scale.
if ($role === 'dean') {
    $semStmt = $conn->prepare('SELECT id, name, faculty_id FROM semesters WHERE faculty_id = ? ORDER BY start_date DESC');
    $semStmt->bind_param('i', $deanFacultyId);
    $semStmt->execute();
    $semesters = $semStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $semStmt->close();
} else {
    $semesters = $conn->query('SELECT id, name, faculty_id FROM semesters ORDER BY start_date DESC')->fetch_all(MYSQLI_ASSOC);
}

$semestersByFacultyId = [];
foreach ($semesters as $sem) {
    $semestersByFacultyId[(int) $sem['faculty_id']][] = ['id' => (int) $sem['id'], 'name' => $sem['name']];
}

$conditions = [];
$params = [];
$types = '';

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

$whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

$studentsSql = "SELECT s.id, s.student_no, s.full_name, s.shift,
                       ay.label AS academic_year_label, f.name AS faculty_name, d.name AS department_name,
                       sem.name AS semester_name,
                       u.status AS user_status
                FROM students s
                JOIN academic_years ay ON ay.id = s.academic_year_id
                JOIN faculties f ON f.id = s.faculty_id
                JOIN departments d ON d.id = s.department_id
                JOIN users u ON u.id = s.user_id
                LEFT JOIN semesters sem ON sem.id = s.semester_id
                {$whereSql}
                ORDER BY f.name, d.name, s.full_name";

$studentsStmt = $conn->prepare($studentsSql);
if ($types !== '') {
    $studentsStmt->bind_param($types, ...$params);
}
$studentsStmt->execute();
$students = $studentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$studentsStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management — ADMAS Attendance System</title>
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
                <?php elseif ($role === 'registration'): ?>
                    Access scope: All faculties — enrollment-focused
                <?php else: ?>
                    Access scope: Full system — all faculties, departments, and students
                <?php endif; ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Student Management</h4>
                    <p class="text-muted mb-0">Create and manage student accounts.</p>
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
                            <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Students</h6>
                            <div class="d-flex gap-2">
                                <button type="button" id="bulkDeleteStudentsBtn" class="btn btn-outline-danger btn-sm d-none">Delete Selected</button>
                                <?php if ($role !== 'dean'): ?>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-file-earmark-arrow-up"></i> Import from Excel
                                    </a>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                    <i class="bi bi-plus-lg"></i> Add Student
                                </a>
                            </div>
                        </div>

                        <form id="bulkDeleteStudentsForm" method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="d-none">
                            <input type="hidden" name="action" value="bulk_delete">
                            <div id="bulkDeleteStudentsIds"></div>
                        </form>

                        <!-- Filter bar: real SQL WHERE filters via GET -->
                        <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="row g-2 mb-3">
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
                                    <?php foreach (SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                        <option value="<?= htmlspecialchars($shiftValue) ?>" <?= $filterShift === $shiftValue ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($shiftLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-2">
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control" name="search" placeholder="Search name or student no"
                                           value="<?= htmlspecialchars($filterSearch) ?>">
                                    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
                                </div>
                            </div>
                            <?php if ($filterAcademicYearId > 0 || $filterFacultyId > 0 || $filterDepartmentId > 0 || $filterSemesterId > 0 || $filterShift !== '' || $filterSearch !== ''): ?>
                                <div class="col-12">
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="small">Clear filters</a>
                                </div>
                            <?php endif; ?>
                        </form>

                        <div class="table-responsive">
                            <table class="table admas-table align-middle">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllStudents"></th>
                                        <th>Student No</th>
                                        <th>Full Name</th>
                                        <th>Academic Year</th>
                                        <th>Faculty</th>
                                        <th>Department</th>
                                        <th>Semester</th>
                                        <th>Shift</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($students)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No students match the current filters.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($students as $s): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="row-check-student" value="<?= (int) $s['id'] ?>"
                                                           data-label="<?= htmlspecialchars($s['full_name'] . ' (' . $s['student_no'] . ')') ?>">
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
                                                <td><?= htmlspecialchars(SHIFT_LABELS[$s['shift']] ?? $s['shift']) ?></td>
                                                <td>
                                                    <?php if ($s['user_status'] === 'active'): ?>
                                                        <span class="badge-pill badge-active">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill badge-inactive">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php?edit=<?= (int) $s['id'] ?>" class="btn-icon" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" style="display:inline;"
                                                          onsubmit="return confirm('Reset this student\'s password? A new temporary password will be generated.');">
                                                        <input type="hidden" name="action" value="reset_password">
                                                        <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                                                        <button type="submit" class="btn-icon" title="Reset Password">
                                                            <i class="bi bi-key"></i>
                                                        </button>
                                                    </form>
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" style="display:inline;"
                                                          onsubmit="return confirm('Delete this student? This cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
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
                            <?= $formMode === 'edit' ? 'Edit Student' : 'Add Student' ?>
                        </h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php">
                            <input type="hidden" name="action" value="<?= $formMode === 'edit' ? 'update' : 'create' ?>">
                            <?php if ($formMode === 'edit'): ?>
                                <input type="hidden" name="student_id" value="<?= (int) $formValues['id'] ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="studentNoInput" class="form-label">Student No</label>
                                <?php if ($formMode === 'edit'): ?>
                                    <input type="text" class="form-control text-uppercase" value="<?= htmlspecialchars($formValues['student_no']) ?>" disabled>
                                    <div class="form-text">Student No cannot be changed after creation.</div>
                                <?php else: ?>
                                    <input type="text" class="form-control text-uppercase" id="studentNoInput" name="student_no" maxlength="20"
                                           value="<?= htmlspecialchars($formValues['student_no']) ?>" required>
                                    <div class="form-text">The student's existing admission/ID number. This becomes their login username base and initial password.</div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="studentFirstNameInput" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="studentFirstNameInput" name="first_name" maxlength="60"
                                       value="<?= htmlspecialchars($formValues['first_name']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentFatherNameInput" class="form-label">Father's Name</label>
                                <input type="text" class="form-control" id="studentFatherNameInput" name="father_name" maxlength="60"
                                       value="<?= htmlspecialchars($formValues['father_name']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentGrandfatherNameInput" class="form-label">Grandfather's Name <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" class="form-control" id="studentGrandfatherNameInput" name="grandfather_name" maxlength="60"
                                       value="<?= htmlspecialchars($formValues['grandfather_name']) ?>">
                            </div>

                            <div class="mb-3">
                                <label for="studentEmailInput" class="form-label">Email</label>
                                <input type="email" class="form-control" id="studentEmailInput" name="email" maxlength="150"
                                       value="<?= htmlspecialchars($formValues['email']) ?>">
                            </div>

                            <div class="mb-3">
                                <label for="studentAcademicYearSelect" class="form-label">Academic Year</label>
                                <select class="form-select" id="studentAcademicYearSelect" name="academic_year_id" required>
                                    <option value="">Select academic year</option>
                                    <?php foreach ($academicYears as $ay): ?>
                                        <option value="<?= (int) $ay['id'] ?>" <?= (int) $formValues['academic_year_id'] === (int) $ay['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ay['label']) ?><?= $ay['is_current'] ? ' (current)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($academicYears)): ?>
                                    <div class="form-text text-danger">No academic years exist yet.</div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="studentFacultySelect" class="form-label">Faculty</label>
                                <?php if ($role === 'dean'): ?>
                                    <select class="form-select" id="studentFacultySelect" disabled onchange="updateFormDepartmentOptions(this.value, 0); updateFormSemesterOptions(this.value, 0);">
                                        <option value="<?= (int) $deanFacultyId ?>" selected><?= htmlspecialchars($deanFacultyName) ?></option>
                                    </select>
                                    <div class="form-text">Locked to your own faculty.</div>
                                <?php else: ?>
                                    <select class="form-select" id="studentFacultySelect" name="faculty_id" required
                                            onchange="updateFormDepartmentOptions(this.value, 0); updateFormSemesterOptions(this.value, 0);">
                                        <option value="">Select faculty</option>
                                        <?php foreach ($faculties as $f): ?>
                                            <option value="<?= (int) $f['id'] ?>" <?= (int) $formValues['faculty_id'] === (int) $f['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($f['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="studentDepartmentSelect" class="form-label">Department</label>
                                <select class="form-select" id="studentDepartmentSelect" name="department_id" required>
                                    <option value="">Select department</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="studentSemesterSelect" class="form-label">Semester</label>
                                <select class="form-select" id="studentSemesterSelect" name="semester_id" required>
                                    <option value="">Select semester</option>
                                </select>
                                <div class="form-text">Only semesters belonging to the selected faculty are shown.</div>
                            </div>

                            <div class="mb-3">
                                <label for="studentShiftSelect" class="form-label">Shift</label>
                                <select class="form-select" id="studentShiftSelect" name="shift" required>
                                    <?php foreach (SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                        <option value="<?= htmlspecialchars($shiftValue) ?>" <?= $formValues['shift'] === $shiftValue ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($shiftLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if ($formMode === 'create'): ?>
                                <div class="form-text mb-3">A student number, username, and temporary password will be generated automatically and shown once after saving.</div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= empty($academicYears) || empty($faculties) ? 'disabled' : '' ?>>
                                <?= $formMode === 'edit' ? 'Update Student' : 'Save Student' ?>
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
        const departmentsByFacultyId = <?= json_encode($departmentsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const allDepartmentsFlat = <?= json_encode(array_map(static fn ($d) => ['id' => (int) $d['id'], 'name' => $d['name']], $departments), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function buildDepartmentOptions(select, facultyId, selectedDepartmentId, allLabel, fallbackToAll) {
            let departments = departmentsByFacultyId[facultyId] || [];
            if (departments.length === 0 && fallbackToAll && (!facultyId || facultyId === '0')) {
                departments = allDepartmentsFlat;
            }
            select.innerHTML = '';

            const allOption = document.createElement('option');
            allOption.value = '0';
            allOption.textContent = allLabel;
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

        function updateFilterDepartmentOptions(facultyId, selectedDepartmentId) {
            buildDepartmentOptions(document.getElementById('filterDepartmentSelect'), facultyId, selectedDepartmentId, 'All Departments', true);
        }

        function updateFormDepartmentOptions(facultyId, selectedDepartmentId) {
            buildDepartmentOptions(document.getElementById('studentDepartmentSelect'), facultyId, selectedDepartmentId, 'Select department', false);
        }

        const semestersByFacultyId = <?= json_encode($semestersByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const allSemestersFlat = <?= json_encode(array_map(static fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name']], $semesters), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function updateFilterSemesterOptions(facultyId, selectedSemesterId) {
            const select = document.getElementById('filterSemesterSelect');
            let semesters = semestersByFacultyId[facultyId] || [];
            if (semesters.length === 0 && (!facultyId || facultyId === '0')) {
                semesters = allSemestersFlat;
            }
            select.innerHTML = '';

            const allOption = document.createElement('option');
            allOption.value = '0';
            allOption.textContent = 'All Semesters';
            select.appendChild(allOption);

            semesters.forEach((sem) => {
                const opt = document.createElement('option');
                opt.value = String(sem.id);
                opt.textContent = sem.name;
                select.appendChild(opt);
            });

            select.value = String(selectedSemesterId || 0);
            if (select.value !== String(selectedSemesterId || 0)) {
                select.value = '0';
            }
        }

        function updateFormSemesterOptions(facultyId, selectedSemesterId) {
            const select = document.getElementById('studentSemesterSelect');
            const semesters = semestersByFacultyId[facultyId] || [];
            select.innerHTML = '';

            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = semesters.length === 0 ? 'No semesters for this faculty yet' : 'Select semester';
            select.appendChild(blank);

            semesters.forEach((sem) => {
                const opt = document.createElement('option');
                opt.value = String(sem.id);
                opt.textContent = sem.name;
                select.appendChild(opt);
            });

            select.value = String(selectedSemesterId || '');
            if (select.value !== String(selectedSemesterId || '')) {
                select.value = '';
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const filterFacultyId = document.getElementById('filterFacultySelect').value;
            updateFilterDepartmentOptions(filterFacultyId, <?= (int) $filterDepartmentId ?>);
            updateFilterSemesterOptions(filterFacultyId, <?= (int) $filterSemesterId ?>);

            const formFacultyId = document.getElementById('studentFacultySelect').value;
            updateFormDepartmentOptions(formFacultyId, <?= (int) $formValues['department_id'] ?>);
            updateFormSemesterOptions(formFacultyId, <?= (int) $formValues['semester_id'] ?>);

            admasInitBulkDelete({
                checkboxSelector: '.row-check-student',
                selectAllSelector: '#selectAllStudents',
                buttonSelector: '#bulkDeleteStudentsBtn',
                formSelector: '#bulkDeleteStudentsForm',
                hiddenContainerSelector: '#bulkDeleteStudentsIds',
                hiddenInputName: 'student_ids[]',
                entityLabel: 'student',
                entityLabelPlural: 'students',
            });
        });
    </script>
</body>
</html>
