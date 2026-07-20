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
 * already makes in the opposite direction (course -> students).
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
$ownStmt = $conn->prepare('SELECT id, department_id, faculty_id FROM students WHERE user_id = ?');
$ownStmt->bind_param('i', $currentUser['id']);
$ownStmt->execute();
$ownRow = $ownStmt->get_result()->fetch_assoc();
$ownStmt->close();
$ownStudentId = $ownRow ? (int) $ownRow['id'] : 0;
$ownDepartmentId = $ownRow ? (int) $ownRow['department_id'] : 0;

// "Current semester" is resolved from this student's own faculty, not a
// single global settings value — used only as the dropdown's default pick.
$ownCurrentSemester = $ownRow ? get_current_semester($conn, (int) $ownRow['faculty_id']) : null;

// ---------------------------------------------------------------------
// Semester picker — every semester this student has attendance history in
// (via attendance -> sessions -> semesters), most recent first, so a
// senior can page back through everything they've taken so far, not just
// the current one. Falls back to just their own current semester when
// there's no attendance history yet (e.g. a brand-new student), so the
// dropdown is never empty.
// ---------------------------------------------------------------------
$semesterOptions = [];
if ($ownStudentId > 0) {
    $stmt = $conn->prepare(
        "SELECT DISTINCT se.id, se.name, se.start_date, se.is_current
         FROM attendance a
         JOIN sessions sess ON sess.id = a.session_id
         JOIN semesters se ON se.id = sess.semester_id
         WHERE a.student_id = ?
         ORDER BY se.start_date DESC"
    );
    $stmt->bind_param('i', $ownStudentId);
    $stmt->execute();
    $semesterOptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
if (empty($semesterOptions) && $ownCurrentSemester !== null) {
    $semesterOptions[] = [
        'id' => (int) $ownCurrentSemester['id'],
        'name' => $ownCurrentSemester['name'],
        'start_date' => $ownCurrentSemester['start_date'],
        'is_current' => 1,
    ];
}
$semesterOptionIds = array_map(static fn ($s) => (int) $s['id'], $semesterOptions);

$filterSemesterId = (int) ($_GET['semester_id'] ?? 0);
if (!in_array($filterSemesterId, $semesterOptionIds, true)) {
    $filterSemesterId = 0;
    foreach ($semesterOptions as $s) {
        if ((int) $s['is_current'] === 1) {
            $filterSemesterId = (int) $s['id'];
            break;
        }
    }
    if ($filterSemesterId === 0 && !empty($semesterOptions)) {
        $filterSemesterId = (int) $semesterOptions[0]['id'];
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
    $sql = "SELECT c.id, c.code, c.name, l.full_name AS lecturer_name,
                   SUM(a.status = 'present') AS present_count,
                   COUNT(a.id) AS total_marks
            FROM courses c
            LEFT JOIN course_offerings co ON co.course_id = c.id AND co.semester_id = ?
            LEFT JOIN lecturers l ON l.id = co.lecturer_id
            LEFT JOIN attendance a ON a.course_id = c.id AND a.student_id = ?
                AND a.session_id IN (SELECT id FROM sessions WHERE semester_id = ?)
            WHERE c.id IN ({$placeholders})
            GROUP BY c.id, c.code, c.name, l.full_name
            ORDER BY c.code";
    $stmt = $conn->prepare($sql);
    $types = 'iii' . str_repeat('i', count($courseIds));
    $params = array_merge([$filterSemesterId, $ownStudentId, $filterSemesterId], $courseIds);
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

            <?php if (count($semesterOptions) > 1): ?>
                <div class="admas-card p-3 mb-3">
                    <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/student/courses.php" class="d-flex align-items-center gap-2">
                        <label for="semesterSelect" class="form-label small mb-0 text-nowrap">Semester</label>
                        <select class="form-select form-select-sm" id="semesterSelect" name="semester_id" style="max-width: 260px;" onchange="this.form.submit()">
                            <?php foreach ($semesterOptions as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $filterSemesterId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name']) ?><?= (int) $s['is_current'] === 1 ? ' (current)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php endif; ?>

            <div class="admas-card p-4">
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
                                    <td colspan="5" class="text-center text-muted py-4">You are not enrolled in any courses yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courses as $c): ?>
                                    <?php
                                    $totalMarks = (int) $c['total_marks'];
                                    $pct = $totalMarks > 0 ? round(100 * (int) $c['present_count'] / $totalMarks, 1) : null;
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
                                                <span class="badge-pill <?= attendance_badge_class($pct, $minAttendancePct) ?>"><?= number_format($pct, 1) ?>%</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php
                                        $gridUrl = BASE_URL . '/student/xiiso_grid.php?' . http_build_query([
                                            'course_id' => (int) $c['id'],
                                            'semester_id' => $filterSemesterId,
                                        ]);
                                        ?>
                                        <td>
                                            <a href="<?= htmlspecialchars($gridUrl) ?>" class="btn btn-outline-secondary btn-sm">
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
