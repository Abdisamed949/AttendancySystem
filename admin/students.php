<?php
/**
 * Student Management — University Rector and Registration Office (both
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
require_once __DIR__ . '/../includes/avatar_helpers.php';
require_once __DIR__ . '/../includes/audit_helpers.php';

// Registration Office also has "Add/edit students, bulk Excel import of
// students" per CLAUDE.md §4 — its scope is already university-wide
// ("All faculties"), matching this page's existing unscoped queries
// exactly, so no additional query changes are needed for that role.
// Head of Academic Affairs gets the exact same read-only "View Students
// information" access as University Rector (requested alongside the
// University Rector UI polish work) — full university-wide VIEW, no
// create/edit/delete.
require_role(['university_rector', 'head_academic', 'registration', 'dean']);

$conn = db();
$currentUser = current_user();
$role = current_role();
// University Rector + Head of Academic Affairs: full VIEW access to this
// page (all faculties/students), but no create/edit/delete/import/
// bulk-delete — supervisory/oversight role only. Dean is also read-only
// here now (converted from full CRUD to a faculty-scoped Viewer, per
// explicit request) — the existing $role === 'dean' scoping blocks below
// still narrow the list/queries to their own faculty only, this flag just
// additionally removes their write ability within that scope. Enforced
// both by hiding write UI below and by a single dispatch guard at the top
// of the POST handler further down.
$isReadOnly = in_array($role, ['university_rector', 'head_academic', 'dean'], true);
// Head of Academic Affairs and Dean are read-only for CRUD here but still
// get select-all/individual checkboxes for their own "Export Students"
// button (a non-destructive action) — every write-capable role already
// shows these same checkboxes via !$isReadOnly for bulk delete.
$showSelectCheckboxes = !$isReadOnly || $role === 'head_academic' || $role === 'dean';

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
$bulkResetResults = [];
if (!empty($_SESSION['bulk_reset_results'])) {
    $bulkResetResults = $_SESSION['bulk_reset_results'];
    unset($_SESSION['bulk_reset_results']);
}

// ---------------------------------------------------------------------
// Add / Edit side-panel form state
// ---------------------------------------------------------------------
$formMode = 'create';
// The Enrollment template's real-world field set (Downloads/"Enrollment
// (2).xlsx", the actual paper/Excel form ADMAS uses to enroll a student) —
// 'full_name' is one combined field here (not the 3 separate First/
// Father's/Grandfather's inputs this form used before), split server-side
// via split_student_full_name() into the same first_name/father_name/
// grandfather_name columns students already stores. Every new field below
// is optional (nullable in the DB) except where noted, since not every
// enrollment record will have all of them filled in at once.
$formValues = [
    'id' => 0,
    'student_no' => '',
    'full_name' => '',
    'mother_name' => '',
    'sex' => '',
    'birth_date' => '',
    'street_address' => '',
    'phone' => '',
    'email' => '',
    'emergency_contact_name' => '',
    'emergency_contact_phone' => '',
    'nationality' => '',
    'enrollment_date' => '',
    'certificate_type' => '',
    'school_roll_number' => '',
    'degree' => '',
    'academic_year_id' => 0,
    'faculty_id' => $deanFacultyId,
    'department_id' => 0,
    'program' => '',
    'semester_id' => 0,
    'class_year' => '',
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

/**
 * Shared reset-password logic — used by both the single-row "Reset" action
 * and the "Reset Selected" bulk action, so the two can never drift on
 * scoping/validation logic. Reuses the exact same "DepartmentCode-StudentNo"
 * normalization the rest of this app's Reset Password actions already use
 * (see admin/users.php / head_academic/users.php), so a pre-existing
 * name-based or department-code-based username also converges onto the
 * current scheme the moment it's reset here.
 */
function reset_student_password_row(mysqli $conn, int $studentId, string $role, int $deanFacultyId): array
{
    if ($role === 'dean') {
        $studentStmt = $conn->prepare(
            'SELECT s.user_id, s.student_no, s.full_name, u.username, d.code AS department_code
             FROM students s
             JOIN users u ON u.id = s.user_id
             JOIN departments d ON d.id = s.department_id
             WHERE s.id = ? AND s.faculty_id = ?'
        );
        $studentStmt->bind_param('ii', $studentId, $deanFacultyId);
    } else {
        $studentStmt = $conn->prepare(
            'SELECT s.user_id, s.student_no, s.full_name, u.username, d.code AS department_code
             FROM students s
             JOIN users u ON u.id = s.user_id
             JOIN departments d ON d.id = s.department_id
             WHERE s.id = ?'
        );
        $studentStmt->bind_param('i', $studentId);
    }
    $studentStmt->execute();
    $studentRow = $studentStmt->get_result()->fetch_assoc();
    $studentStmt->close();

    if (!$studentRow) {
        return ['ok' => false, 'message' => 'Student not found.'];
    }

    $newCredential = student_credential_value($conn, (string) $studentRow['department_code'], (string) $studentRow['student_no'], (int) $studentRow['user_id']);
    $newHash = password_hash($newCredential, PASSWORD_DEFAULT);

    $updatePassStmt = $conn->prepare('UPDATE users SET username = ?, password_hash = ?, must_change_password = 1 WHERE id = ?');
    $updatePassStmt->bind_param('ssi', $newCredential, $newHash, $studentRow['user_id']);
    $updatePassStmt->execute();
    $updatePassStmt->close();

    return [
        'ok' => true,
        'message' => 'Password reset for ' . $studentRow['username'] . '.',
        'student_no' => (string) $studentRow['student_no'],
        'full_name' => (string) $studentRow['full_name'],
        'old_username' => (string) $studentRow['username'],
        'new_credential' => $newCredential,
    ];
}

// ---------------------------------------------------------------------
// Handle POST actions: create, update, delete, bulk_delete, reset_password
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($isReadOnly) {
        $_SESSION['flash_error'] = 'Access scope: View only — this role cannot modify records.';
        redirect_to('admin/students.php');
    }

    if ($action === 'create' || $action === 'update') {
        $studentId = $action === 'update' ? (int) ($_POST['student_id'] ?? 0) : 0;
        $studentNo = strtoupper(trim((string) ($_POST['student_no'] ?? '')));
        $fullNameInput = trim((string) ($_POST['full_name'] ?? ''));
        $nameParts = split_student_full_name($fullNameInput);
        $firstName = $nameParts['first_name'];
        $fatherName = $nameParts['father_name'];
        $grandfatherName = $nameParts['grandfather_name'] ?? '';
        $fullName = trim($firstName . ' ' . $fatherName . ' ' . $grandfatherName);
        $motherName = trim((string) ($_POST['mother_name'] ?? ''));
        $sex = (string) ($_POST['sex'] ?? '');
        $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
        $streetAddress = trim((string) ($_POST['street_address'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $emergencyContactName = trim((string) ($_POST['emergency_contact_name'] ?? ''));
        $emergencyContactPhone = trim((string) ($_POST['emergency_contact_phone'] ?? ''));
        $nationality = trim((string) ($_POST['nationality'] ?? ''));
        $enrollmentDate = trim((string) ($_POST['enrollment_date'] ?? ''));
        $certificateType = trim((string) ($_POST['certificate_type'] ?? ''));
        $schoolRollNumber = trim((string) ($_POST['school_roll_number'] ?? ''));
        $degree = trim((string) ($_POST['degree'] ?? ''));
        $program = trim((string) ($_POST['program'] ?? ''));
        $classYear = trim((string) ($_POST['class_year'] ?? ''));
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
            'full_name' => $fullNameInput,
            'mother_name' => $motherName,
            'sex' => $sex,
            'birth_date' => $birthDate,
            'street_address' => $streetAddress,
            'phone' => $phone,
            'email' => $email,
            'emergency_contact_name' => $emergencyContactName,
            'emergency_contact_phone' => $emergencyContactPhone,
            'nationality' => $nationality,
            'enrollment_date' => $enrollmentDate,
            'certificate_type' => $certificateType,
            'school_roll_number' => $schoolRollNumber,
            'degree' => $degree,
            'academic_year_id' => $academicYearId,
            'faculty_id' => $facultyId,
            'department_id' => $departmentId,
            'program' => $program,
            'semester_id' => $semesterId,
            'class_year' => $classYear,
            'shift' => $shift,
        ];

        $validationError = '';
        if ($firstName === '' || $fatherName === '') {
            $validationError = 'Please enter the student\'s full name (at least a first name and father\'s name).';
        } elseif ($motherName === '') {
            $validationError = 'Mother\'s Name is required.';
        } elseif ($sex === '' || !in_array($sex, ['male', 'female'], true)) {
            $validationError = 'Please select a value for Sex.';
        } elseif ($birthDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            $validationError = 'Please enter a valid Birth Date.';
        } elseif ($streetAddress === '') {
            $validationError = 'Street Address is required.';
        } elseif ($phone === '') {
            $validationError = 'Student Phone is required.';
        } elseif ($email === '') {
            $validationError = 'Student Email is required.';
        } elseif ($emergencyContactName === '') {
            $validationError = 'Emergency Contact Name is required.';
        } elseif ($emergencyContactPhone === '') {
            $validationError = 'Emergency Contact Phone is required.';
        } elseif ($nationality === '') {
            $validationError = 'Nationality is required.';
        } elseif ($enrollmentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $enrollmentDate)) {
            $validationError = 'Please enter a valid Enrollment Date.';
        } elseif ($certificateType === '') {
            $validationError = 'Certificate Type is required.';
        } elseif ($schoolRollNumber === '') {
            $validationError = 'School Roll Number is required.';
        } elseif ($degree === '') {
            $validationError = 'Degree is required.';
        } elseif ($program === '') {
            $validationError = 'Program is required.';
        } elseif ($classYear === '') {
            $validationError = 'Class Year is required.';
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
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

        $departmentCode = '';
        if ($validationError === '') {
            $deptCheckStmt = $conn->prepare(
                'SELECT d.id, d.code AS department_code FROM departments d WHERE d.id = ? AND d.faculty_id = ?'
            );
            $deptCheckStmt->bind_param('ii', $departmentId, $facultyId);
            $deptCheckStmt->execute();
            $deptCheckRow = $deptCheckStmt->get_result()->fetch_assoc();
            if (!$deptCheckRow) {
                $validationError = 'Selected department does not belong to the selected faculty.';
            } else {
                $departmentCode = (string) $deptCheckRow['department_code'];
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
            $grandfatherParam = $grandfatherName !== '' ? $grandfatherName : null;
            $motherNameParam = $motherName !== '' ? $motherName : null;
            $sexParam = $sex !== '' ? $sex : null;
            $birthDateParam = $birthDate !== '' ? $birthDate : null;
            $streetAddressParam = $streetAddress !== '' ? $streetAddress : null;
            $phoneParam = $phone !== '' ? $phone : null;
            $emergencyContactNameParam = $emergencyContactName !== '' ? $emergencyContactName : null;
            $emergencyContactPhoneParam = $emergencyContactPhone !== '' ? $emergencyContactPhone : null;
            $nationalityParam = $nationality !== '' ? $nationality : null;
            $enrollmentDateParam = $enrollmentDate !== '' ? $enrollmentDate : null;
            $certificateTypeParam = $certificateType !== '' ? $certificateType : null;
            $schoolRollNumberParam = $schoolRollNumber !== '' ? $schoolRollNumber : null;
            $degreeParam = $degree !== '' ? $degree : null;
            $programParam = $program !== '' ? $program : null;
            $classYearParam = $classYear !== '' ? $classYear : null;

            if ($action === 'create') {
                $conn->begin_transaction();
                try {
                    $credentialValue = student_credential_value($conn, $departmentCode, $studentNo);
                    $username = $credentialValue;
                    $tempPassword = $credentialValue;
                    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

                    $insertUserStmt = $conn->prepare(
                        'INSERT INTO users (username, password_hash, full_name, email, role_id, status) VALUES (?, ?, ?, ?, ?, "active")'
                    );
                    $insertUserStmt->bind_param('ssssi', $username, $passwordHash, $fullName, $emailParam, $studentRoleId);
                    $insertUserStmt->execute();
                    $newUserId = (int) $conn->insert_id;
                    $insertUserStmt->close();

                    $insertStudentStmt = $conn->prepare(
                        'INSERT INTO students (
                            student_no, first_name, father_name, grandfather_name, mother_name, sex,
                            birth_date, street_address, phone, emergency_contact_name, emergency_contact_phone,
                            nationality, enrollment_date, certificate_type, school_roll_number, degree, program,
                            class_year, user_id, academic_year_id, faculty_id, department_id, semester_id, shift, status
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
                    );
                    $insertStudentStmt->bind_param(
                        'ssssssssssssssssssiiiiis',
                        $studentNo,
                        $firstName,
                        $fatherName,
                        $grandfatherParam,
                        $motherNameParam,
                        $sexParam,
                        $birthDateParam,
                        $streetAddressParam,
                        $phoneParam,
                        $emergencyContactNameParam,
                        $emergencyContactPhoneParam,
                        $nationalityParam,
                        $enrollmentDateParam,
                        $certificateTypeParam,
                        $schoolRollNumberParam,
                        $degreeParam,
                        $programParam,
                        $classYearParam,
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
                        'UPDATE students SET
                            first_name = ?, father_name = ?, grandfather_name = ?, mother_name = ?, sex = ?,
                            birth_date = ?, street_address = ?, phone = ?, emergency_contact_name = ?,
                            emergency_contact_phone = ?, nationality = ?, enrollment_date = ?, certificate_type = ?,
                            school_roll_number = ?, degree = ?, program = ?, class_year = ?,
                            academic_year_id = ?, faculty_id = ?, department_id = ?, semester_id = ?, shift = ?
                         WHERE id = ?'
                    );
                    $updateStudentStmt->bind_param(
                        'sssssssssssssssssiiiisi',
                        $firstName,
                        $fatherName,
                        $grandfatherParam,
                        $motherNameParam,
                        $sexParam,
                        $birthDateParam,
                        $streetAddressParam,
                        $phoneParam,
                        $emergencyContactNameParam,
                        $emergencyContactPhoneParam,
                        $nationalityParam,
                        $enrollmentDateParam,
                        $certificateTypeParam,
                        $schoolRollNumberParam,
                        $degreeParam,
                        $programParam,
                        $classYearParam,
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
            audit_log($conn, 'delete_student', 'student', $studentId, preg_replace('/ deleted\.?$/', '', $result['message']));
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
                audit_log($conn, 'bulk_delete', 'student', null, null, $summary);
                $_SESSION['flash_success'] = $summary;
            } else {
                $_SESSION['flash_error'] = $summary;
            }
        }
        redirect_to('admin/students.php');
    } elseif ($action === 'reset_password') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $result = reset_student_password_row($conn, $studentId, $role, $deanFacultyId);
        if ($result['ok']) {
            audit_log($conn, 'reset_password', 'student', $studentId, $result['student_no'] ?? null);
            $_SESSION['flash_success'] = $result['message']
                . ' New username and temporary password: ' . $result['new_credential']
                . ' — share this with the student now; it will not be shown again.';
            redirect_to('admin/students.php');
        } else {
            $errorMessage = $result['message'];
        }
    } elseif ($action === 'bulk_reset_password') {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['student_ids'] ?? [])),
            static fn ($id) => $id > 0
        )));

        if (empty($ids)) {
            $_SESSION['flash_error'] = 'No students were selected.';
        } else {
            $resetCount = 0;
            $skippedMessages = [];
            $resultRows = [];
            foreach ($ids as $sid) {
                $result = reset_student_password_row($conn, $sid, $role, $deanFacultyId);
                if ($result['ok']) {
                    $resetCount++;
                    $resultRows[] = [
                        'student_no' => $result['student_no'],
                        'full_name' => $result['full_name'],
                        'username' => $result['new_credential'],
                        'password' => $result['new_credential'],
                    ];
                } else {
                    $skippedMessages[] = $result['message'];
                }
            }

            $summary = $resetCount . ' of ' . count($ids) . ' selected student' . (count($ids) === 1 ? '' : 's') . ' had their password reset.';
            if (!empty($skippedMessages)) {
                $summary .= ' Skipped: ' . implode(' | ', $skippedMessages);
            }
            if ($resetCount > 0) {
                audit_log($conn, 'bulk_reset_password', 'student', null, null, $summary);
                $_SESSION['flash_success'] = $summary;
                // Shown once via the results table below, then cleared —
                // same one-time-reveal convention as every other generated-
                // credential display in this app (lecturers_import.php's
                // own results step, single reset_password above).
                $_SESSION['bulk_reset_results'] = $resultRows;
            } else {
                $_SESSION['flash_error'] = $summary;
            }
        }
        redirect_to('admin/students.php');
    }
}

// ---------------------------------------------------------------------
// GET ?edit=ID switches the side panel into edit mode (skipped if a
// failed POST above already put the form into edit mode).
// ---------------------------------------------------------------------
if ($formMode === 'create' && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $editSelect = 'SELECT s.id, s.student_no, s.full_name, s.mother_name, s.sex, s.birth_date, s.street_address,
                          s.phone, s.emergency_contact_name, s.emergency_contact_phone, s.nationality,
                          s.enrollment_date, s.certificate_type, s.school_roll_number, s.degree, s.program,
                          s.class_year, s.academic_year_id, s.faculty_id, s.department_id, s.semester_id, s.shift, u.email
                   FROM students s
                   JOIN users u ON u.id = s.user_id
                   WHERE s.id = ?';
    if ($role === 'dean') {
        $editStmt = $conn->prepare($editSelect . ' AND s.faculty_id = ?');
        $editStmt->bind_param('ii', $editId, $deanFacultyId);
    } else {
        $editStmt = $conn->prepare($editSelect);
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
            'full_name' => (string) $editRow['full_name'],
            'mother_name' => (string) ($editRow['mother_name'] ?? ''),
            'sex' => (string) ($editRow['sex'] ?? ''),
            'birth_date' => (string) ($editRow['birth_date'] ?? ''),
            'street_address' => (string) ($editRow['street_address'] ?? ''),
            'phone' => (string) ($editRow['phone'] ?? ''),
            'email' => (string) ($editRow['email'] ?? ''),
            'emergency_contact_name' => (string) ($editRow['emergency_contact_name'] ?? ''),
            'emergency_contact_phone' => (string) ($editRow['emergency_contact_phone'] ?? ''),
            'nationality' => (string) ($editRow['nationality'] ?? ''),
            'enrollment_date' => (string) ($editRow['enrollment_date'] ?? ''),
            'certificate_type' => (string) ($editRow['certificate_type'] ?? ''),
            'school_roll_number' => (string) ($editRow['school_roll_number'] ?? ''),
            'degree' => (string) ($editRow['degree'] ?? ''),
            'academic_year_id' => (int) $editRow['academic_year_id'],
            'faculty_id' => (int) $editRow['faculty_id'],
            'department_id' => (int) $editRow['department_id'],
            'program' => (string) ($editRow['program'] ?? ''),
            'semester_id' => (int) ($editRow['semester_id'] ?? 0),
            'class_year' => (string) ($editRow['class_year'] ?? ''),
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
                       u.status AS user_status, u.photo_path
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
                    Access scope: <?= htmlspecialchars($deanFacultyName) ?> Faculty only — view only
                <?php elseif ($role === 'registration'): ?>
                    Access scope: All faculties — enrollment-focused
                <?php elseif ($isReadOnly): ?>
                    Access scope: Full system — view only (oversight)<?= $role === 'head_academic' ? ' — Head of Academic Affairs' : '' ?>
                <?php else: ?>
                    Access scope: Full system — all faculties, departments, and students
                <?php endif; ?>
            </div>

            <?php if ($role === 'university_rector'): ?>
                <div class="export-card">
                    <div>
                        <p class="export-card-title"><i class="bi bi-cloud-arrow-down-fill"></i> Export Students</p>
                        <p class="export-card-sub">Download the full, university-wide student list.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/export.php?type=students&format=excel" class="btn btn-sm"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/export.php?type=students&format=pdf" class="btn btn-sm"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                    </div>
                </div>
            <?php endif; ?>

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

            <?php if (!empty($bulkResetResults)): ?>
                <div class="admas-card p-3 mb-4">
                    <div class="alert alert-warning small mb-3">
                        <i class="bi bi-exclamation-triangle"></i>
                        These temporary passwords are shown only once. Copy them now and share securely with each
                        student — they will not be shown again.
                    </div>
                    <div class="table-responsive">
                        <table class="table admas-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Student No</th>
                                    <th>Full Name</th>
                                    <th>New Username</th>
                                    <th>New Temporary Password</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bulkResetResults as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['student_no']) ?></td>
                                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                                        <td><code><?= htmlspecialchars($r['username']) ?></code></td>
                                        <td><code><?= htmlspecialchars($r['password']) ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="<?= $isReadOnly ? 'col-lg-12' : 'col-lg-8' ?>">
                    <div class="admas-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Students</h6>
                            <div class="d-flex gap-2">
                                <?php if ($role === 'head_academic' || $role === 'dean' || $role === 'registration'): ?>
                                    <form id="exportStudentsForm" method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/export.php?type=students&format=excel" class="d-inline">
                                        <div id="exportStudentsIds"></div>
                                        <button type="submit" id="exportStudentsBtn" formaction="<?= htmlspecialchars(BASE_URL) ?>/admin/export.php?type=students&format=excel" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                            <i class="bi bi-file-earmark-excel"></i> <span id="exportStudentsBtnLabel">Export All Students</span>
                                        </button>
                                        <button type="submit" formaction="<?= htmlspecialchars(BASE_URL) ?>/admin/export.php?type=students&format=pdf" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                            <i class="bi bi-file-earmark-pdf"></i> PDF
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!$isReadOnly): ?>
                                    <button type="button" id="bulkResetPasswordStudentsBtn" class="btn btn-outline-secondary btn-sm d-none">Reset Password Selected</button>
                                    <button type="button" id="bulkDeleteStudentsBtn" class="btn btn-outline-danger btn-sm d-none">Delete Selected</button>
                                    <?php if ($role !== 'dean'): ?>
                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                            <i class="bi bi-file-earmark-arrow-up"></i> Import from Excel
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-plus-lg"></i> Add Student
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$isReadOnly): ?>
                        <form id="bulkDeleteStudentsForm" method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="d-none">
                            <input type="hidden" name="action" value="bulk_delete">
                            <div id="bulkDeleteStudentsIds"></div>
                        </form>
                        <form id="bulkResetPasswordStudentsForm" method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="d-none">
                            <input type="hidden" name="action" value="bulk_reset_password">
                            <div id="bulkResetPasswordStudentsIds"></div>
                        </form>
                        <?php endif; ?>

                        <!-- Filter bar: real SQL WHERE filters via GET -->
                        <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="row g-2 mb-3" id="studentsFilterForm">
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
                                    <input type="text" class="form-control" name="search" placeholder="Search name or student no" data-live-search
                                           value="<?= htmlspecialchars($filterSearch) ?>">
                                    <button type="submit" class="btn text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"><i class="bi bi-search"></i></button>
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
                                        <?php if ($showSelectCheckboxes): ?><th><input type="checkbox" id="selectAllStudents"></th><?php endif; ?>
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
                                                <?php if ($showSelectCheckboxes): ?>
                                                <td>
                                                    <input type="checkbox" class="row-check-student" value="<?= (int) $s['id'] ?>"
                                                           data-label="<?= htmlspecialchars($s['full_name'] . ' (' . $s['student_no'] . ')') ?>">
                                                </td>
                                                <?php endif; ?>
                                                <td><span class="badge-pill badge-active"><?= htmlspecialchars($s['student_no']) ?></span></td>
                                                <td><?php render_person_avatar_cell($s['photo_path'] ?? null, (string) $s['full_name'], (string) $s['student_no']); ?></td>
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
                                                    <?php if ($isReadOnly): ?>
                                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/student_view.php?student_id=<?= (int) $s['id'] ?>" class="btn-icon-label text-sky" title="View Profile">
                                                            <i class="bi bi-eye"></i> View Profile
                                                        </a>
                                                    <?php else: ?>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php?edit=<?= (int) $s['id'] ?>" class="btn-icon-label" title="Edit">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </a>
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" style="display:inline;"
                                                          onsubmit="return confirm('Reset this student\'s password? A new temporary password will be generated.');">
                                                        <input type="hidden" name="action" value="reset_password">
                                                        <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                                                        <button type="submit" class="btn-icon-label" title="Reset Password">
                                                            <i class="bi bi-key"></i> Reset
                                                        </button>
                                                    </form>
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" style="display:inline;"
                                                          onsubmit="return confirm('Delete this student? This cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                                                        <button type="submit" class="btn-icon-label text-danger" title="Delete">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </button>
                                                    </form>
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

                <?php if (!$isReadOnly): ?>
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
                                    <div class="form-text">The student's existing admission/ID number. Combined with the Faculty code, this becomes both their initial login username and password (e.g. "INF-1472/23").</div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="studentFullNameInput" class="form-label">Student Name</label>
                                <input type="text" class="form-control" id="studentFullNameInput" name="full_name" maxlength="150"
                                       value="<?= htmlspecialchars($formValues['full_name']) ?>" required>
                                <div class="form-text">Full name (e.g. first, father's, and grandfather's name together).</div>
                            </div>

                            <div class="mb-3">
                                <label for="studentMotherNameInput" class="form-label">Mother's Name</label>
                                <input type="text" class="form-control" id="studentMotherNameInput" name="mother_name" maxlength="120"
                                       value="<?= htmlspecialchars($formValues['mother_name']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentSexSelect" class="form-label">Sex</label>
                                <select class="form-select" id="studentSexSelect" name="sex" required>
                                    <option value="">Select sex</option>
                                    <option value="male" <?= $formValues['sex'] === 'male' ? 'selected' : '' ?>>Male</option>
                                    <option value="female" <?= $formValues['sex'] === 'female' ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="studentBirthDateInput" class="form-label">Birth Date</label>
                                <input type="date" class="form-control" id="studentBirthDateInput" name="birth_date"
                                       value="<?= htmlspecialchars($formValues['birth_date']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentStreetAddressInput" class="form-label">Street Address</label>
                                <input type="text" class="form-control" id="studentStreetAddressInput" name="street_address" maxlength="255"
                                       value="<?= htmlspecialchars($formValues['street_address']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentPhoneInput" class="form-label">Student Phone</label>
                                <input type="text" class="form-control" id="studentPhoneInput" name="phone" maxlength="30"
                                       value="<?= htmlspecialchars($formValues['phone']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentEmailInput" class="form-label">Student Email</label>
                                <input type="email" class="form-control" id="studentEmailInput" name="email" maxlength="150"
                                       value="<?= htmlspecialchars($formValues['email']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentEmergencyContactNameInput" class="form-label">Emergency Contact Name</label>
                                <input type="text" class="form-control" id="studentEmergencyContactNameInput" name="emergency_contact_name" maxlength="120"
                                       value="<?= htmlspecialchars($formValues['emergency_contact_name']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentEmergencyContactPhoneInput" class="form-label">Emergency Contact Phone</label>
                                <input type="text" class="form-control" id="studentEmergencyContactPhoneInput" name="emergency_contact_phone" maxlength="30"
                                       value="<?= htmlspecialchars($formValues['emergency_contact_phone']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentNationalityInput" class="form-label">Nationality</label>
                                <input type="text" class="form-control" id="studentNationalityInput" name="nationality" maxlength="80"
                                       value="<?= htmlspecialchars($formValues['nationality']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentEnrollmentDateInput" class="form-label">Enrollment Date</label>
                                <input type="date" class="form-control" id="studentEnrollmentDateInput" name="enrollment_date"
                                       value="<?= htmlspecialchars($formValues['enrollment_date']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentCertificateTypeInput" class="form-label">Certificate Type</label>
                                <input type="text" class="form-control" id="studentCertificateTypeInput" name="certificate_type" maxlength="120"
                                       value="<?= htmlspecialchars($formValues['certificate_type']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentSchoolRollNumberInput" class="form-label">School Roll Number</label>
                                <input type="text" class="form-control" id="studentSchoolRollNumberInput" name="school_roll_number" maxlength="60"
                                       value="<?= htmlspecialchars($formValues['school_roll_number']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentDegreeInput" class="form-label">Degree</label>
                                <input type="text" class="form-control" id="studentDegreeInput" name="degree" maxlength="120"
                                       value="<?= htmlspecialchars($formValues['degree']) ?>" required>
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
                                <label for="studentProgramInput" class="form-label">Program</label>
                                <input type="text" class="form-control" id="studentProgramInput" name="program" maxlength="120"
                                       value="<?= htmlspecialchars($formValues['program']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="studentSemesterSelect" class="form-label">Semester</label>
                                <select class="form-select" id="studentSemesterSelect" name="semester_id" required>
                                    <option value="">Select semester</option>
                                </select>
                                <div class="form-text">Only semesters belonging to the selected faculty are shown.</div>
                            </div>

                            <div class="mb-3">
                                <label for="studentClassYearInput" class="form-label">Class Year</label>
                                <input type="text" class="form-control" id="studentClassYearInput" name="class_year" maxlength="30"
                                       value="<?= htmlspecialchars($formValues['class_year']) ?>" placeholder="e.g. 1st Year" required>
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
                                <div class="form-text mb-3">A username and temporary password (both "DepartmentCode-StudentNo", e.g. "IT-1472/23") will be generated automatically and shown once after saving.</div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= empty($academicYears) || empty($faculties) ? 'disabled' : '' ?>>
                                <?= $formMode === 'edit' ? 'Update Student' : 'Save Student' ?>
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
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/bulk_reset_password.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/bulk_export.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/semester_label.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/live_filter.js"></script>
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
        const allSemestersFlat = <?= json_encode(array_map(static fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'academic_year_label' => $s['academic_year_label'], 'status' => $s['status']], $semesters), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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
                opt.textContent = admasSemesterLabel(sem);
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
                opt.textContent = admasSemesterLabel(sem);
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

            const studentFacultySelectEl = document.getElementById('studentFacultySelect');
            if (studentFacultySelectEl) {
                const formFacultyId = studentFacultySelectEl.value;
                updateFormDepartmentOptions(formFacultyId, <?= (int) $formValues['department_id'] ?>);
                updateFormSemesterOptions(formFacultyId, <?= (int) $formValues['semester_id'] ?>);
            }

            admasInitLiveFilter('#studentsFilterForm');

            if (document.getElementById('bulkDeleteStudentsBtn')) {
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
            }

            if (document.getElementById('bulkResetPasswordStudentsBtn')) {
                admasInitBulkResetPassword({
                    checkboxSelector: '.row-check-student',
                    selectAllSelector: '#selectAllStudents',
                    buttonSelector: '#bulkResetPasswordStudentsBtn',
                    formSelector: '#bulkResetPasswordStudentsForm',
                    hiddenContainerSelector: '#bulkResetPasswordStudentsIds',
                    hiddenInputName: 'student_ids[]',
                    entityLabel: 'student',
                    entityLabelPlural: 'students',
                });
            }

            if (document.getElementById('exportStudentsBtn')) {
                admasInitBulkExport({
                    checkboxSelector: '.row-check-student',
                    selectAllSelector: '#selectAllStudents',
                    formSelector: '#exportStudentsForm',
                    hiddenContainerSelector: '#exportStudentsIds',
                    hiddenInputName: 'ids[]',
                    labelSelector: '#exportStudentsBtnLabel',
                    allLabel: 'Export All Students',
                    selectedLabelPrefix: 'Export Selected',
                });
            }
        });
    </script>
</body>
</html>
