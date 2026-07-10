<?php
/**
 * Forgot Password — step 2: enter the emailed 6-digit code plus a new
 * password. Email is asked for again alongside the code (not just the
 * code alone) because `password_resets.code` is only unique per-user, not
 * globally — asking for both unambiguously identifies which reset request
 * is being redeemed.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$submittedEmail = '';
$submittedCode = '';
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedEmail = trim((string) ($_POST['email'] ?? ''));
    $submittedCode = trim((string) ($_POST['code'] ?? ''));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmNewPassword = (string) ($_POST['confirm_new_password'] ?? '');

    $validationError = '';
    if ($submittedEmail === '' || !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
        $validationError = 'Please enter a valid email address.';
    } elseif (!preg_match('/^\d{6}$/', $submittedCode)) {
        $validationError = 'Please enter the 6-digit code exactly as emailed to you.';
    } elseif ($newPassword === '' || $confirmNewPassword === '') {
        $validationError = 'Please fill in both password fields.';
    } elseif (mb_strlen($newPassword) < 8) {
        $validationError = 'New password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmNewPassword) {
        $validationError = 'New password and confirmation do not match.';
    }

    $conn = db();
    $resetRow = null;
    if ($validationError === '') {
        $lookupStmt = $conn->prepare(
            'SELECT pr.id, pr.user_id, pr.expires_at, pr.used
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE u.email = ? AND pr.code = ?
             ORDER BY pr.id DESC
             LIMIT 1'
        );
        $lookupStmt->bind_param('ss', $submittedEmail, $submittedCode);
        $lookupStmt->execute();
        $resetRow = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();

        if (!$resetRow) {
            $validationError = 'That code does not match this email address.';
        } elseif ((int) $resetRow['used'] === 1) {
            $validationError = 'That code has already been used. Please request a new one.';
        } elseif (strtotime((string) $resetRow['expires_at']) < time()) {
            $validationError = 'That code has expired. Please request a new one.';
        }
    }

    if ($validationError === '') {
        $userId = (int) $resetRow['user_id'];
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $conn->begin_transaction();
        try {
            $updateUserStmt = $conn->prepare(
                'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
            );
            $updateUserStmt->bind_param('si', $newHash, $userId);
            $updateUserStmt->execute();
            $updateUserStmt->close();

            $markUsedStmt = $conn->prepare('UPDATE password_resets SET used = 1 WHERE id = ?');
            $markUsedStmt->bind_param('i', $resetRow['id']);
            $markUsedStmt->execute();
            $markUsedStmt->close();

            // Invalidate any other still-outstanding codes for this user so
            // an old, unused code can't be redeemed later.
            $invalidateStmt = $conn->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0');
            $invalidateStmt->bind_param('i', $userId);
            $invalidateStmt->execute();
            $invalidateStmt->close();

            $conn->commit();
            $successMessage = 'Your password has been reset successfully. You can now log in with your new password.';
        } catch (Throwable $e) {
            $conn->rollback();
            $errorMessage = 'Could not reset your password. Please try again.';
        }
    } else {
        $errorMessage = $validationError;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — ADMAS Attendance System</title>
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

        .reset-card {
            width: 100%;
            max-width: 440px;
            background: #fff;
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 25px 60px rgba(11, 31, 58, 0.35);
        }

        .btn-primary {
            background-color: #0ea5e9;
            border-color: #0ea5e9;
        }

        .form-control:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 0.2rem rgba(14, 165, 233, 0.15);
        }
    </style>
</head>
<body>
    <div class="reset-card">
        <h2 class="fw-bold mb-2">Reset Password</h2>
        <p class="text-muted mb-4">Enter the 6-digit code we emailed you along with your new password.</p>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success" role="alert"><?= htmlspecialchars($successMessage) ?></div>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" class="btn btn-primary w-100 py-2">Back to Login</a>
        <?php else: ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/reset_password.php">
                <div class="mb-3">
                    <label for="email" class="form-label">Account Email</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($submittedEmail) ?>" required>
                </div>
                <div class="mb-3">
                    <label for="code" class="form-label">6-Digit Code</label>
                    <input type="text" class="form-control" id="code" name="code" maxlength="6" pattern="\d{6}" inputmode="numeric"
                           value="<?= htmlspecialchars($submittedCode) ?>" required>
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required>
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="new_password" aria-label="Show password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="form-text">At least 8 characters.</div>
                </div>
                <div class="mb-4">
                    <label for="confirm_new_password" class="form-label">Confirm New Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" minlength="8" required>
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_new_password" aria-label="Show password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2">Reset Password</button>
            </form>
        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" class="small">Back to Login</a>
        </div>
    </div>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/password-toggle.js"></script>
</body>
</html>
