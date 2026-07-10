<?php
/**
 * Shared sky-blue strip + white topbar partial.
 * Expects $settings (array<string,string>) and $currentUser (array) to be set
 * by the including page before this file is included.
 */
declare(strict_types=1);

$universityName = $settings['university_name'] ?? 'ADMAS University';
$campus = $settings['campus'] ?? '';
$contactEmail = $settings['contact_email'] ?? '';
$contactPhone = $settings['contact_phone'] ?? '';

$initials = '';
foreach (preg_split('/\s+/', trim((string) ($currentUser['full_name'] ?? ''))) as $part) {
    if ($part !== '') {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
}
$initials = mb_substr($initials, 0, 2) ?: '?';

$roleLabel = role_label(current_role());

// ---------------------------------------------------------------------
// Unread notifications bell — scoped the same way as notifications.php:
// system_admin/head_academic see the system-wide unread count, dean sees
// only their own faculty's, and a student sees only their own alerts.
// Other roles (registration, lecturer) have no notifications feature yet,
// so the bell renders as a plain non-navigating icon for them.
// ---------------------------------------------------------------------
$notifBadgeCount = 0;
$notifLinkUrl = null;
$topbarRole = current_role();
$topbarConn = db();

if ($topbarRole === 'system_admin' || $topbarRole === 'head_academic') {
    $countResult = $topbarConn->query('SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0');
    $notifBadgeCount = (int) (($countResult ? $countResult->fetch_assoc() : null)['c'] ?? 0);
    $notifLinkUrl = BASE_URL . '/notifications.php';
} elseif ($topbarRole === 'dean') {
    $deanFacultyIdForNotif = (int) ($_SESSION['faculty_id'] ?? 0);
    $countStmt = $topbarConn->prepare(
        'SELECT COUNT(*) AS c FROM notifications n JOIN students s ON s.id = n.student_id WHERE n.is_read = 0 AND s.faculty_id = ?'
    );
    $countStmt->bind_param('i', $deanFacultyIdForNotif);
    $countStmt->execute();
    $notifBadgeCount = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $countStmt->close();
    $notifLinkUrl = BASE_URL . '/notifications.php';
} elseif ($topbarRole === 'student') {
    $ownStudentStmt = $topbarConn->prepare('SELECT id FROM students WHERE user_id = ?');
    $ownStudentStmt->bind_param('i', $currentUser['id']);
    $ownStudentStmt->execute();
    $ownStudentRow = $ownStudentStmt->get_result()->fetch_assoc();
    $ownStudentStmt->close();

    if ($ownStudentRow) {
        $countStmt = $topbarConn->prepare('SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0 AND student_id = ?');
        $countStmt->bind_param('i', $ownStudentRow['id']);
        $countStmt->execute();
        $notifBadgeCount = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $countStmt->close();
    }
    $notifLinkUrl = BASE_URL . '/student/notifications.php';
}
?>
<div class="top-strip">
    <div class="top-strip-left">
        <i class="bi bi-geo-alt-fill"></i>
        <span><?= htmlspecialchars($universityName . ($campus !== '' ? ' — ' . $campus : '')) ?></span>
    </div>
    <div class="top-strip-right">
        <?php if ($contactEmail !== ''): ?>
            <span><i class="bi bi-envelope-fill"></i><?= htmlspecialchars($contactEmail) ?></span>
        <?php endif; ?>
        <?php if ($contactPhone !== ''): ?>
            <span><i class="bi bi-telephone-fill"></i><?= htmlspecialchars($contactPhone) ?></span>
        <?php endif; ?>
    </div>
</div>
<div class="topbar">
    <div class="topbar-search">
        <i class="bi bi-search"></i>
        <input type="text" placeholder="Search...">
    </div>
    <div class="topbar-right">
        <?php if ($notifLinkUrl !== null): ?>
            <a href="<?= htmlspecialchars($notifLinkUrl) ?>" class="notif-bell" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <?php if ($notifBadgeCount > 0): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </a>
        <?php else: ?>
            <button type="button" class="notif-bell" aria-label="Notifications">
                <i class="bi bi-bell"></i>
            </button>
        <?php endif; ?>
        <div class="topbar-user">
            <div class="avatar-initials"><?= htmlspecialchars($initials) ?></div>
            <div class="user-meta">
                <span class="user-name"><?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?></span>
                <span class="user-role"><?= htmlspecialchars($roleLabel) ?></span>
            </div>
        </div>
        <a href="<?= htmlspecialchars(BASE_URL) ?>/logout.php" class="logout-btn" aria-label="Logout" title="Logout">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</div>
