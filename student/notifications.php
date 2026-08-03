<?php
/**
 * A student's own notifications — read-only, and strictly limited to
 * alerts that were actually raised about this student (rows in the
 * `notifications` table), never a live cross-student view like
 * notifications.php uses for the management roles.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['student']);

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
$minAttendancePct = (float) ($settings['min_attendance_pct'] ?? 75);

// ---------------------------------------------------------------------
// Resolve this login's own students.id — the only id this page will ever
// query by, so there is no way for a student to see another student's alerts.
// ---------------------------------------------------------------------
$ownStudentId = 0;
$stmt = $conn->prepare('SELECT id FROM students WHERE user_id = ?');
$stmt->bind_param('i', $currentUser['id']);
$stmt->execute();
$ownRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ownRow) {
    $ownStudentId = (int) $ownRow['id'];
}

// ---------------------------------------------------------------------
// Handle "Mark as read" (POST) — scoped to student_id = $ownStudentId so a
// crafted request can never mark another student's notification as read.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'mark_read' && $ownStudentId > 0) {
    $notifId = (int) ($_POST['notification_id'] ?? 0);
    $updateStmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND student_id = ?');
    $updateStmt->bind_param('ii', $notifId, $ownStudentId);
    $updateStmt->execute();
    $updateStmt->close();
    redirect_to('student/notifications.php');
}

// ---------------------------------------------------------------------
// This student's own notifications only
// ---------------------------------------------------------------------
$notifications = [];
if ($ownStudentId > 0) {
    $listStmt = $conn->prepare(
        "SELECT n.id, n.attendance_pct, n.threshold_at_time, n.is_read, n.created_at,
                c.code, c.name AS course_name
         FROM notifications n
         JOIN courses c ON c.id = n.course_id
         WHERE n.student_id = ?
         ORDER BY n.created_at DESC"
    );
    $listStmt->bind_param('i', $ownStudentId);
    $listStmt->execute();
    $notifications = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $listStmt->close();
}

$unreadCount = count(array_filter($notifications, static fn ($n) => (int) $n['is_read'] === 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Notifications — ADMAS Attendance System</title>
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
                Access scope: Your own attendance alerts only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">My Notifications</h4>
                    <p class="text-muted mb-0">Attendance alerts your Dean, Head of Academic Affairs, or the System Administrator have raised for you.</p>
                </div>
            </div>

            <div class="admas-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Alerts</h6>
                    <span class="badge-pill badge-inactive"><?= (int) $unreadCount ?> unread</span>
                </div>

                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Attendance %</th>
                                <th>Threshold at the Time</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($notifications)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">You have no attendance alerts.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($notifications as $n): ?>
                                    <?php $pct = (float) $n['attendance_pct']; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($n['code'] . ' — ' . $n['course_name']) ?></td>
                                        <td><span class="badge-pill <?= attendance_badge_class($pct, (float) $n['threshold_at_time']) ?>"><?= number_format($pct, 1) ?>%</span></td>
                                        <td><?= number_format((float) $n['threshold_at_time'], 1) ?>%</td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime((string) $n['created_at']))) ?></td>
                                        <td>
                                            <?php if ((int) $n['is_read'] === 0): ?>
                                                <span class="badge-pill badge-absent">Unread</span>
                                            <?php else: ?>
                                                <span class="badge-pill badge-active">Read</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ((int) $n['is_read'] === 0): ?>
                                                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/student/notifications.php" style="display:inline;">
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                                                    <button type="submit" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">Mark as Read</button>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
