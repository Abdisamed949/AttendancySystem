<?php
/**
 * Cross-faculty course search — the entry point for cross-listing a
 * course whose catalog home is a DIFFERENT faculty into your own
 * faculty's semester track (see the Multi-Faculty Course Offerings plan).
 * admin/courses.php's own list/dropdowns stay faculty-scoped as before
 * (a Dean only ever sees their own faculty's catalog there), so without
 * this page a Dean would have no way to even discover a course belonging
 * to another faculty in order to cross-list it.
 *
 * Read-only: catalog metadata only (Code/Name/Home Department/Home
 * Faculty/Credit Hours) — no write action lives on this page. Every
 * result's "Add Offering" link goes to admin/course_offerings.php, which
 * is where the actual faculty-scoped write boundary is enforced (System
 * Admin: any faculty; Dean: only their own faculty's semester).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['university_rector', 'head_academic', 'dean']);

$conn = db();
$role = current_role();
$currentUser = current_user();
// This page itself has no write action (pure catalog search) — these two
// flags only drive the per-row link labels/destinations, matching
// whatever the two linked pages (Manage Offerings, Enroll Students)
// actually allow for this role. Dean has full Course/Offerings CRUD
// again within their own faculty; Enroll Students stays a separate,
// still read-only-for-Dean surface (real student data, not schedule
// metadata).
$isReadOnly = $role === 'university_rector';
$enrollmentsReadOnly = in_array($role, ['university_rector', 'dean'], true);

$deanFacultyId = 0;
$deanFacultyName = '';
if ($role === 'dean') {
    $deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
    if ($deanFacultyId > 0) {
        $fStmt = $conn->prepare('SELECT name FROM faculties WHERE id = ?');
        $fStmt->bind_param('i', $deanFacultyId);
        $fStmt->execute();
        $fRow = $fStmt->get_result()->fetch_assoc();
        $fStmt->close();
        $deanFacultyName = $fRow ? (string) $fRow['name'] : '';
    }
}

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

// ---------------------------------------------------------------------
// Search — deliberately university-wide for BOTH roles, since a Dean must
// be able to find a course cataloged under a faculty other than their own
// in order to cross-list it. This page never writes anything; the actual
// faculty write-boundary lives on admin/course_offerings.php.
// ---------------------------------------------------------------------
$searchTerm = trim((string) ($_GET['q'] ?? ''));

if ($searchTerm !== '') {
    $like = '%' . $searchTerm . '%';
    $coursesStmt = $conn->prepare(
        'SELECT c.id, c.code, c.name, c.credit_hours, d.name AS department_name, f.name AS faculty_name, f.id AS faculty_id
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         WHERE c.code LIKE ? OR c.name LIKE ?
         ORDER BY f.name, d.name, c.code'
    );
    $coursesStmt->bind_param('ss', $like, $like);
} else {
    $coursesStmt = $conn->prepare(
        'SELECT c.id, c.code, c.name, c.credit_hours, d.name AS department_name, f.name AS faculty_name, f.id AS faculty_id
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name, c.code'
    );
}
$coursesStmt->execute();
$courses = $coursesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$coursesStmt->close();

// Course codes are only unique WITHIN one department (schema:
// uq_course_code_per_department) — the same code (e.g. "IT101") can
// legitimately exist as several unrelated `courses` rows in different
// departments. That's invisible in a flat code/name search unless flagged:
// an admin cross-listing "the IT101 course" could otherwise pick the wrong
// row and silently split what was meant to be one shared course into two
// disconnected rosters/attendance histories. Counted here so the table can
// warn on every row sharing a code with another result.
$codeOccurrences = [];
foreach ($courses as $c) {
    $codeOccurrences[mb_strtoupper($c['code'])] = ($codeOccurrences[mb_strtoupper($c['code'])] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Existing Course — ADMAS Attendance System</title>
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
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="text-decoration-none">&larr; Back to Courses</a>
                &nbsp;&middot;&nbsp;
                <?php if ($role === 'dean'): ?>
                    Browsing every faculty's catalog (read-only) — you may only create an offering inside <?= htmlspecialchars($deanFacultyName) ?> Faculty's own semester
                <?php elseif ($isReadOnly): ?>
                    Browsing every faculty's catalog — view only (oversight)
                <?php else: ?>
                    Browsing every faculty's catalog — you may create an offering inside any faculty's semester
                <?php endif; ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Add Existing Course</h4>
                    <p class="text-muted mb-0">Find a course cataloged under any faculty, then add an offering for it under <?= $role === 'dean' ? 'your own faculty\'s' : 'a chosen faculty\'s' ?> semester track — for "common" courses taught across more than one faculty at once.</p>
                </div>
            </div>

            <div class="admas-card p-4">
                <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings_search.php" class="d-flex gap-2 mb-3" id="courseOfferingsSearchForm">
                    <input type="text" name="q" class="form-control form-control-sm" style="max-width: 320px;" placeholder="Search by course code or name" data-live-search value="<?= htmlspecialchars($searchTerm) ?>">
                    <button type="submit" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <?php if ($searchTerm !== ''): ?>
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings_search.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                    <?php endif; ?>
                </form>

                <div class="table-responsive">
                    <table class="table admas-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Home Department</th>
                                <th>Home Faculty</th>
                                <th>Credit Hours</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courses)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">No courses found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courses as $c): ?>
                                    <?php
                                    $isOwnFaculty = $role === 'dean' && (int) $c['faculty_id'] === $deanFacultyId;
                                    $isDuplicateCode = ($codeOccurrences[mb_strtoupper($c['code'])] ?? 0) > 1;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);">
                                            <?= htmlspecialchars($c['code']) ?>
                                            <?php if ($isDuplicateCode): ?>
                                                <span class="badge-pill badge-warning" title="This code also exists as a separate course in another department — double-check Home Department/Faculty before adding an offering, so you don't cross-list the wrong one.">
                                                    <i class="bi bi-exclamation-triangle-fill"></i> Shared code
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($c['name']) ?></td>
                                        <td><?= htmlspecialchars($c['department_name']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($c['faculty_name']) ?>
                                            <?php if ($isOwnFaculty): ?>
                                                <span class="badge-pill badge-active">Your Faculty</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int) $c['credit_hours'] ?></td>
                                        <td class="text-end">
                                            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_offerings.php?course_id=<?= (int) $c['id'] ?>" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                                <i class="bi bi-signpost-2"></i> <?= $isReadOnly ? 'View Offerings' : 'Add Offering' ?>
                                            </a>
                                            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/course_enrollments.php?course_id=<?= (int) $c['id'] ?>" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                                <i class="bi bi-person-check"></i> <?= $enrollmentsReadOnly ? 'View Enrollment' : 'Enroll Students' ?>
                                            </a>
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
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/live_filter.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            admasInitLiveFilter('#courseOfferingsSearchForm');
        });
    </script>
</body>
</html>
