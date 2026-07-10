<?php
/**
 * Lecturers (Head of Academic Affairs only) — read-only, university-wide
 * list of every lecturer (CLAUDE.md §4 does not grant this role edit/
 * delete/reset-password over lecturers, only "register new Lecturer
 * accounts"), plus the Register New Lecturer quick-add form that used to
 * live only on head_academic/dashboard.php (moved here so it's reachable
 * from the "Lecturers" nav item, and no longer duplicated on both pages).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/lecturer_accounts.php';

require_role(['head_academic']);

$conn = db();
$currentUser = current_user();

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
// Lecturer role id (for the Register New Lecturer form)
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
// Register New Lecturer form state
// ---------------------------------------------------------------------
$lecturerFormValues = ['staff_no' => '', 'full_name' => '', 'email' => '', 'department_id' => 0];

// ---------------------------------------------------------------------
// Handle POST: register_lecturer (same transaction shape as
// admin/lecturers.php's create branch)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'register_lecturer') {
    $staffNo = strtoupper(trim((string) ($_POST['staff_no'] ?? '')));
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $departmentId = (int) ($_POST['department_id'] ?? 0);

    $lecturerFormValues = ['staff_no' => $staffNo, 'full_name' => $fullName, 'email' => $email, 'department_id' => $departmentId];

    $validationError = '';
    if ($fullName === '') {
        $validationError = 'Full name is required.';
    } elseif ($staffNo === '') {
        $validationError = 'Staff number is required.';
    } elseif ($departmentId <= 0) {
        $validationError = 'Please select a department.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validationError = 'Please enter a valid email address.';
    }

    if ($validationError === '') {
        $deptCheckStmt = $conn->prepare('SELECT id FROM departments WHERE id = ?');
        $deptCheckStmt->bind_param('i', $departmentId);
        $deptCheckStmt->execute();
        if (!$deptCheckStmt->get_result()->fetch_assoc()) {
            $validationError = 'Selected department does not exist.';
        }
        $deptCheckStmt->close();
    }

    if ($validationError === '') {
        $dupStaffStmt = $conn->prepare('SELECT id FROM lecturers WHERE UPPER(staff_no) = ?');
        $dupStaffStmt->bind_param('s', $staffNo);
        $dupStaffStmt->execute();
        if ($dupStaffStmt->get_result()->fetch_assoc()) {
            $validationError = 'A lecturer with this staff number already exists.';
        }
        $dupStaffStmt->close();
    }

    if ($validationError === '' && $email !== '') {
        $emailCheckStmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $emailCheckStmt->bind_param('s', $email);
        $emailCheckStmt->execute();
        if ($emailCheckStmt->get_result()->fetch_assoc()) {
            $validationError = 'This email address is already used by another account.';
        }
        $emailCheckStmt->close();
    }

    if ($validationError === '') {
        $emailParam = $email !== '' ? $email : null;

        $conn->begin_transaction();
        try {
            $username = generate_lecturer_username($conn, $fullName);
            $tempPassword = generate_temp_password();
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
            $_SESSION['flash_success'] = 'Lecturer registered successfully. Username: ' . $username
                . ' — Temporary Password: ' . $tempPassword
                . ' — share these credentials with the lecturer now; the password will not be shown again.';
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['flash_error'] = 'Could not register the lecturer. Please try again.';
        }

        redirect_to('head_academic/lecturers.php');
    }

    $errorMessage = $validationError;
}

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------
$departmentsByFaculty = [];
$deptResult = $conn->query(
    "SELECT d.id, d.name, f.name AS faculty_name
     FROM departments d
     JOIN faculties f ON f.id = d.faculty_id
     ORDER BY f.name, d.name"
);
while ($row = $deptResult->fetch_assoc()) {
    $departmentsByFaculty[$row['faculty_name']][] = $row;
}

$lecturers = $conn->query(
    "SELECT l.id, l.staff_no, l.full_name, d.name AS department_name, f.name AS faculty_name,
            u.username, u.status AS user_status,
            (SELECT COUNT(*) FROM courses c WHERE c.lecturer_id = l.id) AS course_count
     FROM lecturers l
     JOIN departments d ON d.id = l.department_id
     JOIN faculties f ON f.id = d.faculty_id
     JOIN users u ON u.id = l.user_id
     ORDER BY f.name, d.name, l.full_name"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturers — ADMAS Attendance System</title>
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
                Access scope: All faculties (cross-faculty, view + register only)
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Lecturers</h4>
                    <p class="text-muted mb-0">University-wide lecturer directory and new-account registration.</p>
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
                        <h6 class="fw-bold mb-3" style="color: #0b1f3a;">All Lecturers</h6>
                        <div class="table-responsive">
                            <table class="table admas-table align-middle">
                                <thead>
                                    <tr>
                                        <th>Staff No</th>
                                        <th>Full Name</th>
                                        <th>Department</th>
                                        <th>Faculty</th>
                                        <th># Courses</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($lecturers)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">No lecturers have been registered yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($lecturers as $l): ?>
                                            <tr>
                                                <td><span class="badge-pill badge-active"><?= htmlspecialchars($l['staff_no']) ?></span></td>
                                                <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($l['full_name']) ?></td>
                                                <td><?= htmlspecialchars($l['department_name']) ?></td>
                                                <td><?= htmlspecialchars($l['faculty_name']) ?></td>
                                                <td><?= number_format((int) $l['course_count']) ?></td>
                                                <td>
                                                    <?php if ($l['user_status'] === 'active'): ?>
                                                        <span class="badge-pill badge-active">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill badge-inactive">Inactive</span>
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

                <div class="col-lg-4">
                    <div class="admas-card p-4">
                        <h6 class="fw-bold mb-3" style="color: #0b1f3a;">Register New Lecturer</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/head_academic/lecturers.php">
                            <input type="hidden" name="action" value="register_lecturer">

                            <div class="mb-3">
                                <label for="lecturerStaffNoInput" class="form-label">Staff No</label>
                                <input type="text" class="form-control text-uppercase" id="lecturerStaffNoInput" name="staff_no" maxlength="20" required
                                       value="<?= htmlspecialchars($lecturerFormValues['staff_no']) ?>">
                            </div>
                            <div class="mb-3">
                                <label for="lecturerFullNameInput" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="lecturerFullNameInput" name="full_name" maxlength="150" required
                                       value="<?= htmlspecialchars($lecturerFormValues['full_name']) ?>">
                            </div>
                            <div class="mb-3">
                                <label for="lecturerEmailInput" class="form-label">Email</label>
                                <input type="email" class="form-control" id="lecturerEmailInput" name="email" maxlength="150"
                                       value="<?= htmlspecialchars($lecturerFormValues['email']) ?>">
                            </div>
                            <div class="mb-3">
                                <label for="lecturerDepartmentSelect" class="form-label">Department</label>
                                <select class="form-select" id="lecturerDepartmentSelect" name="department_id" required>
                                    <option value="">Select department</option>
                                    <?php foreach ($departmentsByFaculty as $facultyName => $deptList): ?>
                                        <optgroup label="<?= htmlspecialchars($facultyName) ?>">
                                            <?php foreach ($deptList as $d): ?>
                                                <option value="<?= (int) $d['id'] ?>" <?= $lecturerFormValues['department_id'] === (int) $d['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($d['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($departmentsByFaculty)): ?>
                                    <div class="form-text text-danger">No departments exist yet.</div>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: #0ea5e9; border-color: #0ea5e9;" <?= empty($departmentsByFaculty) ? 'disabled' : '' ?>>
                                <i class="bi bi-person-plus"></i> Register Lecturer
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
