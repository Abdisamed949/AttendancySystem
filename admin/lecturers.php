<?php
/**
 * Lecturer Management — University Rector (all faculties) and Dean (own
 * faculty only, per CLAUDE.md §4). Dean's faculty_id is always read from
 * $_SESSION, never trusted from request input (same pattern used across
 * attendance.php/reports.php/admin/departments.php/admin/courses.php).
 *
 * Head of Academic Affairs is deliberately NOT included: CLAUDE.md §4 only
 * grants that role "register new Lecturer accounts" (its own form on
 * head_academic/dashboard.php), not full CRUD here — includes/nav_items.php
 * no longer lists "Lecturers" for that role either, so the two now agree.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/lecturer_accounts.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['university_rector', 'dean']);

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
// Lecturer role id
// ---------------------------------------------------------------------
$lecturerRoleId = 0;
$roleResult = $conn->query("SELECT id FROM roles WHERE name = 'lecturer'");
if ($roleResult && ($roleRow = $roleResult->fetch_assoc())) {
    $lecturerRoleId = (int) $roleRow['id'];
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
$formValues = ['id' => 0, 'staff_no' => '', 'full_name' => '', 'email' => '', 'department_id' => 0];

/**
 * Shared by both the single-row "Delete" button and the bulk "Delete
 * Selected" action so the two can never drift on blocker/validation logic.
 */
function delete_lecturer_row(mysqli $conn, int $lecturerId, string $role, int $deanFacultyId): array
{
    if ($role === 'dean') {
        $lecturerStmt = $conn->prepare(
            'SELECT l.user_id, l.full_name FROM lecturers l JOIN departments d ON d.id = l.department_id WHERE l.id = ? AND d.faculty_id = ?'
        );
        $lecturerStmt->bind_param('ii', $lecturerId, $deanFacultyId);
    } else {
        $lecturerStmt = $conn->prepare('SELECT user_id, full_name FROM lecturers WHERE id = ?');
        $lecturerStmt->bind_param('i', $lecturerId);
    }
    $lecturerStmt->execute();
    $lecturerRow = $lecturerStmt->get_result()->fetch_assoc();
    $lecturerStmt->close();

    if (!$lecturerRow) {
        return ['ok' => false, 'message' => 'Lecturer not found.'];
    }

    $userId = (int) $lecturerRow['user_id'];
    $label = (string) $lecturerRow['full_name'];

    // Current-offering-only: a lecturer whose only assignments are
    // past/historical semesters doesn't block deletion — only an
    // active, current-semester teaching assignment does.
    $courseCountStmt = $conn->prepare(
        "SELECT COUNT(*) AS c
         FROM course_offerings co
         JOIN courses c ON c.id = co.course_id
         JOIN departments d ON d.id = c.department_id
         JOIN semesters se ON se.id = co.semester_id AND se.faculty_id = d.faculty_id AND se.is_current = 1
         WHERE co.lecturer_id = ?"
    );
    $courseCountStmt->bind_param('i', $lecturerId);
    $courseCountStmt->execute();
    $courseCount = (int) ($courseCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $courseCountStmt->close();

    $attendanceCountStmt = $conn->prepare('SELECT COUNT(*) AS c FROM attendance WHERE recorded_by_user_id = ?');
    $attendanceCountStmt->bind_param('i', $userId);
    $attendanceCountStmt->execute();
    $attendanceCount = (int) ($attendanceCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $attendanceCountStmt->close();

    $blockers = [];
    if ($courseCount > 0) {
        $blockers[] = $courseCount . ' course' . ($courseCount === 1 ? '' : 's') . ' assigned';
    }
    if ($attendanceCount > 0) {
        $blockers[] = $attendanceCount . ' attendance record' . ($attendanceCount === 1 ? '' : 's') . ' recorded by them';
    }

    if (!empty($blockers)) {
        return ['ok' => false, 'message' => $label . ': still has ' . implode(', ', $blockers) . '.'];
    }

    $conn->begin_transaction();
    try {
        $deleteStmt = $conn->prepare('DELETE FROM lecturers WHERE id = ?');
        $deleteStmt->bind_param('i', $lecturerId);
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

    if ($isReadOnly) {
        $_SESSION['flash_error'] = 'Access scope: View only — this role cannot modify records.';
        redirect_to('admin/lecturers.php');
    }

    if ($action === 'create' || $action === 'update') {
        $lecturerId = $action === 'update' ? (int) ($_POST['lecturer_id'] ?? 0) : 0;
        $staffNo = strtoupper(trim((string) ($_POST['staff_no'] ?? '')));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $departmentId = (int) ($_POST['department_id'] ?? 0);

        $formMode = $action === 'update' ? 'edit' : 'create';
        $existingStaffNo = '';
        if ($formMode === 'edit' && $lecturerId > 0) {
            $staffNoStmt = $conn->prepare('SELECT staff_no FROM lecturers WHERE id = ?');
            $staffNoStmt->bind_param('i', $lecturerId);
            $staffNoStmt->execute();
            $existingStaffNo = (string) ($staffNoStmt->get_result()->fetch_assoc()['staff_no'] ?? '');
            $staffNoStmt->close();
        }
        $formValues = [
            'id' => $lecturerId,
            'staff_no' => $formMode === 'edit' ? $existingStaffNo : $staffNo,
            'full_name' => $fullName,
            'email' => $email,
            'department_id' => $departmentId,
        ];

        $validationError = '';
        if ($fullName === '') {
            $validationError = 'Full name is required.';
        } elseif ($action === 'create' && $staffNo === '') {
            $validationError = 'Staff No is required.';
        } elseif ($departmentId <= 0) {
            $validationError = 'Please select a department.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $validationError = 'Please enter a valid email address.';
        } elseif ($action === 'update' && $lecturerId <= 0) {
            $validationError = 'Invalid lecturer selected for editing.';
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

        // A Dean editing an existing lecturer must currently own them (i.e.
        // their current department is inside the Dean's faculty).
        if ($validationError === '' && $role === 'dean' && $action === 'update') {
            $ownCheckStmt = $conn->prepare(
                'SELECT l.id FROM lecturers l JOIN departments d ON d.id = l.department_id WHERE l.id = ? AND d.faculty_id = ?'
            );
            $ownCheckStmt->bind_param('ii', $lecturerId, $deanFacultyId);
            $ownCheckStmt->execute();
            if (!$ownCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'Invalid lecturer selected for editing.';
            }
            $ownCheckStmt->close();
        }

        if ($validationError === '' && $action === 'create') {
            $dupStaffStmt = $conn->prepare('SELECT id FROM lecturers WHERE UPPER(staff_no) = ?');
            $dupStaffStmt->bind_param('s', $staffNo);
            $dupStaffStmt->execute();
            if ($dupStaffStmt->get_result()->fetch_assoc()) {
                $validationError = 'A lecturer with this staff number already exists.';
            }
            $dupStaffStmt->close();
        }

        $existingUserId = 0;
        if ($validationError === '' && $action === 'update') {
            $existingStmt = $conn->prepare('SELECT user_id FROM lecturers WHERE id = ?');
            $existingStmt->bind_param('i', $lecturerId);
            $existingStmt->execute();
            $existingRow = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();

            if (!$existingRow) {
                $validationError = 'Invalid lecturer selected for editing.';
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
                    $username = generate_lecturer_username($conn, $fullName, $staffNo);
                    $tempPassword = $staffNo;
                    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

                    $insertUserStmt = $conn->prepare(
                        'INSERT INTO users (username, password_hash, full_name, email, role_id, status) VALUES (?, ?, ?, ?, ?, "active")'
                    );
                    $insertUserStmt->bind_param('ssssi', $username, $passwordHash, $fullName, $emailParam, $lecturerRoleId);
                    $insertUserStmt->execute();
                    $newUserId = (int) $conn->insert_id;
                    $insertUserStmt->close();

                    $insertLecturerStmt = $conn->prepare(
                        'INSERT INTO lecturers (staff_no, full_name, user_id, department_id, status) VALUES (?, ?, ?, ?, "active")'
                    );
                    $insertLecturerStmt->bind_param('ssii', $staffNo, $fullName, $newUserId, $departmentId);
                    $insertLecturerStmt->execute();
                    $insertLecturerStmt->close();

                    $conn->commit();
                    $_SESSION['flash_success'] = 'Lecturer added successfully. Staff No: ' . $staffNo
                        . ' — Username: ' . $username
                        . ' — Temporary Password: ' . $tempPassword
                        . ' — share these credentials with the lecturer now; the password will not be shown again.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $_SESSION['flash_error'] = 'Could not save the lecturer. Please try again.';
                }
            } else {
                $conn->begin_transaction();
                try {
                    $updateLecturerStmt = $conn->prepare('UPDATE lecturers SET full_name = ?, department_id = ? WHERE id = ?');
                    $updateLecturerStmt->bind_param('sii', $fullName, $departmentId, $lecturerId);
                    $updateLecturerStmt->execute();
                    $updateLecturerStmt->close();

                    $updateUserStmt = $conn->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
                    $updateUserStmt->bind_param('ssi', $fullName, $emailParam, $existingUserId);
                    $updateUserStmt->execute();
                    $updateUserStmt->close();

                    $conn->commit();
                    $_SESSION['flash_success'] = 'Lecturer updated successfully.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $_SESSION['flash_error'] = 'Could not update the lecturer. Please try again.';
                }
            }

            redirect_to('admin/lecturers.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'delete') {
        $lecturerId = (int) ($_POST['lecturer_id'] ?? 0);
        $result = delete_lecturer_row($conn, $lecturerId, $role, $deanFacultyId);
        if ($result['ok']) {
            $_SESSION['flash_success'] = 'Lecturer deleted successfully. Their login account has been deactivated.';
            redirect_to('admin/lecturers.php');
        } else {
            $errorMessage = $result['message'];
        }
    } elseif ($action === 'bulk_delete') {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['lecturer_ids'] ?? [])),
            static fn ($id) => $id > 0
        )));

        if (empty($ids)) {
            $_SESSION['flash_error'] = 'No lecturers were selected.';
        } else {
            $deletedCount = 0;
            $skippedMessages = [];
            foreach ($ids as $lid) {
                $result = delete_lecturer_row($conn, $lid, $role, $deanFacultyId);
                if ($result['ok']) {
                    $deletedCount++;
                } else {
                    $skippedMessages[] = $result['message'];
                }
            }

            $summary = $deletedCount . ' of ' . count($ids) . ' selected lecturer' . (count($ids) === 1 ? '' : 's') . ' deleted.';
            if (!empty($skippedMessages)) {
                $summary .= ' Skipped: ' . implode(' | ', $skippedMessages);
            }
            if ($deletedCount > 0) {
                $_SESSION['flash_success'] = $summary;
            } else {
                $_SESSION['flash_error'] = $summary;
            }
        }
        redirect_to('admin/lecturers.php');
    } elseif ($action === 'reset_password') {
        $lecturerId = (int) ($_POST['lecturer_id'] ?? 0);

        if ($role === 'dean') {
            $lecturerStmt = $conn->prepare(
                'SELECT l.user_id, l.staff_no, u.username FROM lecturers l
                 JOIN users u ON u.id = l.user_id
                 JOIN departments d ON d.id = l.department_id
                 WHERE l.id = ? AND d.faculty_id = ?'
            );
            $lecturerStmt->bind_param('ii', $lecturerId, $deanFacultyId);
        } else {
            $lecturerStmt = $conn->prepare(
                'SELECT l.user_id, l.staff_no, u.username FROM lecturers l JOIN users u ON u.id = l.user_id WHERE l.id = ?'
            );
            $lecturerStmt->bind_param('i', $lecturerId);
        }
        $lecturerStmt->execute();
        $lecturerRow = $lecturerStmt->get_result()->fetch_assoc();
        $lecturerStmt->close();

        if (!$lecturerRow) {
            $errorMessage = 'Lecturer not found.';
        } else {
            $newPassword = $lecturerRow['staff_no'];
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $updatePassStmt = $conn->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?');
            $updatePassStmt->bind_param('si', $newHash, $lecturerRow['user_id']);
            $updatePassStmt->execute();
            $updatePassStmt->close();

            $_SESSION['flash_success'] = 'Password reset for ' . $lecturerRow['username']
                . '. New temporary password: ' . $newPassword
                . ' — share this with the lecturer now; it will not be shown again.';
            redirect_to('admin/lecturers.php');
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
            'SELECT l.id, l.staff_no, l.full_name, l.department_id, u.email
             FROM lecturers l
             JOIN users u ON u.id = l.user_id
             JOIN departments d ON d.id = l.department_id
             WHERE l.id = ? AND d.faculty_id = ?'
        );
        $editStmt->bind_param('ii', $editId, $deanFacultyId);
    } else {
        $editStmt = $conn->prepare(
            'SELECT l.id, l.staff_no, l.full_name, l.department_id, u.email
             FROM lecturers l
             JOIN users u ON u.id = l.user_id
             WHERE l.id = ?'
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
            'staff_no' => (string) $editRow['staff_no'],
            'full_name' => (string) $editRow['full_name'],
            'email' => (string) ($editRow['email'] ?? ''),
            'department_id' => (int) $editRow['department_id'],
        ];
    }
}

// ---------------------------------------------------------------------
// Data for rendering — scoped to the Dean's own faculty when applicable
// (never trusted from request input).
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

    $lecStmt = $conn->prepare(
        "SELECT l.id, l.staff_no, l.full_name, l.department_id,
                d.name AS department_name, f.name AS faculty_name,
                u.username, u.status AS user_status,
                (SELECT COUNT(DISTINCT co.course_id) FROM course_offerings co WHERE co.lecturer_id = l.id) AS course_count
         FROM lecturers l
         JOIN departments d ON d.id = l.department_id
         JOIN faculties f ON f.id = d.faculty_id
         JOIN users u ON u.id = l.user_id
         WHERE d.faculty_id = ?
         ORDER BY d.name, l.full_name"
    );
    $lecStmt->bind_param('i', $deanFacultyId);
    $lecStmt->execute();
    $lecturers = $lecStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lecStmt->close();
} else {
    $departments = $conn->query(
        "SELECT d.id, d.code, d.name, d.faculty_id, f.name AS faculty_name
         FROM departments d
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name"
    )->fetch_all(MYSQLI_ASSOC);

    $lecturers = $conn->query(
        "SELECT l.id, l.staff_no, l.full_name, l.department_id,
                d.name AS department_name, f.name AS faculty_name,
                u.username, u.status AS user_status,
                (SELECT COUNT(DISTINCT co.course_id) FROM course_offerings co WHERE co.lecturer_id = l.id) AS course_count
         FROM lecturers l
         JOIN departments d ON d.id = l.department_id
         JOIN faculties f ON f.id = d.faculty_id
         JOIN users u ON u.id = l.user_id
         ORDER BY f.name, d.name, l.full_name"
    )->fetch_all(MYSQLI_ASSOC);
}

$departmentsByFaculty = [];
foreach ($departments as $dept) {
    $departmentsByFaculty[$dept['faculty_name']][] = $dept;
}

// ---------------------------------------------------------------------
// Teaching-setup completeness, mirroring admin/course_offerings.php's own
// Complete/Incomplete badge: a lecturer counts as "Complete" only once they
// have at least one CURRENT-semester course assignment AND that
// assignment's real roster (enrollment-then-department-fallback, same
// resolution attendance.php/reports.php use) has at least one student —
// "assigned to teach nothing this semester" or "assigned but empty
// roster" both show as Incomplete rather than silently looking fine.
// ---------------------------------------------------------------------
$lecturerIds = array_map(static fn ($l) => (int) $l['id'], $lecturers);
$currentOfferingsByLecturer = [];
if (!empty($lecturerIds)) {
    $idPlaceholders = implode(',', array_fill(0, count($lecturerIds), '?'));
    $curOffStmt = $conn->prepare(
        "SELECT co.lecturer_id, co.course_id, co.semester_id, co.shift
         FROM course_offerings co
         JOIN semesters se ON se.id = co.semester_id AND se.is_current = 1
         WHERE co.lecturer_id IN ({$idPlaceholders})"
    );
    $curOffStmt->bind_param(str_repeat('i', count($lecturerIds)), ...$lecturerIds);
    $curOffStmt->execute();
    $curOffRes = $curOffStmt->get_result();
    while ($row = $curOffRes->fetch_assoc()) {
        $currentOfferingsByLecturer[(int) $row['lecturer_id']][] = $row;
    }
    $curOffStmt->close();
}

$studentCountByLecturerId = [];
$isCompleteByLecturerId = [];
foreach ($lecturerIds as $lid) {
    $offeringsForLecturer = $currentOfferingsByLecturer[$lid] ?? [];
    $studentCount = 0;
    foreach ($offeringsForLecturer as $off) {
        $shiftFilter = ($off['shift'] !== null && $off['shift'] !== 'any') ? $off['shift'] : null;
        $studentCount += get_course_roster_count($conn, (int) $off['course_id'], (int) $off['semester_id'], $shiftFilter);
    }
    $studentCountByLecturerId[$lid] = $studentCount;
    $isCompleteByLecturerId[$lid] = !empty($offeringsForLecturer) && $studentCount > 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Management — ADMAS Attendance System</title>
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
                    Access scope: Full system — all faculties, departments, and lecturers
                <?php endif; ?>
            </div>

            <?php if ($role === 'university_rector'): ?>
                <div class="export-card">
                    <div>
                        <p class="export-card-title"><i class="bi bi-cloud-arrow-down-fill"></i> Export Lecturers</p>
                        <p class="export-card-sub">Download the full, university-wide lecturer list.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/export.php?type=lecturers&format=excel" class="btn btn-sm"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/export.php?type=lecturers&format=pdf" class="btn btn-sm"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Lecturer Management</h4>
                    <p class="text-muted mb-0">Create and manage lecturer accounts.</p>
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
                            <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Lecturers</h6>
                            <div class="d-flex gap-2">
                                <?php if (!$isReadOnly): ?>
                                    <button type="button" id="bulkDeleteLecturersBtn" class="btn btn-outline-danger btn-sm d-none">Delete Selected</button>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers_import.php" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-file-earmark-arrow-up"></i> Import from Excel
                                    </a>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-plus-lg"></i> Add Lecturer
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$isReadOnly): ?>
                        <form id="bulkDeleteLecturersForm" method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php" class="d-none">
                            <input type="hidden" name="action" value="bulk_delete">
                            <div id="bulkDeleteLecturersIds"></div>
                        </form>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table admas-table align-middle">
                                <thead>
                                    <tr>
                                        <?php if (!$isReadOnly): ?><th><input type="checkbox" id="selectAllLecturers"></th><?php endif; ?>
                                        <th>Staff No</th>
                                        <th>Full Name</th>
                                        <th>Department</th>
                                        <th>Faculty</th>
                                        <th># Courses</th>
                                        <th>Students</th>
                                        <th>Status</th>
                                        <th>Setup</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($lecturers)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No lecturers have been created yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($lecturers as $l): ?>
                                            <tr>
                                                <?php if (!$isReadOnly): ?>
                                                <td>
                                                    <input type="checkbox" class="row-check-lecturer" value="<?= (int) $l['id'] ?>"
                                                           data-label="<?= htmlspecialchars($l['full_name'] . ' (' . $l['staff_no'] . ')') ?>">
                                                </td>
                                                <?php endif; ?>
                                                <td><span class="badge-pill badge-active"><?= htmlspecialchars($l['staff_no']) ?></span></td>
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($l['full_name']) ?></td>
                                                <td><?= htmlspecialchars($l['department_name']) ?></td>
                                                <td><?= htmlspecialchars($l['faculty_name']) ?></td>
                                                <td><?= number_format((int) $l['course_count']) ?></td>
                                                <td><i class="bi bi-people-fill text-muted"></i> <?= number_format($studentCountByLecturerId[(int) $l['id']] ?? 0) ?></td>
                                                <td>
                                                    <?php if ($l['user_status'] === 'active'): ?>
                                                        <span class="badge-pill badge-active"><i class="bi bi-check-circle-fill"></i> Active</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill badge-inactive"><i class="bi bi-slash-circle-fill"></i> Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($isCompleteByLecturerId[(int) $l['id']] ?? false): ?>
                                                        <span class="badge-pill badge-active"><i class="bi bi-check-circle-fill"></i> Complete</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill badge-warning" title="No current course assignment with enrolled students yet"><i class="bi bi-exclamation-triangle-fill"></i> Incomplete</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($isReadOnly): ?>
                                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturer_view.php?lecturer_id=<?= (int) $l['id'] ?>" class="btn-icon-label text-sky" title="View Profile">
                                                            <i class="bi bi-eye"></i> View Profile
                                                        </a>
                                                    <?php else: ?>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/lecturer_courses.php?lecturer_id=<?= (int) $l['id'] ?>" class="btn-icon" title="Assign Courses">
                                                        <i class="bi bi-journal-plus"></i>
                                                    </a>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php?edit=<?= (int) $l['id'] ?>" class="btn-icon" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php" style="display:inline;"
                                                          onsubmit="return confirm('Reset this lecturer\'s password? A new temporary password will be generated.');">
                                                        <input type="hidden" name="action" value="reset_password">
                                                        <input type="hidden" name="lecturer_id" value="<?= (int) $l['id'] ?>">
                                                        <button type="submit" class="btn-icon" title="Reset Password">
                                                            <i class="bi bi-key"></i>
                                                        </button>
                                                    </form>
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php" style="display:inline;"
                                                          onsubmit="return confirm('Delete this lecturer? This cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="lecturer_id" value="<?= (int) $l['id'] ?>">
                                                        <button type="submit" class="btn-icon text-danger" title="Delete">
                                                            <i class="bi bi-trash"></i>
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
                            <?= $formMode === 'edit' ? 'Edit Lecturer' : 'Add Lecturer' ?>
                        </h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php">
                            <input type="hidden" name="action" value="<?= $formMode === 'edit' ? 'update' : 'create' ?>">
                            <?php if ($formMode === 'edit'): ?>
                                <input type="hidden" name="lecturer_id" value="<?= (int) $formValues['id'] ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="lecturerStaffNoInput" class="form-label">Staff No</label>
                                <?php if ($formMode === 'edit'): ?>
                                    <input type="text" class="form-control text-uppercase" value="<?= htmlspecialchars($formValues['staff_no']) ?>" disabled>
                                    <div class="form-text">Staff number cannot be changed after creation.</div>
                                <?php else: ?>
                                    <input type="text" class="form-control text-uppercase" id="lecturerStaffNoInput" name="staff_no" maxlength="20"
                                           value="<?= htmlspecialchars($formValues['staff_no']) ?>" required>
                                    <div class="form-text">The lecturer's existing staff/employee number. This becomes their login username base and initial password.</div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="lecturerFullNameInput" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="lecturerFullNameInput" name="full_name" maxlength="150"
                                       value="<?= htmlspecialchars($formValues['full_name']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="lecturerEmailInput" class="form-label">Email</label>
                                <input type="email" class="form-control" id="lecturerEmailInput" name="email" maxlength="150"
                                       value="<?= htmlspecialchars($formValues['email']) ?>">
                            </div>

                            <div class="mb-3">
                                <label for="lecturerDepartmentSelect" class="form-label">Department</label>
                                <select class="form-select" id="lecturerDepartmentSelect" name="department_id" required>
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

                            <?php if ($formMode === 'create'): ?>
                                <div class="form-text mb-3">A username and temporary password will be generated automatically and shown once after saving.</div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= empty($departments) ? 'disabled' : '' ?>>
                                <?= $formMode === 'edit' ? 'Update Lecturer' : 'Save Lecturer' ?>
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
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('bulkDeleteLecturersBtn')) {
                admasInitBulkDelete({
                    checkboxSelector: '.row-check-lecturer',
                    selectAllSelector: '#selectAllLecturers',
                    buttonSelector: '#bulkDeleteLecturersBtn',
                    formSelector: '#bulkDeleteLecturersForm',
                    hiddenContainerSelector: '#bulkDeleteLecturersIds',
                    hiddenInputName: 'lecturer_ids[]',
                    entityLabel: 'lecturer',
                    entityLabelPlural: 'lecturers',
                });
            }
        });
    </script>
</body>
</html>
