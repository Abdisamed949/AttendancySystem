<?php
/**
 * My Courses (Student only) — the courses this student takes, plus their
 * own attendance % in each, for a Semester the student picks from a
 * dropdown (every semester they have attendance history in — not just the
 * current one). Resolved via current_user()['id'] -> students.user_id,
 * never from request input (same pattern as student/dashboard.php).
 *
 * Course discovery: tries `course_enrollments` first (the real enrollment
 * record); if this student has zero enrollment rows, falls back to every
 * course in the student's own department — the same
 * enrolled-or-department-fallback assumption attendance.php's roster query
 * already makes in the opposite direction (course -> students). A third,
 * additive source also surfaces any course cross-listed into this
 * student's own department via a course_offerings row's
 * roster_department_id (see the Multi-Faculty Course Offerings plan) —
 * the course's own catalog home may be a different faculty entirely.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';
require_once __DIR__ . '/../includes/semester_helpers.php';

require_role(['student']);

$conn = db();
$currentUser = current_user();

// ---------------------------------------------------------------------
// University settings (drives the sky-blue top strip + threshold)
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
// Own students.id + department_id + faculty_id (never trusted from input)
// ---------------------------------------------------------------------
$ownStmt = $conn->prepare('SELECT id, department_id, faculty_id, shift FROM students WHERE user_id = ?');
$ownStmt->bind_param('i', $currentUser['id']);
$ownStmt->execute();
$ownRow = $ownStmt->get_result()->fetch_assoc();
$ownStmt->close();
$ownStudentId = $ownRow ? (int) $ownRow['id'] : 0;
$ownDepartmentId = $ownRow ? (int) $ownRow['department_id'] : 0;
$ownShift = (string) ($ownRow['shift'] ?? '');

$ownFacultyId = (int) ($ownRow['faculty_id'] ?? 0);

// ---------------------------------------------------------------------
// Semester picker — one box per "Semester 1".."Semester {total_semesters}"
// for this student's own faculty (the same numbering
// semesters.php's Create Semester dropdown uses), not just the semesters
// this student happens to already have attendance rows in. A semester
// number with no real `semesters` row yet renders as a disabled box
// ("not created yet") instead of being silently omitted, so the student
// can see their whole program's shape even before every semester has been
// entered into the system.
// ---------------------------------------------------------------------
$facultyTotalSemesters = 0;
if ($ownFacultyId > 0) {
    $facStmt = $conn->prepare('SELECT total_semesters FROM faculties WHERE id = ?');
    $facStmt->bind_param('i', $ownFacultyId);
    $facStmt->execute();
    $facultyTotalSemesters = (int) ($facStmt->get_result()->fetch_assoc()['total_semesters'] ?? 0);
    $facStmt->close();
}

// Real semester rows for this faculty, keyed by name. The DB allows two
// rows to legitimately share one name across different academic years
// (the unique key is (faculty, academic_year, name), not (faculty, name)
// — e.g. a faculty re-using "Semester 6" for a later cohort/year after the
// earlier one has ended) — every matching row is kept here, not just the
// newest, so an older same-named semester's real historical data can never
// be silently swallowed behind a newer one and become unreachable.
$semestersByName = [];
if ($ownFacultyId > 0) {
    $semStmt = $conn->prepare(
        'SELECT s.id, s.name, s.status, ay.label AS academic_year_label
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         WHERE s.faculty_id = ? AND s.hidden_from_picker = 0
         ORDER BY s.id ASC'
    );
    $semStmt->bind_param('i', $ownFacultyId);
    $semStmt->execute();
    $semRes = $semStmt->get_result();
    while ($row = $semRes->fetch_assoc()) {
        $semestersByName[$row['name']][] = $row;
    }
    $semStmt->close();
}

$semesterBoxes = [];
foreach (semester_name_options_for_faculty($facultyTotalSemesters) as $semName) {
    $matches = $semestersByName[$semName] ?? [];
    if (empty($matches)) {
        $semesterBoxes[] = ['name' => $semName, 'semester_id' => 0, 'status' => null];
    } elseif (count($matches) === 1) {
        $semesterBoxes[] = [
            'name' => $semName,
            'semester_id' => (int) $matches[0]['id'],
            'status' => $matches[0]['status'],
        ];
    } else {
        // Name collision across academic years — render one disambiguated
        // box per real row (oldest first, since $matches is already in id
        // order) instead of collapsing to just the newest.
        foreach ($matches as $match) {
            $semesterBoxes[] = [
                'name' => $semName . ' (' . $match['academic_year_label'] . ')',
                'semester_id' => (int) $match['id'],
                'status' => $match['status'],
            ];
        }
    }
}

$filterSemesterId = (int) ($_GET['semester_id'] ?? 0);
$createdSemesterIds = array_filter(array_column($semesterBoxes, 'semester_id'));
if (!in_array($filterSemesterId, $createdSemesterIds, true)) {
    $filterSemesterId = 0;
    foreach ($semesterBoxes as $box) {
        if ($box['semester_id'] > 0 && $box['status'] === 'current') {
            $filterSemesterId = $box['semester_id'];
            break;
        }
    }
    if ($filterSemesterId === 0) {
        // No current semester created for this faculty yet — default to
        // the highest-numbered semester that actually exists.
        foreach (array_reverse($semesterBoxes) as $box) {
            if ($box['semester_id'] > 0) {
                $filterSemesterId = $box['semester_id'];
                break;
            }
        }
    }
}

// ---------------------------------------------------------------------
// Course discovery: course_enrollments first, department fallback second.
// ---------------------------------------------------------------------
$courseIds = [];
$discoveryMethod = 'none';
if ($ownStudentId > 0) {
    $enrollStmt = $conn->prepare('SELECT course_id FROM course_enrollments WHERE student_id = ?');
    $enrollStmt->bind_param('i', $ownStudentId);
    $enrollStmt->execute();
    $enrollRes = $enrollStmt->get_result();
    while ($row = $enrollRes->fetch_assoc()) {
        $courseIds[] = (int) $row['course_id'];
    }
    $enrollStmt->close();

    if (!empty($courseIds)) {
        $discoveryMethod = 'course_enrollments';
    } elseif ($ownDepartmentId > 0) {
        // Fallback: course_enrollments is still unpopulated for this student
        // — assume every course in their own department is theirs, the same
        // way attendance.php's roster falls back to department/year/shift
        // matching when a course has no enrollment rows yet.
        $deptCourseStmt = $conn->prepare('SELECT id FROM courses WHERE department_id = ?');
        $deptCourseStmt->bind_param('i', $ownDepartmentId);
        $deptCourseStmt->execute();
        $deptCourseRes = $deptCourseStmt->get_result();
        while ($row = $deptCourseRes->fetch_assoc()) {
            $courseIds[] = (int) $row['id'];
        }
        $deptCourseStmt->close();
        $discoveryMethod = 'department_fallback';
    }

    // Additive third source: a cross-listed/guest-faculty offering whose
    // roster_department_id explicitly names this student's own department
    // as its roster (see the Multi-Faculty Course Offerings plan) — the
    // course's own catalog home may be a completely different faculty, so
    // neither of the two paths above would ever surface it. Merged in
    // regardless of which path above ran, since a student can be a
    // genuine course_enrollments/department-fallback member of some
    // courses AND separately in a guest offering's roster for another.
    if ($ownDepartmentId > 0) {
        $guestCourseStmt = $conn->prepare(
            'SELECT DISTINCT course_id FROM course_offerings WHERE roster_department_id = ?'
        );
        $guestCourseStmt->bind_param('i', $ownDepartmentId);
        $guestCourseStmt->execute();
        $guestCourseRes = $guestCourseStmt->get_result();
        while ($row = $guestCourseRes->fetch_assoc()) {
            $courseIds[] = (int) $row['course_id'];
        }
        $guestCourseStmt->close();
        $courseIds = array_values(array_unique($courseIds));
    }
}

// ---------------------------------------------------------------------
// Course details + this student's own attendance % in each, scoped to the
// selected semester (not "the current academic year" — a student picking
// an older semester from the dropdown needs that semester's own lecturer
// and marks, which can differ from who teaches the course now).
// ---------------------------------------------------------------------
$courses = [];
if (!empty($courseIds) && $filterSemesterId > 0) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    // Lecturer shown is whoever held this course's offering for the
    // SELECTED semester (not the deprecated permanent courses.lecturer_id)
    // — "Unassigned" if there's no offering for that pair.
    //
    // course_enrollments has no semester_id of its own — it just means
    // "this student takes this course at all", not "in this specific
    // semester" — so $courseIds alone isn't enough to decide what belongs
    // under a given semester box. The extra WHERE clause below only keeps
    // a course here if there's real evidence it belongs to THIS semester:
    // either a course_offerings row for (course, semester), or an actual
    // attendance record. A course the student takes generally but with
    // neither for this particular semester is correctly left off this
    // semester's list instead of showing as a bare "Unassigned / No
    // records yet" row that doesn't actually belong here.
    //
    // The course_offerings JOIN is also narrowed to this student's own
    // shift (or an 'any'-shift offering, which applies to every shift) —
    // a course can now have a separate offering per shift within the same
    // semester, so without this the join would match every shift's row at
    // once, showing the wrong lecturer and (since attendance is joined
    // independently of co) double-counting this student's attendance once
    // per matching offering row. A specific-shift row and an 'any' row can
    // legitimately coexist for the same course+semester (e.g. an old
    // catch-all offering left in place after a specific shift was added
    // later) — the correlated subquery below resolves to exactly ONE
    // offering per course, preferring the exact shift match over 'any'
    // when both exist, so this can never fan out into duplicate rows.
    $sql = "SELECT c.id, c.code, c.name, l.full_name AS lecturer_name,
                   LEAST(10, SUM(a.status = 'present')) AS present_count,
                   COUNT(a.id) AS total_marks
            FROM courses c
            LEFT JOIN course_offerings co ON co.id = (
                SELECT co2.id FROM course_offerings co2
                WHERE co2.course_id = c.id AND co2.semester_id = ? AND (co2.shift = ? OR co2.shift = 'any')
                ORDER BY (co2.shift = ?) DESC
                LIMIT 1
            )
            LEFT JOIN lecturers l ON l.id = co.lecturer_id
            LEFT JOIN attendance a ON a.course_id = c.id AND a.student_id = ?
                AND a.session_id IN (SELECT id FROM sessions WHERE semester_id = ? AND type = 'regular')
            WHERE c.id IN ({$placeholders})
                AND (co.id IS NOT NULL OR a.id IS NOT NULL)
            GROUP BY c.id, c.code, c.name, l.full_name
            ORDER BY c.code";
    $stmt = $conn->prepare($sql);
    $types = 'issii' . str_repeat('i', count($courseIds));
    $params = array_merge([$filterSemesterId, $ownShift, $ownShift, $ownStudentId, $filterSemesterId], $courseIds);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses — ADMAS Attendance System</title>
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
                Access scope: Own personal record only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">My Courses</h4>
                    <p class="text-muted mb-0">Courses you're enrolled in and your attendance % in each, per semester.</p>
                </div>
            </div>

            <?php if (!empty($semesterBoxes)): ?>
                <div class="admas-card p-3 mb-3" style="border: 2px solid var(--admas-sky);">
                    <div class="text-muted small mb-2">Semester</div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($semesterBoxes as $box): ?>
                            <?php if ($box['semester_id'] > 0): ?>
                                <a href="<?= htmlspecialchars(BASE_URL) ?>/student/courses.php?semester_id=<?= $box['semester_id'] ?>"
                                   class="btn btn-sm <?= $box['semester_id'] === $filterSemesterId ? 'text-white' : '' ?>"
                                   <?= $box['semester_id'] === $filterSemesterId
                                        ? 'style="background-color: var(--admas-sky); border-color: var(--admas-sky);"'
                                        : 'style="border: 1px solid var(--admas-sky); color: var(--admas-sky);"' ?>>
                                    <?= htmlspecialchars($box['name']) ?><?= $box['status'] === 'current' ? ' (current)' : '' ?>
                                </a>
                            <?php else: ?>
                                <span class="btn btn-sm btn-outline-secondary disabled" style="opacity: 0.4;" title="Not created yet">
                                    <?= htmlspecialchars($box['name']) ?>
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="admas-card p-4" style="border: 2px solid var(--admas-sky);">
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Name</th>
                                <th>Lecturer</th>
                                <th>Attendance %</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courses)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No courses recorded for this semester yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courses as $c): ?>
                                    <?php
                                    $totalMarks = (int) $c['total_marks'];
                                    $pct = $totalMarks > 0 ? min(ATTENDANCE_MAX_SCORE, (int) $c['present_count']) : null;
                                    ?>
                                    <tr>
                                        <td><span class="badge-pill badge-active"><?= htmlspecialchars($c['code']) ?></span></td>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($c['name']) ?></td>
                                        <td>
                                            <?php if ($c['lecturer_name']): ?>
                                                <?= htmlspecialchars($c['lecturer_name']) ?>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($pct === null): ?>
                                                <span class="text-muted">No records yet</span>
                                            <?php else: ?>
                                                <span class="badge-pill <?= attendance_badge_class($pct, $minAttendancePct) ?>"><?= $pct ?>%</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php
                                        $gridUrl = BASE_URL . '/student/xiiso_grid.php?' . http_build_query([
                                            'course_id' => (int) $c['id'],
                                            'semester_id' => $filterSemesterId,
                                        ]);
                                        ?>
                                        <td>
                                            <a href="<?= htmlspecialchars($gridUrl) ?>" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                                <i class="bi bi-grid-3x3"></i> View Grid
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
</body>
</html>
