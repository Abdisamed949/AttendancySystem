<?php
/**
 * Profile & Password (Dean only) — edit own Full Name/Email, change
 * username, and change password. Same pattern as admin/profile.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';

require_role(['dean']);

$conn = db();
$currentUser = current_user();
$currentUserId = (int) $currentUser['id'];

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
// Own faculty name for the scope banner (never trusted from input)
// ---------------------------------------------------------------------
$deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
$deanFacultyName = '';
if ($deanFacultyId > 0) {
    $fStmt = $conn->prepare('SELECT name FROM faculties WHERE id = ?');
    $fStmt->bind_param('i', $deanFacultyId);
    $fStmt->execute();
    $fRow = $fStmt->get_result()->fetch_assoc();
    $fStmt->close();
    $deanFacultyName = $fRow ? (string) $fRow['name'] : '';
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
// Form state (defaults from the current user; overridden below only on a
// failed submit of that specific form, so the others stay untouched)
// ---------------------------------------------------------------------
$profileFormValues = [
    'full_name' => (string) $currentUser['full_name'],
    'email' => (string) ($currentUser['email'] ?? ''),
];
$usernameFormValues = [
    'new_username' => (string) $currentUser['username'],
];

// ---------------------------------------------------------------------
// Handle POST actions: update_profile, change_username, change_password
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        $profileFormValues = ['full_name' => $fullName, 'email' => $email];

        $validationError = '';
        if ($fullName === '') {
            $validationError = 'Full name is required.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $validationError = 'Please enter a valid email address.';
        }

        if ($validationError === '' && $email !== '') {
            $emailCheckStmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $emailCheckStmt->bind_param('si', $email, $currentUserId);
            $emailCheckStmt->execute();
            if ($emailCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'This email address is already used by another account.';
            }
            $emailCheckStmt->close();
        }

        if ($validationError === '') {
            $emailParam = $email !== '' ? $email : null;
            $updateStmt = $conn->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
            $updateStmt->bind_param('ssi', $fullName, $emailParam, $currentUserId);
            $updateStmt->execute();
            $updateStmt->close();

            $_SESSION['flash_success'] = 'Profile updated successfully.';
            redirect_to('dean/profile.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'change_username') {
        $newUsername = trim((string) ($_POST['new_username'] ?? ''));
        $usernameFormValues = ['new_username' => $newUsername];

        $validationError = '';
        if ($newUsername === '') {
            $validationError = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $newUsername)) {
            $validationError = 'Username may only contain letters, numbers, dots, underscores, and hyphens.';
        }

        if ($validationError === '') {
            $dupStmt = $conn->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $dupStmt->bind_param('si', $newUsername, $currentUserId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $validationError = 'That username is already taken.';
            }
            $dupStmt->close();
        }

        if ($validationError === '') {
            $updateStmt = $conn->prepare('UPDATE users SET username = ? WHERE id = ?');
            $updateStmt->bind_param('si', $newUsername, $currentUserId);
            $updateStmt->execute();
            $updateStmt->close();

            $_SESSION['flash_success'] = 'Username updated successfully. Use "' . $newUsername . '" to log in from now on.';
            redirect_to('dean/profile.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmNewPassword = (string) ($_POST['confirm_new_password'] ?? '');

        $hashStmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ?');
        $hashStmt->bind_param('i', $currentUserId);
        $hashStmt->execute();
        $hashRow = $hashStmt->get_result()->fetch_assoc();
        $hashStmt->close();

        $validationError = '';
        if ($currentPassword === '' || $newPassword === '' || $confirmNewPassword === '') {
            $validationError = 'Please fill in all three password fields.';
        } elseif (!$hashRow || !password_verify($currentPassword, $hashRow['password_hash'])) {
            $validationError = 'Current password is incorrect.';
        } elseif (mb_strlen($newPassword) < 8) {
            $validationError = 'New password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmNewPassword) {
            $validationError = 'New password and confirmation do not match.';
        }

        if ($validationError === '') {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $updateStmt->bind_param('si', $newHash, $currentUserId);
            $updateStmt->execute();
            $updateStmt->close();

            clear_must_change_password($conn, $currentUserId);

            $_SESSION['flash_success'] = 'Password changed successfully.';
            redirect_to('dean/profile.php');
        }

        $errorMessage = $validationError;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile &amp; Password — ADMAS Attendance System</title>
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
                Access scope: <?= htmlspecialchars($deanFacultyName) ?> Faculty only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Profile &amp; Password</h4>
                    <p class="text-muted mb-0">Manage your own account details, username, and password.</p>
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

            <?php if (!empty($_SESSION['must_change_password'])): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-shield-exclamation"></i>
                    For security, please set your own password before continuing.
                </div>
            <?php endif; ?>

            <!-- Profile Information -->
            <div class="admas-card p-4 mb-3">
                <h6 class="fw-bold mb-3" style="color: #0b1f3a;">Profile Information</h6>
                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/dean/profile.php" class="row g-3">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="col-md-6">
                        <label for="fullNameInput" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="fullNameInput" name="full_name" maxlength="150" required
                               value="<?= htmlspecialchars($profileFormValues['full_name']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="emailInput" class="form-label">Email</label>
                        <input type="email" class="form-control" id="emailInput" name="email" maxlength="150"
                               value="<?= htmlspecialchars($profileFormValues['email']) ?>">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                            <i class="bi bi-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <!-- Change Username -->
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-3" style="color: #0b1f3a;">Change Username</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/dean/profile.php">
                            <input type="hidden" name="action" value="change_username">

                            <div class="mb-3">
                                <label for="newUsernameInput" class="form-label">Username</label>
                                <input type="text" class="form-control" id="newUsernameInput" name="new_username" maxlength="60" required
                                       value="<?= htmlspecialchars($usernameFormValues['new_username']) ?>">
                                <div class="form-text">Used to sign in — must be unique across all accounts.</div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                                <i class="bi bi-person-badge"></i> Save Username
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-6">
                    <!-- Change Password -->
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-3" style="color: #0b1f3a;">Change Password</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/dean/profile.php">
                            <input type="hidden" name="action" value="change_password">

                            <div class="mb-3">
                                <label for="currentPasswordInput" class="form-label">Current Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="currentPasswordInput" name="current_password" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button" data-target="currentPasswordInput" aria-label="Show password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="newPasswordInput" class="form-label">New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="newPasswordInput" name="new_password" minlength="8" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button" data-target="newPasswordInput" aria-label="Show password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">At least 8 characters.</div>
                            </div>
                            <div class="mb-3">
                                <label for="confirmNewPasswordInput" class="form-label">Confirm New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="confirmNewPasswordInput" name="confirm_new_password" minlength="8" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirmNewPasswordInput" aria-label="Show password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                                <i class="bi bi-key"></i> Change Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/password-toggle.js"></script>
</body>
</html>
