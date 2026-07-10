<?php
/**
 * Shown when require_role() rejects a logged-in user's role for the page
 * they requested (e.g. a Dean typing an admin-only URL directly). Any
 * logged-in role can land here, so this only requires login, not a role.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';

require_login();

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

$ownDashboardUrl = BASE_URL . '/' . role_folder(current_role()) . '/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div class="page-body">
            <div class="admas-card p-5 text-center" style="max-width: 560px; margin: 3rem auto;">
                <i class="bi bi-shield-lock" style="font-size: 3rem; color: #dc2626;"></i>
                <h4 class="fw-bold mt-3 mb-2" style="color: #0b1f3a;">Access Denied</h4>
                <p class="text-muted mb-4">You don't have permission to view that page with your current role.</p>
                <a href="<?= htmlspecialchars($ownDashboardUrl) ?>" class="btn btn-primary" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                    <i class="bi bi-speedometer2"></i> Back to My Dashboard
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
