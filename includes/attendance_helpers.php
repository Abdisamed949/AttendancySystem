<?php
/**
 * Small presentation helpers shared by notifications.php and
 * student/notifications.php.
 */
declare(strict_types=1);

/**
 * Red below threshold, yellow near it (within 10 points), green at or above it —
 * matches the Chapter Four mockup's color-coded attendance percentage badges.
 */
function attendance_badge_class(float $pct, float $threshold): string
{
    if ($pct < $threshold - 10) {
        return 'badge-absent';
    }
    if ($pct < $threshold) {
        return 'badge-late';
    }

    return 'badge-present';
}
