<?php
/**
 * Reports Hub — one front door gathering every report/analytics screen
 * this app has, instead of leaving them spread across separate sidebar
 * links (reports.php's own 6 report types, Teaching History, Lecturer
 * Check-Ins). Purely a set of role-aware links — every card's own page
 * still enforces its own real scoping/access rules independently; this
 * page adds no new data access of its own.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';

require_role(['university_rector', 'head_academic', 'dean', 'registration', 'lecturer']);

$conn = db();
$currentUser = current_user();
$role = current_role();

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

/**
 * One entry per report/analytics screen. `new` just drives the small
 * "New" badge — every one of these already works as its own real page;
 * this hub only links to them.
 */
$hubCards = [
    [
        'label' => 'Course Attendance Summary',
        'desc' => 'Present/absent rate per course, one semester at a time.',
        'icon' => 'bi-clipboard-data',
        'href' => 'reports.php?report_type=course_attendance',
        'roles' => ['university_rector', 'head_academic', 'dean', 'lecturer'],
        'new' => false,
    ],
    [
        'label' => 'Department Summary',
        'desc' => 'Roll-up of every course inside one department.',
        'icon' => 'bi-diagram-3',
        'href' => 'reports.php?report_type=department_summary',
        'roles' => ['university_rector', 'head_academic', 'dean', 'registration'],
        'new' => false,
    ],
    [
        'label' => 'Faculty Summary',
        'desc' => 'Whole-faculty average, across every department.',
        'icon' => 'bi-bank',
        'href' => 'reports.php?report_type=faculty_summary',
        'roles' => ['university_rector', 'head_academic', 'registration'],
        'new' => false,
    ],
    [
        'label' => 'Xiiso Attendance Grid',
        'desc' => 'All 12 sessions, one row per student, P/A/% at the end.',
        'icon' => 'bi-grid-3x3',
        'href' => 'reports.php?report_type=xiiso_grid',
        'roles' => ['university_rector', 'head_academic', 'dean', 'lecturer'],
        'new' => false,
    ],
    [
        'label' => 'At-Risk Students',
        'desc' => 'Not below the threshold yet, but close enough to flag now.',
        'icon' => 'bi-exclamation-diamond',
        'href' => 'reports.php?report_type=at_risk_students',
        'roles' => ['university_rector', 'head_academic', 'dean'],
        'new' => true,
    ],
    [
        'label' => 'Lecturer Recording Rate',
        'desc' => 'Of the sessions expected so far, how many has each lecturer actually marked?',
        'icon' => 'bi-speedometer2',
        'href' => 'reports.php?report_type=lecturer_recording_rate',
        'roles' => ['university_rector', 'head_academic', 'dean'],
        'new' => true,
    ],
    [
        'label' => 'Teaching History',
        'desc' => 'Your own teaching record, semester by semester.',
        'icon' => 'bi-journal-bookmark',
        'href' => 'lecturer/teaching_history.php',
        'roles' => ['lecturer'],
        'new' => false,
    ],
    [
        'label' => 'Lecturer Check-Ins',
        'desc' => 'Arrival/departure times per class session.',
        'icon' => 'bi-door-open',
        'href' => 'lecturer_checkins.php',
        'roles' => ['university_rector', 'head_academic', 'dean'],
        'new' => false,
    ],
];

$visibleCards = array_values(array_filter($hubCards, static fn ($c) => in_array($role, $c['roles'], true)));

$scopeBanner = match ($role) {
    'university_rector' => 'Access scope: Full system — every report this role can reach',
    'head_academic' => 'Access scope: All faculties — every report this role can reach',
    'dean' => 'Access scope: Own faculty only — enforced on each report page',
    'registration' => 'Access scope: All faculties — enrollment-focused reports only',
    'lecturer' => 'Access scope: Your own assigned courses only',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Hub — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        .hub-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 1.25rem;
        }
        .hub-card {
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
            min-height: 180px;
            text-decoration: none;
            color: inherit;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .hub-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 28px var(--admas-shadow);
            border-color: var(--admas-sky);
        }
        .hub-icon {
            width: 54px;
            height: 54px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgb(14 165 233 / var(--admas-tint-opacity));
            color: var(--admas-sky);
            font-size: 1.5rem;
        }
        .hub-card h3 {
            font-size: 1.15rem;
            font-weight: 700;
            margin: 0;
            color: var(--admas-text);
        }
        .hub-card p {
            font-size: 0.9rem;
            color: var(--admas-text-muted);
            margin: 0;
            line-height: 1.5;
        }
        .hub-new-badge {
            display: inline-flex;
            align-items: center;
            background: rgb(22 163 74 / 0.14);
            color: #16a34a;
            font-size: 0.62rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 0.15rem 0.45rem;
            border-radius: 999px;
            margin-left: 0.4rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                <?= htmlspecialchars($scopeBanner) ?>
            </div>

            <div class="mb-4">
                <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Reports Hub</h4>
                <p class="text-muted mb-0">Every report you can generate, in one place.</p>
            </div>

            <?php if (empty($visibleCards)): ?>
                <div class="admas-card p-4 text-center text-muted py-5">
                    No reports are available for your role yet.
                </div>
            <?php else: ?>
                <div class="hub-grid">
                    <?php foreach ($visibleCards as $card): ?>
                        <a href="<?= htmlspecialchars(BASE_URL . '/' . $card['href']) ?>" class="admas-card hub-card p-4">
                            <div class="hub-icon"><i class="bi <?= htmlspecialchars($card['icon']) ?>"></i></div>
                            <h3>
                                <?= htmlspecialchars($card['label']) ?>
                                <?php if ($card['new']): ?><span class="hub-new-badge">New</span><?php endif; ?>
                            </h3>
                            <p><?= htmlspecialchars($card['desc']) ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
