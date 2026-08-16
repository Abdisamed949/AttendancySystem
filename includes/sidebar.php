<?php
/**
 * Shared sidebar partial. Expects auth.php + nav_items.php already loaded.
 * Renders only the nav items the current session's role is allowed to see.
 */
declare(strict_types=1);

require_once __DIR__ . '/university_logo.php';
require_once __DIR__ . '/chat_helpers.php';

$activeRole = current_role();
$activeFolder = role_folder($activeRole);
$activeScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$sidebarUniversityName = $settings['university_name'] ?? 'ADMAS University';
$sidebarLogoPath = get_university_logo_relative_path($settings ?? []);

// Unread Staff Messages badge on the sidebar's own "Messages" link — same
// one extra count-query-per-page-load pattern as topbar.php's notif bell.
$sidebarUnreadMessages = 0;
if (in_array($activeRole, CHAT_STAFF_ROLES, true) && !empty($_SESSION['user_id'])) {
    $unreadMsgStmt = db()->prepare('SELECT COUNT(*) AS c FROM messages WHERE receiver_id = ? AND is_read = 0');
    $unreadMsgStmt->bind_param('i', $_SESSION['user_id']);
    $unreadMsgStmt->execute();
    $sidebarUnreadMessages = (int) ($unreadMsgStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $unreadMsgStmt->close();
}
?>
<script>
    (function () {
        if (localStorage.getItem('admas-theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }

        // A live-filter reload (see assets/js/live_filter.js) leaves a
        // pending scroll position for this exact page — hide the page
        // until that script (loaded later, once the full page is parsed)
        // restores it and reveals the page again, so the reload never
        // visibly flashes at the top before jumping back down.
        if (sessionStorage.getItem('admasFilterScroll:' + window.location.pathname) !== null) {
            document.documentElement.style.visibility = 'hidden';
        }
    })();
</script>
<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="<?= htmlspecialchars(BASE_URL . '/' . $sidebarLogoPath) ?>" alt="<?= htmlspecialchars($sidebarUniversityName) ?> logo">
        <div>
            <span class="brand-title"><?= htmlspecialchars($sidebarUniversityName) ?></span>
            <span class="brand-subtitle">Attendance System</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <?php foreach (nav_items() as $item): ?>
            <?php if (in_array($activeRole, $item['roles'], true)): ?>
                <?php $itemHref = $item['path'] ?? ($activeFolder . '/' . $item['file']); ?>
                <a href="<?= htmlspecialchars(BASE_URL . '/' . $itemHref) ?>"
                   class="sidebar-link<?= $activeScript === $item['file'] ? ' active' : '' ?>">
                    <i class="bi <?= htmlspecialchars($item['icon']) ?>"></i>
                    <span><?= htmlspecialchars($item['label']) ?></span>
                    <?php if ($item['file'] === 'messages.php' && $sidebarUnreadMessages > 0): ?>
                        <span class="chat-contact-unread ms-auto"><?= $sidebarUnreadMessages ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</aside>
