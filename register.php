<?php
/**
 * Student self-registration ("claim your account") — public, no session
 * required. A student's profile record (Student No/Faculty/Department/
 * Shift/Academic Year) is already created by Registration Office via
 * admin/students.php or admin/students_import.php, with an auto-generated
 * placeholder username/password the student never sees. This page lets the
 * student prove it's really them (by matching those 5 fields against their
 * own real row) and choose their own username + password for the first
 * time — a one-time action, tracked by students.self_registered_at.
 *
 * No name field on this form (deliberately, per explicit instruction) — the
 * name is already on file and isn't needed to identify the record; the 5
 * lookup fields together are.
 *
 * Enumeration-safety-ish, same spirit as forgot_password.php: a lookup
 * miss and an "already registered" hit both render distinct, but neither
 * confirms which of the 5 fields was wrong on a miss — just "not found".
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/university_logo.php';

$conn = db();

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$universityName = $settings['university_name'] ?? 'ADMAS University';
$logoRelativePath = get_university_logo_relative_path($settings);

const SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

$faculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$departments = $conn->query('SELECT id, name, faculty_id FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$academicYears = $conn->query('SELECT id, label FROM academic_years ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);

$departmentsByFacultyId = [];
foreach ($departments as $dept) {
    $departmentsByFacultyId[(int) $dept['faculty_id']][] = ['id' => (int) $dept['id'], 'name' => (string) $dept['name']];
}

$errorMessage = '';
$resultState = ''; // '' = show form, 'not_found', 'already_registered', 'success'
$formValues = [
    'student_no' => '',
    'faculty_id' => 0,
    'department_id' => 0,
    'shift' => '',
    'academic_year_id' => 0,
    'new_username' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentNo = strtoupper(trim((string) ($_POST['student_no'] ?? '')));
    $facultyId = (int) ($_POST['faculty_id'] ?? 0);
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $shift = (string) ($_POST['shift'] ?? '');
    $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
    $newUsername = trim((string) ($_POST['new_username'] ?? ''));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $formValues = [
        'student_no' => $studentNo,
        'faculty_id' => $facultyId,
        'department_id' => $departmentId,
        'shift' => $shift,
        'academic_year_id' => $academicYearId,
        'new_username' => $newUsername,
    ];

    $validationError = '';
    if ($studentNo === '' || $facultyId <= 0 || $departmentId <= 0 || $shift === '' || $academicYearId <= 0) {
        $validationError = 'Please fill in all fields.';
    } elseif (!array_key_exists($shift, SHIFT_LABELS)) {
        $validationError = 'Please select a valid shift.';
    } elseif ($newUsername === '') {
        $validationError = 'Please choose a username.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $newUsername)) {
        $validationError = 'Username may only contain letters, numbers, dots, underscores, and hyphens.';
    } elseif ($newPassword === '' || $confirmPassword === '') {
        $validationError = 'Please fill in both password fields.';
    } elseif (mb_strlen($newPassword) < 8) {
        $validationError = 'New password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $validationError = 'New password and confirmation do not match.';
    }

    if ($validationError === '') {
        $usernameDupStmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
        $usernameDupStmt->bind_param('s', $newUsername);
        $usernameDupStmt->execute();
        if ($usernameDupStmt->get_result()->fetch_assoc()) {
            $validationError = 'That username is already taken. Please choose a different one.';
        }
        $usernameDupStmt->close();
    }

    if ($validationError === '') {
        $lookupStmt = $conn->prepare(
            'SELECT id, user_id, self_registered_at FROM students
             WHERE UPPER(student_no) = ? AND faculty_id = ? AND department_id = ? AND shift = ? AND academic_year_id = ?'
        );
        $lookupStmt->bind_param('siisi', $studentNo, $facultyId, $departmentId, $shift, $academicYearId);
        $lookupStmt->execute();
        $studentRow = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();

        if (!$studentRow) {
            $resultState = 'not_found';
        } elseif ($studentRow['self_registered_at'] !== null) {
            $resultState = 'already_registered';
        } else {
            $studentId = (int) $studentRow['id'];
            $userId = (int) $studentRow['user_id'];
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $conn->begin_transaction();
            try {
                $updateUserStmt = $conn->prepare(
                    'UPDATE users SET username = ?, password_hash = ?, must_change_password = 0 WHERE id = ?'
                );
                $updateUserStmt->bind_param('ssi', $newUsername, $passwordHash, $userId);
                $updateUserStmt->execute();
                $updateUserStmt->close();

                $updateStudentStmt = $conn->prepare('UPDATE students SET self_registered_at = NOW() WHERE id = ?');
                $updateStudentStmt->bind_param('i', $studentId);
                $updateStudentStmt->execute();
                $updateStudentStmt->close();

                $conn->commit();

                // Log the student straight in, same session shape login.php sets.
                $userStmt = $conn->prepare('SELECT full_name FROM users WHERE id = ?');
                $userStmt->bind_param('i', $userId);
                $userStmt->execute();
                $fullName = (string) ($userStmt->get_result()->fetch_assoc()['full_name'] ?? '');
                $userStmt->close();

                $_SESSION['user_id'] = $userId;
                $_SESSION['role'] = 'student';
                $_SESSION['full_name'] = $fullName;
                $_SESSION['faculty_id'] = null;
                $_SESSION['must_change_password'] = false;

                redirect_to('student/dashboard.php');
            } catch (Throwable $e) {
                $conn->rollback();
                $validationError = 'Could not complete registration. Please try again.';
            }
        }
    }

    $errorMessage = $validationError;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #0ea5e9 0%, #38bdf8 45%, #7dd3fc 100%);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .register-card {
            width: 100%;
            max-width: 480px;
            background: #fff;
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 25px 60px rgba(11, 31, 58, 0.35);
        }

        .register-brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .register-brand img {
            width: 72px;
            height: 72px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
            margin-bottom: 0.5rem;
        }

        .register-brand .register-brand-name {
            font-weight: 700;
            font-size: 1rem;
            color: #0b1f3a;
        }

        .btn-primary {
            background-color: #0ea5e9;
            border-color: #0ea5e9;
        }

        .form-control:focus, .form-select:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 0.2rem rgba(14, 165, 233, 0.15);
        }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="register-brand">
            <img src="<?= htmlspecialchars(BASE_URL . '/' . $logoRelativePath) ?>" alt="<?= htmlspecialchars($universityName) ?> logo">
            <div class="register-brand-name"><?= htmlspecialchars($universityName) ?></div>
        </div>

        <?php if ($resultState === 'not_found'): ?>
            <h2 class="fw-bold mb-2">Account Not Registered</h2>
            <p class="text-muted mb-4">
                We couldn't find a student record matching the details you entered. Please check with the
                Registration Office to confirm your Student No, Faculty, Department, Shift, and Academic Year.
            </p>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/register.php" class="btn btn-outline-secondary w-100 py-2">Try Again</a>

        <?php elseif ($resultState === 'already_registered'): ?>
            <h2 class="fw-bold mb-2">Already Registered</h2>
            <p class="text-muted mb-4">
                This account has already been set up. Please log in with the username and password you chose.
            </p>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" class="btn btn-primary w-100 py-2">Go to Login</a>

        <?php else: ?>
            <h2 class="fw-bold mb-2">Register</h2>
            <p class="text-muted mb-4">
                Enter your Student No, Faculty, Department, Shift, and Academic Year exactly as on file with the
                Registration Office, then choose your own username and password. You can only do this once.
            </p>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/register.php">
                <div class="mb-3">
                    <label for="studentNoInput" class="form-label">Student No</label>
                    <input type="text" class="form-control text-uppercase" id="studentNoInput" name="student_no" maxlength="20"
                           value="<?= htmlspecialchars($formValues['student_no']) ?>" required autofocus>
                </div>

                <div class="mb-3">
                    <label for="facultySelect" class="form-label">Faculty</label>
                    <select class="form-select" id="facultySelect" name="faculty_id" required onchange="admasUpdateRegisterDepartments()">
                        <option value="">Select faculty</option>
                        <?php foreach ($faculties as $f): ?>
                            <option value="<?= (int) $f['id'] ?>" <?= $formValues['faculty_id'] === (int) $f['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($f['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="departmentSelect" class="form-label">Department</label>
                    <select class="form-select" id="departmentSelect" name="department_id" required>
                        <option value="">Select faculty first</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="shiftSelect" class="form-label">Shift</label>
                    <select class="form-select" id="shiftSelect" name="shift" required>
                        <option value="">Select shift</option>
                        <?php foreach (SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                            <option value="<?= htmlspecialchars($shiftValue) ?>" <?= $formValues['shift'] === $shiftValue ? 'selected' : '' ?>>
                                <?= htmlspecialchars($shiftLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="academicYearSelect" class="form-label">Academic Year</label>
                    <select class="form-select" id="academicYearSelect" name="academic_year_id" required>
                        <option value="">Select academic year</option>
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= (int) $ay['id'] ?>" <?= $formValues['academic_year_id'] === (int) $ay['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ay['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <hr class="my-4">

                <div class="mb-3">
                    <label for="newUsernameInput" class="form-label">Choose a Username</label>
                    <input type="text" class="form-control" id="newUsernameInput" name="new_username" maxlength="60"
                           value="<?= htmlspecialchars($formValues['new_username']) ?>" required>
                </div>

                <div class="mb-3">
                    <label for="newPasswordInput" class="form-label">Choose a Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="newPasswordInput" name="new_password" minlength="8" required>
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="newPasswordInput" aria-label="Show password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="form-text">At least 8 characters.</div>
                </div>

                <div class="mb-4">
                    <label for="confirmPasswordInput" class="form-label">Confirm Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="confirmPasswordInput" name="confirm_password" minlength="8" required>
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirmPasswordInput" aria-label="Show password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">Register</button>
            </form>
        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" class="small">Back to Login</a>
        </div>
    </div>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/password-toggle.js"></script>
    <script>
        const admasRegisterDepartmentsByFaculty = <?= json_encode($departmentsByFacultyId) ?>;
        const admasRegisterSelectedDepartmentId = <?= (int) $formValues['department_id'] ?>;

        function admasUpdateRegisterDepartments() {
            const facultyId = document.getElementById('facultySelect').value;
            const departmentSelect = document.getElementById('departmentSelect');
            departmentSelect.innerHTML = '';

            if (!facultyId) {
                departmentSelect.innerHTML = '<option value="">Select faculty first</option>';
                return;
            }

            const departments = admasRegisterDepartmentsByFaculty[facultyId] || [];
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select department';
            departmentSelect.appendChild(placeholder);

            departments.forEach(function (dept) {
                const option = document.createElement('option');
                option.value = dept.id;
                option.textContent = dept.name;
                if (Number(dept.id) === admasRegisterSelectedDepartmentId) {
                    option.selected = true;
                }
                departmentSelect.appendChild(option);
            });
        }

        document.addEventListener('DOMContentLoaded', admasUpdateRegisterDepartments);
    </script>
</body>
</html>
