<?php
/**
 * My Courses (Lecturer only) — this lecturer's own assigned courses,
 * filterable by Academic Year (scopes the session/attendance stats shown,
 * since `courses` itself has no academic_year_id column) + Faculty +
 * Department (to disambiguate duplicate course codes across faculties, per
 * CLAUDE.md §4). Scoped via lecturers.user_id -> lecturers.id, resolved
 * from current_user()['id'], never trusted from request input (same
 * pattern as attendance.php/reports.php's lecturer branch).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/semester_helpers.php';

require_role(['lecturer']);

$conn = db();
$currentUser = current_user();

// No single "current academic year" default here — a lecturer can have
// courses across different faculties, each with its own current semester
// (see the per-course-row resolution below), so there's nothing global to
// default the Academic Year filter to. It starts as "All Academic Years"
// (0) unless the user explicitly picks one.
$filterAcademicYearIdExplicit = isset($_GET['academic_year_id']);

// ---------------------------------------------------------------------
// Own lecturers.id (never trusted from input)
// ---------------------------------------------------------------------
$lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
$lecStmt->bind_param('i', $currentUser['id']);
$lecStmt->execute();
$lecRow = $lecStmt->get_result()->fetch_assoc();
$lecStmt->close();
$lecturerRecordId = $lecRow ? (int) $lecRow['id'] : 0;

// ---------------------------------------------------------------------
// Filter bar state (real SQL WHERE filters, not client-side JS)
// ---------------------------------------------------------------------
$academicYears = $conn->query('SELECT id, label, is_current FROM academic_years ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);

$filterAcademicYearId = $filterAcademicYearIdExplicit ? (int) $_GET['academic_year_id'] : 0;
$filterFacultyId = (int) ($_GET['faculty_id'] ?? 0);
$filterDepartmentId = (int) ($_GET['department_id'] ?? 0);
$filterSearch = trim((string) ($_GET['search'] ?? ''));

// ---------------------------------------------------------------------
// Faculty/Department options — only those actually present among this
// lecturer's own courses (never the whole university's list).
// ---------------------------------------------------------------------
// A lecturer's "own courses" means current-offering-only (course_offerings
// scoped to that course's own faculty's current semester), not the
// deprecated permanent courses.lecturer_id — same EXISTS shape reused in
// all three queries below so the option lists and the actual course list
// can never drift apart on what "my courses" means.
$currentOfferingExists = "EXISTS (
    SELECT 1 FROM course_offerings co
    JOIN semesters se ON se.id = co.semester_id
    WHERE co.course_id = c.id AND co.lecturer_id = ? AND se.faculty_id = d.faculty_id AND se.is_current = 1
)";

$facultyOptStmt = $conn->prepare(
    "SELECT DISTINCT f.id, f.name
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     JOIN faculties f ON f.id = d.faculty_id
     WHERE {$currentOfferingExists}
     ORDER BY f.name"
);
$facultyOptStmt->bind_param('i', $lecturerRecordId);
$facultyOptStmt->execute();
$facultyOptions = $facultyOptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$facultyOptStmt->close();

$deptOptStmt = $conn->prepare(
    "SELECT DISTINCT d.id, d.name, d.faculty_id
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     WHERE {$currentOfferingExists}
     ORDER BY d.name"
);
$deptOptStmt->bind_param('i', $lecturerRecordId);
$deptOptStmt->execute();
$departmentOptions = $deptOptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$deptOptStmt->close();

$departmentsByFacultyId = [];
foreach ($departmentOptions as $dept) {
    $departmentsByFacultyId[(int) $dept['faculty_id']][] = ['id' => (int) $dept['id'], 'name' => $dept['name']];
}

// ---------------------------------------------------------------------
// Course list — the real security boundary is the current-offering EXISTS
// check here; Faculty/Department/search are extra narrowing only, never a
// way to see another lecturer's courses.
// ---------------------------------------------------------------------
// Note: the current-offering EXISTS check used for the filter-option
// queries above is not needed here — the JOIN to course_offerings/semesters
// below already restricts rows to this lecturer's current offerings, so it
// would just be redundant filtering (and a duplicate placeholder) on top.
$conditions = [];
$params = [$lecturerRecordId];
$types = 'i';

if ($filterFacultyId > 0) {
    $conditions[] = 'd.faculty_id = ?';
    $params[] = $filterFacultyId;
    $types .= 'i';
}
if ($filterDepartmentId > 0) {
    $conditions[] = 'c.department_id = ?';
    $params[] = $filterDepartmentId;
    $types .= 'i';
}
if ($filterSearch !== '') {
    $conditions[] = '(c.code LIKE ? OR c.name LIKE ?)';
    $likeParam = '%' . $filterSearch . '%';
    $params[] = $likeParam;
    $params[] = $likeParam;
    $types .= 'ss';
}
if ($filterAcademicYearId > 0) {
    $conditions[] = 'ay.id = ?';
    $params[] = $filterAcademicYearId;
    $types .= 'i';
}

$whereSql = empty($conditions) ? '1 = 1' : implode(' AND ', $conditions);

// One row per (course, current-semester offering) pair — not per course —
// since a faculty can have multiple concurrent current semesters (see
// includes/semester_helpers.php's refresh_semester_current_flags()), a
// lecturer can be teaching the same course under two different current
// semesters/batches at once, and each needs its own Semester/Pending
// Xiiso context rather than being collapsed into a single course row.
$coursesStmt = $conn->prepare(
    "SELECT c.id AS course_id, c.code, c.name, c.credit_hours,
            d.id AS department_id, d.faculty_id, d.name AS department_name, f.name AS faculty_name,
            se.id AS semester_id, se.name AS semester_name,
            ay.id AS academic_year_id, ay.label AS academic_year_label,
            co.shift AS offering_shift,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) AS student_count
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     JOIN faculties f ON f.id = d.faculty_id
     JOIN course_offerings co ON co.course_id = c.id AND co.lecturer_id = ?
     JOIN semesters se ON se.id = co.semester_id AND se.faculty_id = d.faculty_id AND se.is_current = 1
     JOIN academic_years ay ON ay.id = se.academic_year_id
     WHERE {$whereSql}
     ORDER BY f.name, d.name, c.code, se.start_date"
);
$coursesStmt->bind_param($types, ...$params);
$coursesStmt->execute();
$courseOfferingRows = $coursesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$coursesStmt->close();

// ---------------------------------------------------------------------
// Per-row session stats + Pending Xiiso — scoped to this exact (course,
// semester) pair via its own 12 Xiiso sessions, not a broad academic-year
// date range, so two concurrent semesters for the same course never bleed
// into each other's counts. Sessions are fetched once per distinct
// semester_id and reused across every course row that shares it.
// ---------------------------------------------------------------------
$today = date('Y-m-d');
$sessionsBySemesterId = [];
$courses = [];
foreach ($courseOfferingRows as $row) {
    $courseId = (int) $row['course_id'];
    $semesterId = (int) $row['semester_id'];

    if (!isset($sessionsBySemesterId[$semesterId])) {
        $sessionsBySemesterId[$semesterId] = get_sessions_for_semester($conn, $semesterId);
    }
    $sessions = $sessionsBySemesterId[$semesterId];

    $enrolledCount = (int) $row['student_count'];
    $totalMarked = 0;
    $lastSessionDate = null;
    $pendingSessions = [];

    foreach ($sessions as $session) {
        if ($session['date'] === null) {
            continue;
        }
        $markedStmt = $conn->prepare('SELECT COUNT(*) AS c FROM attendance WHERE course_id = ? AND session_id = ?');
        $markedStmt->bind_param('ii', $courseId, $session['id']);
        $markedStmt->execute();
        $markedCount = (int) ($markedStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $markedStmt->close();

        if ($markedCount > 0) {
            $totalMarked++;
            if ($lastSessionDate === null || $session['date'] > $lastSessionDate) {
                $lastSessionDate = $session['date'];
            }
        }

        if ($session['date'] <= $today && $enrolledCount > 0 && $markedCount < $enrolledCount) {
            $pendingSessions[] = $session;
        }
    }

    $row['total_sessions'] = $totalMarked;
    $row['last_session'] = $lastSessionDate;
    $row['pending_count'] = count($pendingSessions);
    $row['next_pending_session'] = $pendingSessions[0] ?? null;
    $courses[] = $row;
}

$currentAcademicYearLabel = '';
foreach ($academicYears as $ay) {
    if ((int) $ay['id'] === $filterAcademicYearId) {
        $currentAcademicYearLabel = (string) $ay['label'];
        break;
    }
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
                Access scope: Your assigned courses only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">My Courses</h4>
                    <p class="text-muted mb-0">Courses assigned to you, filterable by Academic Year, Faculty, and Department.</p>
                </div>
            </div>

            <div class="admas-card p-4 mb-3">
                <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer/courses.php" class="row g-2 mb-0">
                    <div class="col-sm-6 col-md-3">
                        <select class="form-select form-select-sm" name="academic_year_id">
                            <option value="0">All Academic Years</option>
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?= (int) $ay['id'] ?>" <?= $filterAcademicYearId === (int) $ay['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ay['label']) ?><?= $ay['is_current'] ? ' (current)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <select class="form-select form-select-sm" name="faculty_id" id="filterFacultySelect" onchange="updateFilterDepartmentOptions(this.value, 0)">
                            <option value="0">All Faculties</option>
                            <?php foreach ($facultyOptions as $f): ?>
                                <option value="<?= (int) $f['id'] ?>" <?= $filterFacultyId === (int) $f['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <select class="form-select form-select-sm" name="department_id" id="filterDepartmentSelect">
                            <option value="0">All Departments</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" name="search" placeholder="Search course code or name"
                                   value="<?= htmlspecialchars($filterSearch) ?>">
                            <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
                        </div>
                    </div>
                    <?php if ($filterFacultyId > 0 || $filterDepartmentId > 0 || $filterSearch !== ''): ?>
                        <div class="col-12">
                            <a href="<?= htmlspecialchars(BASE_URL) ?>/lecturer/courses.php" class="small">Clear filters</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="admas-card p-4">
                <h6 class="fw-bold mb-3" style="color: var(--admas-text);">
                    Courses
                    <?php if ($currentAcademicYearLabel !== ''): ?>
                        <span class="text-muted fw-normal">(sessions shown for <?= htmlspecialchars($currentAcademicYearLabel) ?>)</span>
                    <?php endif; ?>
                </h6>
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Department</th>
                                <th>Semester</th>
                                <th>Students</th>
                                <th>Sessions</th>
                                <th>Pending Xiiso</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courses)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No courses match the current filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courses as $c): ?>
                                    <?php
                                    $pendingCount = (int) $c['pending_count'];
                                    $nextPending = $c['next_pending_session'];

                                    // Deep-link straight into the ready-to-mark Grid View whenever
                                    // the offering's shift is known, so the lecturer never has to
                                    // manually pick the course/semester/shift on attendance.php —
                                    // just click and mark. attendance.php's Grid View shows the
                                    // whole semester at once (not one specific session), so this
                                    // only needs course/semester/shift, not the pending session id.
                                    $takeAttendanceUrl = BASE_URL . '/attendance.php?course_id=' . (int) $c['course_id'];
                                    if ($nextPending !== null && $c['offering_shift'] !== null) {
                                        $takeAttendanceUrl = BASE_URL . '/attendance.php?' . http_build_query([
                                            'course_id' => (int) $c['course_id'],
                                            'semester_id' => (int) $c['semester_id'],
                                            'shift' => $c['offering_shift'],
                                        ]);
                                    }
                                    ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);">
                                            <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
                                            <div class="text-muted small"><?= htmlspecialchars($c['faculty_name']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($c['department_name']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($c['semester_name']) ?>
                                            <div class="text-muted small"><?= htmlspecialchars($c['academic_year_label']) ?></div>
                                        </td>
                                        <td><?= number_format((int) $c['student_count']) ?></td>
                                        <td>
                                            <?= number_format((int) $c['total_sessions']) ?>
                                            <?php if ($c['last_session']): ?>
                                                <div class="text-muted small">Last: <?= htmlspecialchars(date('M j, Y', strtotime((string) $c['last_session']))) ?></div>
                                            <?php else: ?>
                                                <div class="text-muted small fst-italic">Never</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($pendingCount > 0): ?>
                                                <span class="badge-pill badge-warning"><?= $pendingCount ?> pending</span>
                                            <?php else: ?>
                                                <span class="badge-pill badge-present">Up to date</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?= htmlspecialchars($takeAttendanceUrl) ?>" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                                <i class="bi bi-calendar2-check"></i>
                                                <?= $nextPending !== null ? 'Take Attendance (' . htmlspecialchars($nextPending['label']) . ')' : 'Take Attendance' ?>
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
    <script>
        const departmentsByFacultyId = <?= json_encode($departmentsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const allDepartmentsFlat = <?= json_encode(array_map(static fn ($d) => ['id' => (int) $d['id'], 'name' => $d['name']], $departmentOptions), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function updateFilterDepartmentOptions(facultyId, selectedDepartmentId) {
            const select = document.getElementById('filterDepartmentSelect');
            let list = departmentsByFacultyId[facultyId] || [];
            if (list.length === 0 && (!facultyId || facultyId === '0')) {
                list = allDepartmentsFlat;
            }

            select.innerHTML = '';
            const allOption = document.createElement('option');
            allOption.value = '0';
            allOption.textContent = 'All Departments';
            select.appendChild(allOption);

            list.forEach((dept) => {
                const opt = document.createElement('option');
                opt.value = String(dept.id);
                opt.textContent = dept.name;
                select.appendChild(opt);
            });

            select.value = String(selectedDepartmentId || 0);
            if (select.value !== String(selectedDepartmentId || 0)) {
                select.value = '0';
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const facultySelect = document.getElementById('filterFacultySelect');
            updateFilterDepartmentOptions(facultySelect.value, <?= (int) $filterDepartmentId ?>);
        });
    </script>
</body>
</html>
