<?php
/**
 * My Courses (Lecturer only) — this lecturer's own assigned courses,
 * filterable by Academic Year (scopes the session/attendance stats shown,
 * since `courses` itself has no academic_year_id column) + Faculty +
 * Department (to disambiguate duplicate course codes across faculties, per
 * CLAUDE.md §4). Scoped via lecturers.user_id -> lecturers.id, resolved
 * from current_user()['id'], never trusted from request input (same
 * pattern as attendance.php/reports.php's lecturer branch).
 *
 * Faculty/Department shown per row are the OFFERING's own semester's
 * faculty and roster department (falling back to the course's own catalog
 * department when no roster department is set) — not necessarily the
 * course's catalog home, since a lecturer may hold a cross-listed/
 * guest-faculty offering (see the Multi-Faculty Course Offerings plan).
 *
 * Shows this lecturer's FULL teaching history — current, waiting, and
 * ended semesters alike, not just the current one — with a Status badge
 * per row, so past semesters they used to teach are never hidden. Write
 * access itself was never blocked by semester status (see
 * user_can_write_course_attendance()); only this page's own listing query
 * used to filter to current-only, which is what this fixes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/semester_helpers.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['lecturer']);

const SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

$conn = db();
$currentUser = current_user();

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
// A lecturer's "own courses" means current-offering-only (course_offerings,
// current semester), not the deprecated permanent courses.lecturer_id —
// no longer requires the offering's semester to belong to the course's own
// catalog department's faculty, since a lecturer may hold a cross-listed/
// guest-faculty offering whose semester's faculty differs from the
// course's home faculty (see the Multi-Faculty Course Offerings plan).
// Faculty/Department options below reflect the OFFERING's own faculty
// (se.faculty_id) and roster department (co.roster_department_id, falling
// back to the course's own department when unset) — the operationally
// relevant faculty/department for that specific offering, not necessarily
// the course's catalog home.
$facultyOptStmt = $conn->prepare(
    "SELECT DISTINCT f.id, f.name
     FROM course_offerings co
     JOIN semesters se ON se.id = co.semester_id
     JOIN faculties f ON f.id = se.faculty_id
     WHERE co.lecturer_id = ?
     ORDER BY f.name"
);
$facultyOptStmt->bind_param('i', $lecturerRecordId);
$facultyOptStmt->execute();
$facultyOptions = $facultyOptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$facultyOptStmt->close();

$deptOptStmt = $conn->prepare(
    "SELECT DISTINCT COALESCE(rd.id, d.id) AS id, COALESCE(rd.name, d.name) AS name, se.faculty_id
     FROM course_offerings co
     JOIN semesters se ON se.id = co.semester_id
     JOIN courses c ON c.id = co.course_id
     JOIN departments d ON d.id = c.department_id
     LEFT JOIN departments rd ON rd.id = co.roster_department_id
     WHERE co.lecturer_id = ?
     ORDER BY name"
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
    $conditions[] = 'se.faculty_id = ?';
    $params[] = $filterFacultyId;
    $types .= 'i';
}
if ($filterDepartmentId > 0) {
    $conditions[] = 'COALESCE(rd.id, d.id) = ?';
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

// One row per (course, offering) pair — not per course, and not restricted
// to the current semester — since a lecturer's real teaching history spans
// past (ended), active (current), and not-yet-started (waiting) semesters
// at once, and a faculty can even have multiple concurrent current
// semesters (status is set by hand per semester via semesters.php, not
// mutually exclusive). Every offering this lecturer has ever held is shown
// here, distinguished by its own Semester Status badge, so their full real
// teaching record — not just today's snapshot — is visible (the dashboard's
// own "My Assigned Courses" widget stays current-only, matching the same
// dashboard-vs-full-history split already used on student/courses.php).
$coursesStmt = $conn->prepare(
    "SELECT c.id AS course_id, c.code, c.name, c.credit_hours,
            COALESCE(rd.id, d.id) AS department_id, se.faculty_id, COALESCE(rd.name, d.name) AS department_name, offf.name AS faculty_name,
            se.id AS semester_id, se.name AS semester_name, se.status AS semester_status,
            se.start_date, se.end_date,
            ay.id AS academic_year_id, ay.label AS academic_year_label,
            co.shift AS offering_shift
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     JOIN course_offerings co ON co.course_id = c.id AND co.lecturer_id = ?
     JOIN semesters se ON se.id = co.semester_id
     JOIN faculties offf ON offf.id = se.faculty_id
     LEFT JOIN departments rd ON rd.id = co.roster_department_id
     JOIN academic_years ay ON ay.id = se.academic_year_id
     WHERE {$whereSql}
     ORDER BY (se.status = 'current') DESC, (se.status = 'waiting') DESC, offf.name, department_name, c.code, se.start_date DESC"
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

    // Real roster size — same enrollment-then-department-fallback
    // resolution the Grid View itself uses (get_course_roster_count()),
    // not a bare course_enrollments count, which understates it whenever
    // a course has no explicit enrollment rows yet. An offering shift of
    // 'any' means "every shift", so it's treated the same as no filter.
    $rosterShift = ($row['offering_shift'] !== null && $row['offering_shift'] !== 'any') ? $row['offering_shift'] : null;
    $enrolledCount = get_course_roster_count($conn, $courseId, $semesterId, $rosterShift);
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

    $row['student_count'] = $enrolledCount;
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

// ---------------------------------------------------------------------
// Group into one card per semester — same visual pattern as
// lecturer/teaching_history.php — preserving $courses' own ordering
// (current semesters first, then waiting, then ended) via first-appearance
// insertion order.
// ---------------------------------------------------------------------
$coursesBySemesterId = [];
$semesterMetaById = [];
foreach ($courses as $c) {
    $sid = (int) $c['semester_id'];
    if (!isset($semesterMetaById[$sid])) {
        $semesterMetaById[$sid] = [
            'id' => $sid,
            'name' => $c['semester_name'],
            'status' => $c['semester_status'],
            'academic_year_label' => $c['academic_year_label'],
            'start_date' => $c['start_date'],
            'end_date' => $c['end_date'],
        ];
    }
    $coursesBySemesterId[$sid][] = $c;
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
    <style>
        /* Same content width as lecturer/teaching_history.php — a centered,
           narrower column reads better for a stack of semester cards than
           stretching them across the full page-body width. */
        .history-wrap { max-width: 900px; margin: 0 auto; }

        /* Same semester-card visual pattern as lecturer/teaching_history.php
           (colored left-accent, hover lift) — applied here to the live
           management view instead of the read-only history one. */
        .semester-card {
            border-left: 5px solid var(--admas-border);
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }

        .semester-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px var(--admas-shadow);
        }

        .semester-card.semester-current { border-left-color: #16a34a; }
        .semester-card.semester-accent-sky { border-left-color: var(--admas-sky); }
        .semester-card.semester-accent-navy { border-left-color: var(--admas-navy-start); }
        .semester-card.semester-accent-amber { border-left-color: #d97706; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <div class="page-body">
            <div class="history-wrap">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                Access scope: Your assigned courses only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">My Courses</h4>
                    <p class="text-muted mb-0">Your full teaching history — current, upcoming, and past semesters — filterable by Academic Year, Faculty, and Department.</p>
                </div>
            </div>

            <div class="admas-card p-4 mb-3" style="border: 2px solid var(--admas-sky);">
                <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer/courses.php" class="row g-2 mb-0" id="lecturerCoursesFilterForm">
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
                            <input type="text" class="form-control" name="search" placeholder="Search course code or name" data-live-search
                                   value="<?= htmlspecialchars($filterSearch) ?>">
                            <button type="submit" class="btn text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"><i class="bi bi-search"></i></button>
                        </div>
                    </div>
                    <?php if ($filterFacultyId > 0 || $filterDepartmentId > 0 || $filterSearch !== ''): ?>
                        <div class="col-12">
                            <a href="<?= htmlspecialchars(BASE_URL) ?>/lecturer/courses.php" class="small">Clear filters</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($currentAcademicYearLabel !== ''): ?>
                <p class="text-muted small mb-2">Sessions shown for <?= htmlspecialchars($currentAcademicYearLabel) ?>.</p>
            <?php endif; ?>

            <?php if (empty($courses)): ?>
                <div class="admas-card p-4 text-center text-muted py-5">
                    No courses match the current filters.
                </div>
            <?php endif; ?>

            <?php
            $semAccents = ['semester-accent-sky', 'semester-accent-navy', 'semester-accent-amber'];
            $semIndex = 0;
            ?>
            <?php foreach ($semesterMetaById as $sem): ?>
                <?php
                $semCourses = $coursesBySemesterId[$sem['id']] ?? [];
                $accentClass = $sem['status'] === 'current' ? 'semester-current' : $semAccents[$semIndex % count($semAccents)];
                $semIndex++;
                $statusLabel = ['current' => 'Current', 'waiting' => 'Waiting', 'ended' => 'Ended'][$sem['status']] ?? ucfirst((string) $sem['status']);
                $statusBadge = ['current' => 'badge-present', 'waiting' => 'badge-warning', 'ended' => 'badge-inactive'][$sem['status']] ?? 'badge-inactive';
                ?>
                <div class="admas-card semester-card <?= $accentClass ?> p-4 mb-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-1" style="color: var(--admas-text);">
                                <?= htmlspecialchars($sem['name']) ?>
                                <span class="badge-pill <?= $statusBadge ?> ms-2"><?= htmlspecialchars($statusLabel) ?></span>
                            </h5>
                            <p class="text-muted small mb-0">
                                <i class="bi bi-calendar3"></i>
                                <?= htmlspecialchars($sem['academic_year_label']) ?>
                                <?php if ($sem['start_date'] || $sem['end_date']): ?>
                                    &middot; <?= htmlspecialchars(($sem['start_date'] ?? '?') . ' to ' . ($sem['end_date'] ?? '?')) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="text-muted small"><?= count($semCourses) ?> course<?= count($semCourses) === 1 ? '' : 's' ?></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table admas-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Faculty</th>
                                    <th>Department</th>
                                    <th>Shift</th>
                                    <th>Students</th>
                                    <th>Sessions</th>
                                    <th>Pending Xiiso</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($semCourses as $c): ?>
                                    <?php
                                    $pendingCount = (int) $c['pending_count'];
                                    $nextPending = $c['next_pending_session'];

                                    // Deep-link straight into the ready-to-mark Grid View whenever
                                    // this course's own current offering's semester is known, so the
                                    // lecturer never has to manually pick the course/semester/shift on
                                    // attendance.php — just click and mark. attendance.php's Grid View
                                    // shows the whole semester at once (not one specific session), so
                                    // this only needs course/semester/shift, not the pending session
                                    // id — deliberately NOT gated on $nextPending !== null (a course
                                    // with zero pending sessions, i.e. fully caught up, still has a
                                    // real semester and must still deep-link to it, not fall back to
                                    // attendance.php's own bare-course_id resolution, which can land on
                                    // the wrong semester whenever a faculty has more than one
                                    // concurrently-current semester).
                                    $takeAttendanceUrl = BASE_URL . '/attendance.php?course_id=' . (int) $c['course_id'];
                                    if ($c['semester_id'] !== null) {
                                        $queryParams = [
                                            'course_id' => (int) $c['course_id'],
                                            'semester_id' => (int) $c['semester_id'],
                                        ];
                                        if ($c['offering_shift'] !== null) {
                                            $queryParams['shift'] = $c['offering_shift'];
                                        }
                                        $takeAttendanceUrl = BASE_URL . '/attendance.php?' . http_build_query($queryParams);
                                    }
                                    ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);">
                                            <span class="badge-pill badge-active"><?= htmlspecialchars($c['code']) ?></span>
                                            <span class="ms-1"><?= htmlspecialchars($c['name']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($c['faculty_name']) ?></td>
                                        <td><?= htmlspecialchars($c['department_name']) ?></td>
                                        <td>
                                            <?php if ($c['offering_shift'] !== null && isset(SHIFT_LABELS[$c['offering_shift']])): ?>
                                                <?= htmlspecialchars(SHIFT_LABELS[$c['offering_shift']]) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
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
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/live_filter.js"></script>
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
            admasInitLiveFilter('#lecturerCoursesFilterForm');
        });
    </script>
</body>
</html>
