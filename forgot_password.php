<?php
/**
 * Forgot Password — step 1: enter email, get a 6-digit code emailed via
 * Gmail SMTP (PHPMailer). Always shows the same generic success message
 * regardless of whether the email actually matched an account, to avoid
 * leaking which addresses have accounts (user enumeration).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/mail_config.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

$submittedEmail = '';
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedEmail = trim((string) ($_POST['email'] ?? ''));

    if ($submittedEmail === '' || !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please enter a valid email address.';
    } else {
        $conn = db();
        $userStmt = $conn->prepare('SELECT id, full_name, email FROM users WHERE email = ?');
        $userStmt->bind_param('s', $submittedEmail);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if ($user) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', time() + 10 * 60);

            $insertStmt = $conn->prepare(
                'INSERT INTO password_resets (user_id, code, expires_at, used) VALUES (?, ?, ?, 0)'
            );
            $insertStmt->bind_param('iss', $user['id'], $code, $expiresAt);
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
                $mail->addAddress($user['email'], (string) $user['full_name']);

                $mail->isHTML(true);
                $mail->Subject = 'Your ADMAS Attendance System password reset code';
                $mail->Body = '<p>Hello ' . htmlspecialchars((string) $user['full_name']) . ',</p>'
                    . '<p>Your password reset code is:</p>'
                    . '<p style="font-size:28px;font-weight:bold;letter-spacing:4px;">' . htmlspecialchars($code) . '</p>'
                    . '<p>This code expires in 10 minutes. If you did not request a password reset, you can ignore this email.</p>';
                $mail->AltBody = 'Your password reset code is ' . $code . '. It expires in 10 minutes.';

                $mail->send();
            } catch (PHPMailerException $e) {
                // Logged for the admin running this server, but never shown
                // to the visitor — the response must stay identical whether
                // or not the email existed / the send succeeded.
                error_log('[forgot_password] Mail send failed: ' . $e->getMessage());
            }
        }

        $successMessage = 'If that email address matches an account, a 6-digit reset code has been sent to it. The code expires in 10 minutes.';
    }
}

$settings = [];
$settingsResult = db()->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$universityName = $settings['university_name'] ?? 'ADMAS University';
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
        <h2 class="fw-bold mb-2">Forgot Password</h2>
        <p class="text-muted mb-4">Enter your account email and we'll send you a 6-digit reset code.</p>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success" role="alert"><?= htmlspecialchars($successMessage) ?></div>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/reset_password.php" class="btn btn-primary w-100 py-2 mb-2">Enter Reset Code</a>
        <?php else: ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/forgot_password.php">
                <div class="mb-4">
                    <label for="email" class="form-label">Account Email</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($submittedEmail) ?>" required>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2">Send Reset Code</button>
            </form>
        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" class="small">Back to Login</a>
        </div>
    </div>
</body>
</html>
