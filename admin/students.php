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
    'full_name' => '',
    'email' => '',
    'academic_year_id' => 0,
    'faculty_id' => $deanFacultyId,
    'department_id' => 0,
    'level' => 1,
    'shift' => 'morning',
];

// ---------------------------------------------------------------------
// Handle POST actions: create, update, delete, reset_password
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $studentId = $action === 'update' ? (int) ($_POST['student_id'] ?? 0) : 0;
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
        // A Dean's faculty is always the session's own faculty_id — never the
        // posted value — so a crafted faculty_id cannot move/create a student
        // in a faculty they don't oversee.
        $facultyId = $role === 'dean' ? $deanFacultyId : (int) ($_POST['faculty_id'] ?? 0);
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $level = (int) ($_POST['level'] ?? 0);
        $shift = (string) ($_POST['shift'] ?? '');

        $formMode = $action === 'update' ? 'edit' : 'create';
        $formValues = [
            'id' => $studentId,
            'full_name' => $fullName,
            'email' => $email,
            'academic_year_id' => $academicYearId,
            'faculty_id' => $facultyId,
            'department_id' => $departmentId,
            'level' => $level,
            'shift' => $shift,
        ];

        $validationError = '';
        if ($fullName === '') {
            $validationError = 'Full name is required.';
        } elseif ($academicYearId <= 0) {
            $validationError = 'Please select an academic year.';
        } elseif ($facultyId <= 0) {
            $validationError = 'Please select a faculty.';
        } elseif ($departmentId <= 0) {
            $validationError = 'Please select a department.';
        } elseif ($level < 1 || $level > 5) {
            $validationError = 'Level must be between 1 and 5.';
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
                    $studentNo = generate_student_no($conn);
                    $username = generate_student_username($conn, $fullName);
                    $tempPassword = generate_temp_password();
                    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

                    $insertUserStmt = $conn->prepare(
                        'INSERT INTO users (username, password_hash, full_name, email, role_id, status) VALUES (?, ?, ?, ?, ?, "active")'
                    );
                    $insertUserStmt->bind_param('ssssi', $username, $passwordHash, $fullName, $emailParam, $studentRoleId);
                    $insertUserStmt->execute();
                    $newUserId = (int) $conn->insert_id;
                    $insertUserStmt->close();

                    $insertStudentStmt = $conn->prepare(
                        'INSERT INTO students (student_no, full_name, user_id, academic_year_id, faculty_id, department_id, level, shift, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active")'
                    );
                    $insertStudentStmt->bind_param(
                        'ssiiiiis',
                        $studentNo,
                        $fullName,
                        $newUserId,
                        $academicYearId,
                        $facultyId,
                        $departmentId,
                        $level,
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
                        'UPDATE students SET full_name = ?, academic_year_id = ?, faculty_id = ?, department_id = ?, level = ?, shift = ? WHERE id = ?'
                    );
                    $updateStudentStmt->bind_param(
                        'siiiisi',
                        $fullName,
                        $academicYearId,
                        $facultyId,
                        $departmentId,
                        $level,
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

        if ($role === 'dean') {
            $studentStmt = $conn->prepare('SELECT user_id FROM students WHERE id = ? AND faculty_id = ?');
            $studentStmt->bind_param('ii', $studentId, $deanFacultyId);
        } else {
            $studentStmt = $conn->prepare('SELECT user_id FROM students WHERE id = ?');
            $studentStmt->bind_param('i', $studentId);
        }
        $studentStmt->execute();
        $studentRow = $studentStmt->get_result()->fetch_assoc();
        $studentStmt->close();

        if (!$studentRow) {
            $errorMessage = 'Student not found.';
        } else {
            $userId = (int) $studentRow['user_id'];

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
                $errorMessage = 'Cannot delete this student: they still have ' . implode(', ', $blockers) . '.';
            } else {
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
                    $_SESSION['flash_success'] = 'Student deleted successfully. Their login account has been deactivated.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $errorMessage = 'Could not delete the student. Please try again.';
                }

                if ($errorMessage === '') {
                    redirect_to('admin/students.php');
                }
            }
        }
    } elseif ($action === 'reset_password') {
        $studentId = (int) ($_POST['student_id'] ?? 0);

        if ($role === 'dean') {
            $studentStmt = $conn->prepare(
                'SELECT s.user_id, u.username FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ? AND s.faculty_id = ?'
            );
            $studentStmt->bind_param('ii', $studentId, $deanFacultyId);
        } else {
            $studentStmt = $conn->prepare(
                'SELECT s.user_id, u.username FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?'
            );
            $studentStmt->bind_param('i', $studentId);
        }
        $studentStmt->execute();
        $studentRow = $studentStmt->get_result()->fetch_assoc();
        $studentStmt->close();

        if (!$studentRow) {
            $errorMessage = 'Student not found.';
        } else {
            $newPassword = generate_temp_password();
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $updatePassStmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
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
            'SELECT s.id, s.full_name, s.academic_year_id, s.faculty_id, s.department_id, s.level, s.shift, u.email
             FROM students s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.faculty_id = ?'
        );
        $editStmt->bind_param('ii', $editId, $deanFacultyId);
    } else {
        $editStmt = $conn->prepare(
            'SELECT s.id, s.full_name, s.academic_year_id, s.faculty_id, s.department_id, s.level, s.shift, u.email
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
            'full_name' => (string) $editRow['full_name'],
            'email' => (string) ($editRow['email'] ?? ''),
            'academic_year_id' => (int) $editRow['academic_year_id'],
            'faculty_id' => (int) $editRow['faculty_id'],
            'department_id' => (int) $editRow['department_id'],
            'level' => (int) $editRow['level'],
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
if ($filterSearch !== '') {
    $conditions[] = '(s.full_name LIKE ? OR s.student_no LIKE ?)';
    $likeParam = '%' . $filterSearch . '%';
    $params[] = $likeParam;
    $params[] = $likeParam;
    $types .= 'ss';
}

$whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

$studentsSql = "SELECT s.id, s.student_no, s.full_name, s.level, s.shift,
                       ay.label AS academic_year_label, f.name AS faculty_name, d.name AS department_name,
                       u.status AS user_status
                FROM students s
                JOIN academic_years ay ON ay.id = s.academic_year_id
                JOIN faculties f ON f.id = s.faculty_id
                JOIN departments d ON d.id = s.department_id
                JOIN users u ON u.id = s.user_id
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
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Student Management</h4>
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
                            <h6 class="fw-bold mb-0" style="color: #0b1f3a;">Students</h6>
                            <div class="d-flex gap-2">
                                <?php if ($role !== 'dean'): ?>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-file-earmark-arrow-up"></i> Import from Excel
                                    </a>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="btn btn-primary btn-sm" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                                    <i class="bi bi-plus-lg"></i> Add Student
                                </a>
                            </div>
                        </div>

                        <!-- Filter bar: real SQL WHERE filters via GET -->
                        <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="row g-2 mb-3">
                            <div class="col-sm-6 col-md-3">
                                <select class="form-select form-select-sm" name="academic_year_id">
                                    <option value="0">All Academic Years</option>
                                    <?php foreach ($academicYears as $ay): ?>
                                        <option value="<?= (int) $ay['id'] ?>" <?= $filterAcademicYearId === (int) $ay['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ay['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <?php if ($role === 'dean'): ?>
                                    <select class="form-select form-select-sm" id="filterFacultySelect" disabled onchange="updateFilterDepartmentOptions(this.value, 0)">
                                        <option value="<?= (int) $deanFacultyId ?>" selected><?= htmlspecialchars($deanFacultyName) ?></option>
                                    </select>
                                <?php else: ?>
                                    <select class="form-select form-select-sm" name="faculty_id" id="filterFacultySelect" onchange="updateFilterDepartmentOptions(this.value, 0)">
                                        <option value="0">All Faculties</option>
                                        <?php foreach ($faculties as $f): ?>
                                            <option value="<?= (int) $f['id'] ?>" <?= $filterFacultyId === (int) $f['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($f['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <select class="form-select form-select-sm" name="department_id" id="filterDepartmentSelect">
                                    <option value="0">All Departments</option>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control" name="search" placeholder="Search name or student no"
                                           value="<?= htmlspecialchars($filterSearch) ?>">
                                    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
                                </div>
                            </div>
                            <?php if ($filterAcademicYearId > 0 || $filterFacultyId > 0 || $filterDepartmentId > 0 || $filterSearch !== ''): ?>
                                <div class="col-12">
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="small">Clear filters</a>
                                </div>
                            <?php endif; ?>
                        </form>

                        <div class="table-responsive">
                            <table class="table admas-table align-middle">
                                <thead>
                                    <tr>
                                        <th>Student No</th>
                                        <th>Full Name</th>
                                        <th>Academic Year</th>
                                        <th>Faculty</th>
                                        <th>Department</th>
                                        <th>Level</th>
                                        <th>Shift</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($students)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">No students match the current filters.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($students as $s): ?>
                                            <tr>
                                                <td><span class="badge-pill badge-active"><?= htmlspecialchars($s['student_no']) ?></span></td>
                                                <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($s['full_name']) ?></td>
                                                <td><?= htmlspecialchars($s['academic_year_label']) ?></td>
                                                <td><?= htmlspecialchars($s['faculty_name']) ?></td>
                                                <td><?= htmlspecialchars($s['department_name']) ?></td>
                                                <td><?= (int) $s['level'] ?></td>
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
                        <h6 class="fw-bold mb-3" style="color: #0b1f3a;">
                            <?= $formMode === 'edit' ? 'Edit Student' : 'Add Student' ?>
                        </h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php">
                            <input type="hidden" name="action" value="<?= $formMode === 'edit' ? 'update' : 'create' ?>">
                            <?php if ($formMode === 'edit'): ?>
                                <input type="hidden" name="student_id" value="<?= (int) $formValues['id'] ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="studentFullNameInput" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="studentFullNameInput" name="full_name" maxlength="150"
                                       value="<?= htmlspecialchars($formValues['full_name']) ?>" required>
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
                                    <select class="form-select" id="studentFacultySelect" disabled onchange="updateFormDepartmentOptions(this.value, 0)">
                                        <option value="<?= (int) $deanFacultyId ?>" selected><?= htmlspecialchars($deanFacultyName) ?></option>
                                    </select>
                                    <div class="form-text">Locked to your own faculty.</div>
                                <?php else: ?>
                                    <select class="form-select" id="studentFacultySelect" name="faculty_id" required
                                            onchange="updateFormDepartmentOptions(this.value, 0)">
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
                                <label for="studentLevelSelect" class="form-label">Level</label>
                                <select class="form-select" id="studentLevelSelect" name="level" required>
                                    <?php for ($lvl = 1; $lvl <= 5; $lvl++): ?>
                                        <option value="<?= $lvl ?>" <?= (int) $formValues['level'] === $lvl ? 'selected' : '' ?>>Level <?= $lvl ?></option>
                                    <?php endfor; ?>
                                </select>
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

                            <button type="submit" class="btn btn-primary w-100" style="background-color: #0ea5e9; border-color: #0ea5e9;" <?= empty($academicYears) || empty($faculties) ? 'disabled' : '' ?>>
                                <?= $formMode === 'edit' ? 'Update Student' : 'Save Student' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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

        window.addEventListener('DOMContentLoaded', () => {
            const filterFacultyId = document.getElementById('filterFacultySelect').value;
            updateFilterDepartmentOptions(filterFacultyId, <?= (int) $filterDepartmentId ?>);

            const formFacultyId = document.getElementById('studentFacultySelect').value;
            updateFormDepartmentOptions(formFacultyId, <?= (int) $formValues['department_id'] ?>);
        });
    </script>
</body>
</html>
