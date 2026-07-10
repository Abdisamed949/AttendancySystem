<?php
/**
 * Login page for the ADMAS Attendance System.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$roleToDashboard = [
    'system_admin' => 'admin/dashboard.php',
    'head_academic' => 'head_academic/dashboard.php',
    'registration' => 'registration/dashboard.php',
    'dean' => 'dean/dashboard.php',
    'lecturer' => 'lecturer/dashboard.php',
    'student' => 'student/dashboard.php',
];

$errorMessage = '';
$submittedRole = '';
$submittedIdentifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedIdentifier = trim((string) ($_POST['username_or_email'] ?? ''));
    $submittedRole = trim((string) ($_POST['role'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($submittedIdentifier !== '' && $password !== '' && $submittedRole !== '') {
        $conn = db();
        $stmt = $conn->prepare(
            'SELECT u.id, u.password_hash, u.full_name, u.faculty_id, u.must_change_password, r.name AS role_name ' .
            'FROM users u ' .
            'JOIN roles r ON r.id = u.role_id ' .
            'WHERE (u.username = ? OR u.email = ?) ' .
            'LIMIT 1'
        );

        if ($stmt) {
            $stmt->bind_param('ss', $submittedIdentifier, $submittedIdentifier);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password_hash'])) {
                $dbRole = (string) ($user['role_name'] ?? '');

                if ($submittedRole === $dbRole) {
                    $mustChangePassword = (int) $user['must_change_password'] === 1;

                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['role'] = $dbRole;
                    $_SESSION['full_name'] = (string) $user['full_name'];
                    $_SESSION['faculty_id'] = $dbRole === 'dean' ? (int) $user['faculty_id'] : null;
                    $_SESSION['must_change_password'] = $mustChangePassword;

                    $targetDashboard = $mustChangePassword
                        ? role_folder($dbRole) . '/profile.php'
                        : ($roleToDashboard[$dbRole] ?? 'index.php');
                    redirect_to($targetDashboard);
                }
            }
        }
    }

    $errorMessage = 'Invalid username, password, or role';
}

// ---------------------------------------------------------------------
// University settings (drives the brand panel's name — this page has no
// session yet, so it reads settings directly rather than via the shared
// sidebar/topbar includes).
// ---------------------------------------------------------------------
$settings = [];
$settingsResult = db()->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$universityName = $settings['university_name'] ?? 'ADMAS University';
$campusLine = $settings['campus'] ?? 'Garoowe Campus';
// Second brand line is the campus setting with a trailing "Campus" word
// stripped (e.g. "Garoowe Campus" -> "Garoowe"), kept data-driven rather
// than hardcoded per CLAUDE.md's branding rule.
$campusShort = trim((string) preg_replace('/\s*campus\s*$/i', '', $campusLine));
if ($campusShort === '') {
    $campusShort = $campusLine;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADMAS Attendance Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #0ea5e9 0%, #38bdf8 45%, #7dd3fc 100%);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .login-card {
            width: 100%;
            max-width: 980px;
            min-height: 560px;
            background: #fff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(11, 31, 58, 0.35);
            display: grid;
            grid-template-columns: 1fr 1fr;
        }

        @media (max-width: 860px) {
            .login-card {
                grid-template-columns: 1fr;
            }
        }

        .brand-panel {
            background: linear-gradient(160deg, #0b1f3a 0%, #0ea5e9 100%);
            color: #fff;
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .form-panel {
            background: #fff;
            padding: 3rem 2.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-logo-ring {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.55);
            padding: 6px;
            margin: 0 auto 1.75rem;
            background: rgba(255, 255, 255, 0.08);
        }

        .brand-logo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            background: #fff;
        }

        .brand-name-line1 {
            font-family: "Playfair Display", Georgia, serif;
            font-weight: 800;
            font-size: 2rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 0.15rem;
        }

        .brand-name-line2 {
            font-family: "Playfair Display", Georgia, serif;
            font-weight: 700;
            font-size: 1.35rem;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: #bfe8fd;
            margin-bottom: 1rem;
        }

        .brand-subtitle {
            font-size: 0.95rem;
            font-weight: 500;
            opacity: 0.85;
            letter-spacing: 0.03em;
        }

        .form-control:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 0.2rem rgba(14, 165, 233, 0.15);
        }

        .btn-primary {
            background-color: #0ea5e9;
            border-color: #0ea5e9;
        }

        .forgot-password-link {
            color: #0ea5e9;
            text-decoration: none;
            font-weight: 600;
        }

        .forgot-password-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-shell">
        <div class="login-card">
            <div class="brand-panel">
                <div class="brand-logo-ring">
                    <img src="<?= htmlspecialchars(BASE_URL) ?>/logo/logo.jpg" alt="<?= htmlspecialchars($universityName) ?> logo" class="brand-logo">
                </div>
                <div class="brand-name-line1"><?= htmlspecialchars($universityName) ?></div>
                <div class="brand-name-line2"><?= htmlspecialchars($campusShort) ?></div>
                <div class="brand-subtitle">Attendance System</div>
            </div>
            <div class="form-panel">
                <div class="w-100" style="max-width: 400px;">
                    <h2 class="fw-bold mb-2">Welcome Back</h2>
                    <p class="text-muted mb-4">Sign in to continue to your dashboard.</p>

                    <?php if ($errorMessage !== ''): ?>
                        <div class="alert alert-danger" role="alert">
                            <?= htmlspecialchars($errorMessage) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/login.php">
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="">Select role</option>
                                <option value="system_admin" <?= ($submittedRole === 'system_admin') ? 'selected' : '' ?>>System Administrator</option>
                                <option value="head_academic" <?= ($submittedRole === 'head_academic') ? 'selected' : '' ?>>Head of Academic Affairs</option>
                                <option value="registration" <?= ($submittedRole === 'registration') ? 'selected' : '' ?>>Registration Office</option>
                                <option value="dean" <?= ($submittedRole === 'dean') ? 'selected' : '' ?>>Dean</option>
                                <option value="lecturer" <?= ($submittedRole === 'lecturer') ? 'selected' : '' ?>>Lecturer</option>
                                <option value="student" <?= ($submittedRole === 'student') ? 'selected' : '' ?>>Student</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="username_or_email" class="form-label">Username / Email</label>
                            <input type="text" class="form-control" id="username_or_email" name="username_or_email" value="<?= htmlspecialchars($submittedIdentifier) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password" aria-label="Show password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-4 text-end">
                            <a href="<?= htmlspecialchars(BASE_URL) ?>/forgot_password.php" class="forgot-password-link small">Forgot Password?</a>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2">Sign In</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/password-toggle.js"></script>
</body>
</html>
