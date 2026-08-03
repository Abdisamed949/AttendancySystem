<?php
/**
 * Shared sidebar partial. Expects auth.php + nav_items.php already loaded.
 * Renders only the nav items the current session's role is allowed to see.
 */
declare(strict_types=1);

require_once __DIR__ . '/university_logo.php';

$activeRole = current_role();
$activeFolder = role_folder($activeRole);
$activeScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$sidebarUniversityName = $settings['university_name'] ?? 'ADMAS University';
$sidebarLogoPath = get_university_logo_relative_path($settings ?? []);
?>
<script>
    (function () {
        if (localStorage.getItem('admas-theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
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
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</aside>
