<?php
/**
 * Forgot Password — single page, two phases, no separate reset_password.php
 * (that file now just redirects here for any stale bookmarks/links).
 *
 * Phase "identify": same Role + Username/Email fields as login.php (not a
 * raw email box) — the account's real email is looked up and used
 * automatically to send the code; the user never has to know/type it.
 * Phase "code": exactly 6-digit code + new password + confirm — no email
 * field here either, since the account was already identified in phase 1
 * (carried via $_SESSION, not re-asked).
 *
 * Enumeration-safety: phase "identify" always advances to phase "code" and
 * always shows the same generic message, whether or not the Role+Username/
 * Email combo matched a real, active account with an email on file. Only
 * $_SESSION['password_reset_user_id'] secretly knows whether a real match
 * happened (null if not) — phase "code" fails with the same generic
 * "invalid code" message either way, so no step of this flow reveals which
 * usernames exist.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/mail_config.php';
require_once __DIR__ . '/includes/university_logo.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

$errorMessage = '';
$successMessage = '';
$submittedRole = '';
$submittedIdentifier = '';
$submittedCode = '';

$phase = !empty($_SESSION['password_reset_pending']) ? 'code' : 'identify';

$settings = [];
$settingsResult = db()->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$universityName = $settings['university_name'] ?? 'ADMAS University';
$logoRelativePath = get_university_logo_relative_path($settings);
$logoFilesystemPath = __DIR__ . '/' . $logoRelativePath;

if (isset($_GET['restart'])) {
    unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_user_id']);
    redirect_to('forgot_password.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formPhase = (string) ($_POST['phase'] ?? '');

    if ($formPhase === 'identify') {
        $submittedRole = trim((string) ($_POST['role'] ?? ''));
        $submittedIdentifier = trim((string) ($_POST['username_or_email'] ?? ''));

        if ($submittedRole === '' || $submittedIdentifier === '') {
            $errorMessage = 'Please select your role and enter your username or email.';
            $phase = 'identify';
        } else {
            $conn = db();
            $stmt = $conn->prepare(
                'SELECT u.id, u.full_name, u.email, r.name AS role_name
                 FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE (u.username = ? OR u.email = ?) LIMIT 1'
            );
            $stmt->bind_param('ss', $submittedIdentifier, $submittedIdentifier);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $matchedUserId = null;
            if ($user && (string) $user['role_name'] === $submittedRole && !empty($user['email'])) {
                $matchedUserId = (int) $user['id'];

                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expiresAt = date('Y-m-d H:i:s', time() + 10 * 60);

                $insertStmt = $conn->prepare(
                    'INSERT INTO password_resets (user_id, code, expires_at, used) VALUES (?, ?, ?, 0)'
                );
                $insertStmt->bind_param('iss', $matchedUserId, $code, $expiresAt);
                $insertStmt->execute();
                $insertStmt->close();

                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = SMTP_HOST;
                    $mail->SMTPAuth = true;
                    $mail->Username = SMTP_USERNAME;
                    $mail->Password = SMTP_PASSWORD;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = SMTP_PORT;

                    $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
                    $mail->addAddress((string) $user['email'], (string) $user['full_name']);

                    // Embedded (cid:), not a remote <img src="http://...">: the
                    // logo lives on this local XAMPP server, which Gmail's
                    // servers can't reach to fetch a remote URL from at all —
                    // embedding attaches the actual image bytes to the email.
                    $logoHtml = '';
                    if (is_file($logoFilesystemPath)) {
                        $mail->addEmbeddedImage($logoFilesystemPath, 'universitylogo');
                        $logoHtml = '<img src="cid:universitylogo" alt="' . htmlspecialchars($universityName) . '" '
                            . 'style="width:64px;height:64px;border-radius:50%;display:block;margin:0 auto 12px;object-fit:cover;">';
                    }

                    $mail->isHTML(true);
                    $mail->Subject = 'Your ' . $universityName . ' password reset code';
                    $mail->Body = '<div style="text-align:center;">' . $logoHtml
                        . '<div style="font-weight:700;color:#0b1f3a;margin-bottom:16px;">' . htmlspecialchars($universityName) . '</div>'
                        . '</div>'
                        . '<p>Hello ' . htmlspecialchars((string) $user['full_name']) . ',</p>'
                        . '<p>Your password reset code is:</p>'
                        . '<p style="font-size:28px;font-weight:bold;letter-spacing:4px;">' . htmlspecialchars($code) . '</p>'
                        . '<p>This code expires in 10 minutes. If you did not request a password reset, you can ignore this email.</p>';
                    $mail->AltBody = $universityName . ' — your password reset code is ' . $code . '. It expires in 10 minutes.';

                    $mail->send();
                } catch (PHPMailerException $e) {
                    // Logged for the admin running this server, but never shown
                    // to the visitor — the response must stay identical whether
                    // or not the account existed / the send succeeded.
                    error_log('[forgot_password] Mail send failed: ' . $e->getMessage());
                }
            }

            $_SESSION['password_reset_pending'] = true;
            $_SESSION['password_reset_user_id'] = $matchedUserId;
            $phase = 'code';
        }
    } elseif ($formPhase === 'code') {
        $submittedCode = trim((string) ($_POST['code'] ?? ''));
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmNewPassword = (string) ($_POST['confirm_new_password'] ?? '');

        $validationError = '';
        if (!preg_match('/^\d{6}$/', $submittedCode)) {
            $validationError = 'Please enter the 6-digit code exactly as emailed to you.';
        } elseif ($newPassword === '' || $confirmNewPassword === '') {
            $validationError = 'Please fill in both password fields.';
        } elseif (mb_strlen($newPassword) < 8) {
            $validationError = 'New password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmNewPassword) {
            $validationError = 'New password and confirmation do not match.';
        }

        $sessionUserId = $_SESSION['password_reset_user_id'] ?? null;
        $conn = db();
        $resetRow = null;

        if ($validationError === '') {
            if ($sessionUserId === null) {
                // No real account was matched in phase 1 — fail exactly like
                // a wrong code would, so this step can't be used to test
                // which usernames exist either.
                $validationError = 'That code is invalid or has expired. Please request a new one.';
            } else {
                $lookupStmt = $conn->prepare(
                    'SELECT id, expires_at, used FROM password_resets
                     WHERE user_id = ? AND code = ?
                     ORDER BY id DESC LIMIT 1'
                );
                $lookupStmt->bind_param('is', $sessionUserId, $submittedCode);
                $lookupStmt->execute();
                $resetRow = $lookupStmt->get_result()->fetch_assoc();
                $lookupStmt->close();

                if (!$resetRow) {
                    $validationError = 'That code is invalid or has expired. Please request a new one.';
                } elseif ((int) $resetRow['used'] === 1) {
                    $validationError = 'That code has already been used. Please request a new one.';
                } elseif (strtotime((string) $resetRow['expires_at']) < time()) {
                    $validationError = 'That code has expired. Please request a new one.';
                }
            }
        }

        if ($validationError === '') {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $conn->begin_transaction();
            try {
                $updateUserStmt = $conn->prepare(
                    'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
                );
                $updateUserStmt->bind_param('si', $newHash, $sessionUserId);
                $updateUserStmt->execute();
                $updateUserStmt->close();

                $markUsedStmt = $conn->prepare('UPDATE password_resets SET used = 1 WHERE id = ?');
                $markUsedStmt->bind_param('i', $resetRow['id']);
                $markUsedStmt->execute();
                $markUsedStmt->close();

                // Invalidate any other still-outstanding codes for this user so
                // an old, unused code can't be redeemed later.
                $invalidateStmt = $conn->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0');
                $invalidateStmt->bind_param('i', $sessionUserId);
                $invalidateStmt->execute();
                $invalidateStmt->close();

                $conn->commit();
                unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_user_id']);
                $successMessage = 'Your password has been reset successfully. You can now log in with your new password.';
            } catch (Throwable $e) {
                $conn->rollback();
                $errorMessage = 'Could not reset your password. Please try again.';
                $phase = 'code';
            }
        } else {
            $errorMessage = $validationError;
            $phase = 'code';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — ADMAS Attendance System</title>
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

        .reset-brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .reset-brand img {
            width: 72px;
            height: 72px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
            margin-bottom: 0.5rem;
        }

        .reset-brand .reset-brand-name {
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
    <div class="reset-card">
        <div class="reset-brand">
            <img src="<?= htmlspecialchars(BASE_URL . '/' . $logoRelativePath) ?>" alt="<?= htmlspecialchars($universityName) ?> logo">
            <div class="reset-brand-name"><?= htmlspecialchars($universityName) ?></div>
        </div>

        <?php if ($successMessage !== ''): ?>
            <h2 class="fw-bold mb-2">Reset Password</h2>
            <div class="alert alert-success" role="alert"><?= htmlspecialchars($successMessage) ?></div>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" class="btn btn-primary w-100 py-2">Back to Login</a>

        <?php elseif ($phase === 'code'): ?>
            <h2 class="fw-bold mb-2">Reset Password</h2>
            <p class="text-muted mb-4">Enter the 6-digit code we emailed you along with your new password.</p>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/forgot_password.php">
                <input type="hidden" name="phase" value="code">
                <div class="mb-3">
                    <label for="code" class="form-label">6-Digit Code</label>
                    <input type="text" class="form-control" id="code" name="code" maxlength="6" pattern="\d{6}" inputmode="numeric"
                           value="<?= htmlspecialchars($submittedCode) ?>" required autofocus>
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
            <div class="text-center mt-3">
                <a href="<?= htmlspecialchars(BASE_URL) ?>/forgot_password.php?restart=1" class="small">Didn't get a code? Start over</a>
            </div>

        <?php else: ?>
            <h2 class="fw-bold mb-2">Forgot Password</h2>
            <p class="text-muted mb-4">Select your role and enter your username or email — we'll send a 6-digit reset code to the email on file for that account.</p>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/forgot_password.php">
                <input type="hidden" name="phase" value="identify">
                <div class="mb-3">
                    <label for="role" class="form-label">Role</label>
                    <select class="form-select" id="role" name="role" required>
                        <option value="">Select role</option>
                        <option value="university_rector" <?= $submittedRole === 'university_rector' ? 'selected' : '' ?>>University Rector</option>
                        <option value="head_academic" <?= $submittedRole === 'head_academic' ? 'selected' : '' ?>>Head of Academic Affairs</option>
                        <option value="registration" <?= $submittedRole === 'registration' ? 'selected' : '' ?>>Registration Office</option>
                        <option value="dean" <?= $submittedRole === 'dean' ? 'selected' : '' ?>>Dean</option>
                        <option value="lecturer" <?= $submittedRole === 'lecturer' ? 'selected' : '' ?>>Lecturer</option>
                        <option value="student" <?= $submittedRole === 'student' ? 'selected' : '' ?>>Student</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="username_or_email" class="form-label">Username / Email</label>
                    <input type="text" class="form-control" id="username_or_email" name="username_or_email"
                           value="<?= htmlspecialchars($submittedIdentifier) ?>" required>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2">Send Reset Code</button>
            </form>
        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" class="small">Back to Login</a>
        </div>
    </div>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/password-toggle.js"></script>
</body>
</html>
