<?php
/**
 * Shared sidebar partial. Expects auth.php + nav_items.php already loaded.
 * Renders only the nav items the current session's role is allowed to see.
 */
declare(strict_types=1);

require_once __DIR__ . '/chat_helpers.php';
require_once __DIR__ . '/university_logo.php';

$activeRole = current_role();
$activeFolder = role_folder($activeRole);
$activeScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
// "<Role Name> Role" (e.g. "Student Role", "Dean Role") — a sidebar-only
// display convention, so role_label() itself stays plain everywhere else
// it's already used (User Management tables, scope banners, etc.).
$sidebarRoleLabel = role_label($activeRole) . ' Role';
$sidebarUserName = trim((string) ($currentUser['full_name'] ?? ''));
$sidebarPhotoPath = (string) ($currentUser['photo_path'] ?? '');

// Mobile-only university brand (logo + name) — shown above .sidebar-profile
// specifically on mobile widths, since the drawer no longer carries any
// university branding at all once the profile block moved into
// includes/topbar.php's always-visible mobile strip. Desktop is unchanged
// (the sidebar there still opens directly on .sidebar-profile).
$sidebarUniversityName = ($settings ?? [])['university_name'] ?? 'ADMAS University';
$sidebarLogoRelativePath = get_university_logo_relative_path($settings ?? []);

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
    <div class="sidebar-mobile-brand">
        <img src="<?= htmlspecialchars(BASE_URL . '/' . $sidebarLogoRelativePath) ?>" alt="">
        <span class="sidebar-mobile-brand-name"><?= htmlspecialchars($sidebarUniversityName) ?></span>
    </div>
    <div class="sidebar-profile">
        <span class="sidebar-profile-role"><?= htmlspecialchars($sidebarRoleLabel) ?></span>
        <?php if ($sidebarPhotoPath !== ''): ?>
            <img class="sidebar-profile-photo" width="64" height="64"
                 src="<?= htmlspecialchars(BASE_URL) ?>/uploads/profile_photos/<?= htmlspecialchars($sidebarPhotoPath) ?>" alt="">
        <?php else: ?>
            <div class="sidebar-profile-photo-fallback"><i class="bi bi-person-fill"></i></div>
        <?php endif; ?>
        <span class="sidebar-profile-name"><?= htmlspecialchars($sidebarUserName !== '' ? $sidebarUserName : $sidebarRoleLabel) ?></span>
    </div>
    <nav class="sidebar-nav">
        <?php $sidebarLastGroup = null; ?>
        <?php foreach (nav_items() as $item): ?>
            <?php if (in_array($activeRole, $item['roles'], true)): ?>
                <?php if (($item['group'] ?? null) !== $sidebarLastGroup): ?>
                    <?php $sidebarLastGroup = $item['group']; ?>
                    <div class="sidebar-group-title"><?= htmlspecialchars($sidebarLastGroup) ?></div>
                <?php endif; ?>
                <?php $itemHref = $item['path'] ?? ($activeFolder . '/' . $item['file']); ?>
                <a href="<?= htmlspecialchars(BASE_URL . '/' . $itemHref) ?>"
                   class="sidebar-link<?= $activeScript === $item['file'] ? ' active' : '' ?><?= $item['file'] === 'logout.php' ? ' sidebar-link-logout' : '' ?>">
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
<script>
    // .sidebar-nav scrolls internally when a role has more nav items than
    // fit on screen (University Rector / Head of Academic Affairs both do)
    // — every link click is a real page reload, and a freshly-loaded page's
    // scrollable div always starts at scrollTop 0 by default, so without
    // this a bottom-of-the-list item (Settings, Audit Log, Logout, etc.)
    // would visibly "jump back up" to the top of the list on every single
    // click, forcing a re-scroll down each time. Persists per role (not one
    // flat key) so a shorter-listed role never inherits a scroll position
    // that only makes sense for a longer one.
    (function () {
        var nav = document.querySelector('.sidebar-nav');
        if (!nav) {
            return;
        }
        var storageKey = 'admas-sidebar-scroll:<?= htmlspecialchars($activeRole, ENT_QUOTES) ?>';

        var saved = sessionStorage.getItem(storageKey);
        if (saved !== null) {
            nav.scrollTop = parseInt(saved, 10) || 0;
        }

        var pending = false;
        nav.addEventListener('scroll', function () {
            if (pending) {
                return;
            }
            pending = true;
            window.requestAnimationFrame(function () {
                sessionStorage.setItem(storageKey, String(nav.scrollTop));
                pending = false;
            });
        }, { passive: true });
    })();
</script>
