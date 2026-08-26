<?php
/**
 * Login page for the ADMAS Attendance System.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/university_logo.php';

$roleToDashboard = [
    'university_rector' => 'admin/dashboard.php',
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
$loginLogoPath = get_university_logo_relative_path($settings);
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

        /* Base (desktop) rules — these must all come BEFORE the @media
           blocks below. CSS gives later same-specificity rules priority
           regardless of whether they're inside a media query, so a media
           override placed earlier in the file than its own base rule gets
           silently beaten by that base rule the instant both declare the
           same property — the bug that broke every mobile override on
           this page (logo/name row layout, then the sky-blue unification)
           until this reordering. */
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

        @media (max-width: 860px) {
            /* Tablet range (576px–860px): side-by-side stays side-by-side
               here — grid-template-columns is deliberately NOT overridden
               (it stays the base rule's "1fr 1fr"), so this block only
               compresses sizing/spacing so both halves' content actually
               fits without overflowing. An overflowing child would widen
               the whole card past the viewport and let the page be dragged
               sideways — the exact "things move" complaint from the
               dashboard cards, so every element in here is deliberately
               sized down rather than left at desktop scale. Each half
               KEEPS its own base color family here (form-panel stays
               white, same two-tone card as desktop) — brand-panel's
               gradient is swapped for a pure sky-blue one (no dark navy
               stop) specifically on mobile, since the desktop navy tone
               read as too dark/not "sky blue" at this size. Padding on
               both halves is also tightened on all four sides so more of
               the compact content shows without wasted space.
               Below 576px (true phone width), the block further down this
               file switches to two STACKED cards instead — this side-by-side
               compression is only the in-between tablet treatment. */
            .login-shell {
                padding: 0;
                align-items: stretch;
            }

            .login-card {
                border-radius: 0;
                box-shadow: none;
                min-height: 100vh;
            }

            .brand-panel,
            .form-panel {
                min-width: 0;
            }

            .brand-panel {
                background: linear-gradient(160deg, #0ea5e9 0%, #38bdf8 100%);
                padding: 1rem 0.6rem;
            }

            .brand-logo-ring {
                width: 72px;
                height: 72px;
                margin: 0 auto 1rem;
            }

            .brand-name-line1 {
                font-size: 1.05rem;
                line-height: 1.15;
                word-break: break-word;
            }

            .brand-name-line2 {
                font-size: 0.72rem;
                letter-spacing: 0.14em;
            }

            .brand-subtitle {
                display: none;
            }

            .form-panel {
                padding: 1.25rem 0.85rem;
            }

            .form-panel h2 {
                font-size: 1.2rem;
            }

            .form-panel p.text-muted {
                font-size: 0.78rem;
            }

            .form-panel .form-label {
                font-size: 0.8rem;
            }

            .form-panel .form-control,
            .form-panel .form-select {
                font-size: 0.85rem;
                padding: 0.5rem 0.6rem;
            }

            .form-panel .forgot-password-link {
                font-size: 0.78rem;
            }

            .form-panel .nav-tabs {
                flex-wrap: nowrap;
            }

            .form-panel .nav-tabs .nav-link {
                font-size: 0.78rem;
                padding: 0.4rem 0.5rem;
                white-space: nowrap;
            }

            .form-panel .btn-primary {
                font-size: 0.85rem;
                padding: 0.55rem;
            }
        }

        @media (max-width: 575.98px) {
            /* True phone width: two separate STACKED cards (brand card on
               top, form card below) instead of the compressed side-by-side
               columns used down to 576px above — undoes the "side-by-side
               all the way down" choice from the 860px block, for this
               narrower range only, per explicit request. Each half becomes
               its own full-width rounded card with its own shadow; the
               page scrolls if the stacked content is taller than the
               screen, which is expected/normal for this layout. */
            .login-shell {
                padding: 1.25rem 0.85rem;
                align-items: flex-start;
            }

            .login-card {
                display: flex;
                flex-direction: column;
                gap: 1rem;
                min-height: 0;
                border-radius: 0;
                box-shadow: none;
                background: transparent;
                overflow: visible;
            }

            .brand-panel,
            .form-panel {
                border-radius: 18px;
                box-shadow: 0 10px 30px rgba(11, 31, 58, 0.2);
                width: 100%;
            }

            .brand-panel {
                padding: 1.5rem 1.25rem;
            }

            .brand-logo-ring {
                width: 64px;
                height: 64px;
                margin-bottom: 0.75rem;
            }

            .brand-name-line1 {
                font-size: 1.05rem;
            }

            .brand-name-line2 {
                font-size: 0.68rem;
                letter-spacing: 0.1em;
            }

            .brand-subtitle {
                display: block;
                font-size: 0.75rem;
            }

            .form-panel {
                padding: 1.25rem 1.1rem;
            }

            .form-panel h2 {
                font-size: 1.15rem;
            }

            .form-panel .nav-tabs .nav-link {
                padding: 0.4rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-shell">
        <div class="login-card">
            <div class="brand-panel">
                <div class="brand-logo-ring">
                    <img src="<?= htmlspecialchars(BASE_URL . '/' . $loginLogoPath) ?>" alt="<?= htmlspecialchars($universityName) ?> logo" class="brand-logo">
                </div>
                <div class="brand-text">
                    <div class="brand-name-line1"><?= htmlspecialchars($universityName) ?></div>
                    <div class="brand-name-line2"><?= htmlspecialchars($campusShort) ?></div>
                    <div class="brand-subtitle">Attendance System</div>
                </div>
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

                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="passwordTabBtn" data-bs-toggle="tab" data-bs-target="#passwordTabPane" type="button" role="tab">Password</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="qrTabBtn" data-bs-toggle="tab" data-bs-target="#qrTabPane" type="button" role="tab">
                                <i class="bi bi-qr-code"></i> <span class="qr-tab-label">QR Code Scan</span>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="passwordTabPane" role="tabpanel">
                            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/login.php">
                                <div class="mb-3">
                                    <label for="role" class="form-label">Role</label>
                                    <select class="form-select" id="role" name="role" required>
                                        <option value="">Select role</option>
                                        <option value="university_rector" <?= ($submittedRole === 'university_rector') ? 'selected' : '' ?>>University Rector</option>
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
                            <div class="text-center mt-3">
                                <a href="<?= htmlspecialchars(BASE_URL) ?>/register.php" class="small">New student? Register</a>
                            </div>
                        </div>

                        <div class="tab-pane fade text-center" id="qrTabPane" role="tabpanel">
                            <p class="text-muted small mb-3">Scan with a phone you've already linked from Profile &amp; Password — no username or password needed.</p>
                            <img id="qrLoginImage" alt="Login QR code" style="width: 220px; height: 220px; display: none;" class="border rounded p-2 mb-2">
                            <div id="qrLoginLoading" class="text-muted small">&nbsp;</div>
                            <div id="qrLoginStatus" class="small fw-semibold mt-2"></div>
                            <button type="button" id="qrLoginRefreshBtn" class="btn btn-sm btn-outline-secondary mt-2 d-none">Generate New Code</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>window.ADMAS_BASE_URL = <?= json_encode(BASE_URL, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/password-toggle.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/qr_login.js"></script>
</body>
</html>
