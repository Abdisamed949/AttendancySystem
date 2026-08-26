<?php
/**
 * Shared "who is this" list-row rendering — a circular uploaded photo
 * (users.photo_path) when one exists, otherwise a colour-coded circle
 * showing the person's first initial (same colour every time for the same
 * name, derived from the name itself, not random per page load). Used
 * everywhere a person appears in a list: admin/students.php,
 * admin/lecturers.php, admin/users.php, head_academic/users.php,
 * head_academic/lecturers.php, and attendance.php's roster/grid.
 */
declare(strict_types=1);

const AVATAR_FALLBACK_COLORS = [
    '#0ea5e9', '#0369a1', '#7c3aed', '#0e7490', '#15803d',
    '#b45309', '#be185d', '#4338ca', '#0f766e', '#334155',
];

/**
 * Deterministic fallback colour for a name — same name always gets the
 * same colour, without needing to store one anywhere.
 */
function avatar_fallback_color(string $name): string
{
    $index = crc32($name) % count(AVATAR_FALLBACK_COLORS);

    return AVATAR_FALLBACK_COLORS[$index];
}

/**
 * Renders one "who" cell: photo-or-initial avatar + name + optional
 * sub-line (student no / staff no / username / role...). Echoes HTML
 * directly (matching this app's existing inline-render helper
 * convention, e.g. render_scope_breadcrumb()).
 */
function render_person_avatar_cell(?string $photoPath, string $fullName, string $subLine = '', bool $small = false): void
{
    $initial = mb_strtoupper(mb_substr(trim($fullName) !== '' ? trim($fullName) : '?', 0, 1));
    $color = avatar_fallback_color($fullName);
    $sizeClass = $small ? ' who-sm' : '';
    ?>
    <div class="who-cell<?= $sizeClass ?>">
        <?php if (!empty($photoPath)): ?>
            <img class="who-avatar" src="<?= htmlspecialchars(BASE_URL) ?>/uploads/profile_photos/<?= htmlspecialchars($photoPath) ?>" alt="">
        <?php else: ?>
            <div class="who-avatar-fallback" style="background-color: <?= htmlspecialchars($color) ?>;"><?= htmlspecialchars($initial) ?></div>
        <?php endif; ?>
        <div>
            <div class="who-text-name"><?= htmlspecialchars($fullName) ?></div>
            <?php if ($subLine !== ''): ?>
                <div class="who-text-sub"><?= htmlspecialchars($subLine) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * One row of a dashboard leaderboard (Lecturer Workload / Lecturer
 * Check-In Ranking on admin/dean/head_academic dashboard.php) — avatar +
 * name, a progress bar, and a score. The bar/score are always shown on a
 * fixed 0-10 scale (capped, same "out of 10" convention as
 * ATTENDANCE_MAX_SCORE elsewhere in this app) rather than relative to
 * whatever the current top row's raw count happens to be — a raw count
 * (e.g. Check-In totals of 25 vs. Workload counts of 3) looks
 * disproportionate/arbitrary next to a same-shaped widget on a totally
 * different scale; capping both to /10 keeps every leaderboard visually
 * consistent and comparable. The real, uncapped count is still shown in a
 * hover tooltip, so nothing is hidden, just not the headline number.
 */
function render_dash_rank_row(?string $photoPath, string $fullName, int $rawCount, string $barColor): void
{
    $capped = min(10, max(0, $rawCount));
    $pct = $capped * 10;
    ?>
    <div class="dash-rank-row">
        <?php render_person_avatar_cell($photoPath, $fullName, '', true); ?>
        <div class="dash-rank-bar-wrap" title="<?= (int) $rawCount ?> total">
            <div class="progress" style="height: 6px;">
                <div class="progress-bar" style="width: <?= $pct ?>%; background-color: <?= htmlspecialchars($barColor) ?>;"></div>
            </div>
        </div>
        <span class="dash-rank-count" title="<?= (int) $rawCount ?> total"><?= $capped ?>/10</span>
    </div>
    <?php
}
