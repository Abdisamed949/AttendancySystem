<?php
/**
 * Attendance marking screen — shared by System Administrator, Dean, and
 * Lecturer (each scoped to a different slice of courses). Lives at the app
 * root (not under /admin) because the same file is reused by all three
 * roles; includes/sidebar.php links to it via the 'path' override in
 * includes/nav_items.php instead of the usual per-role-folder convention.
 *
 * The interactive Xiiso Grid is the only marking view (the older
 * single-session/"classic" form was removed) — pick a Course, a Semester
 * (any semester belonging to that course's faculty, not just the current
 * one — matching attendance_import.php's own historical scope) and
 * optionally a Shift to narrow the roster, then click cells to mark
 * Present/Absent. Each cell save is an AJAX call to
 * ajax/save_attendance_cell.php, which independently re-validates write
 * access for the specific course+semester being edited.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/semester_helpers.php';
require_once __DIR__ . '/includes/attendance_helpers.php';

require_role(['system_admin', 'dean', 'lecturer']);

$conn = db();
$currentUser = current_user();
$role = current_role();

const SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

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
// Role-specific scope: dean's own faculty, lecturer's own lecturers.id
// ---------------------------------------------------------------------
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

$lecturerRecordId = 0;
if ($role === 'lecturer') {
    $lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
    $lecStmt->bind_param('i', $currentUser['id']);
    $lecStmt->execute();
    $lecRow = $lecStmt->get_result()->fetch_assoc();
    $lecStmt->close();
    $lecturerRecordId = $lecRow ? (int) $lecRow['id'] : 0;
}

// ---------------------------------------------------------------------
// Course list, scoped by role (this is the actual security boundary —
// any course_id that shows up in $courseById below is guaranteed in-scope
// for the current user, regardless of what a request tries to submit).
// ---------------------------------------------------------------------
$courses = [];
if ($role === 'system_admin') {
    $courses = $conn->query(
        "SELECT c.id, c.code, c.name, c.department_id,
                d.name AS department_name, d.faculty_id, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name, c.code"
    )->fetch_all(MYSQLI_ASSOC);
} elseif ($role === 'dean') {
    // Own faculty's own-catalog courses, PLUS any course cross-listed INTO
    // this faculty from elsewhere (a course whose catalog home is a
    // different faculty but has a real course_offerings row here — see
    // the Multi-Faculty Course Offerings plan). department_name/
    // faculty_name shown for a cross-listed row are the course's own
    // catalog home, not this faculty — correct, since that's genuinely
    // where the course is cataloged.
    $stmt = $conn->prepare(
        "SELECT DISTINCT c.id, c.code, c.name, c.department_id,
                d.name AS department_name, d.faculty_id, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         WHERE d.faculty_id = ?
            OR EXISTS (
                SELECT 1 FROM course_offerings co
                JOIN semesters se ON se.id = co.semester_id
                WHERE co.course_id = c.id AND se.faculty_id = ?
            )
         ORDER BY d.name, c.code"
    );
    $stmt->bind_param('ii', $deanFacultyId, $deanFacultyId);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($role === 'lecturer') {
    // Current-offering-only: a lecturer only sees courses they're
    // currently assigned to teach (course_offerings), regardless of which
    // faculty that specific offering's semester belongs to — a lecturer
    // may now hold a cross-listed/guest-faculty offering whose semester's
    // faculty differs from the course's own catalog department's faculty
    // (see the Multi-Faculty Course Offerings plan), so this is no longer
    // constrained to "the course's own faculty's current semester". Not a
    // lifetime list of every course they've ever been assigned, and never
    // derived from the lecturer's own home department.
    $stmt = $conn->prepare(
        "SELECT DISTINCT c.id, c.code, c.name, c.department_id,
                d.name AS department_name, d.faculty_id, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         JOIN course_offerings co ON co.course_id = c.id AND co.lecturer_id = ?
         JOIN semesters se ON se.id = co.semester_id AND se.is_current = 1
         ORDER BY c.code"
    );
    $stmt->bind_param('i', $lecturerRecordId);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$courseById = [];
foreach ($courses as $c) {
    $courseById[(int) $c['id']] = $c;
}

// Every faculty each course actually has a real course_offerings row in
// (home faculty is not necessarily one of these until it has its own
// offering) — used below to resolve/validate semesters for a course that
// may now be offered across more than one faculty at once.
$offeringFacultyIdsByCourse = [];
$offFacRes = $conn->query(
    'SELECT DISTINCT co.course_id, se.faculty_id
     FROM course_offerings co
     JOIN semesters se ON se.id = co.semester_id'
);
if ($offFacRes) {
    while ($row = $offFacRes->fetch_assoc()) {
        $offeringFacultyIdsByCourse[(int) $row['course_id']][] = (int) $row['faculty_id'];
    }
}

// Home faculty first (preferred default), then every other faculty this
// course is actually offered in.
$courseFacultyIdsByCourse = [];
foreach ($courses as $c) {
    $cid = (int) $c['id'];
    $courseFacultyIdsByCourse[$cid] = array_values(array_unique(array_merge(
        [(int) $c['faculty_id']],
        $offeringFacultyIdsByCourse[$cid] ?? []
    )));
}

$faculties = $role === 'system_admin'
    ? $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC)
    : [];

// Department filter options (UI convenience only, purely a client-side
// narrowing of the Course dropdown below it; no new access check, since
// $courses above is already the real, role-scoped security boundary).
if ($role === 'system_admin') {
    $departmentsForFilter = $conn->query(
        'SELECT d.id, d.name, f.name AS faculty_name
         FROM departments d
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name'
    )->fetch_all(MYSQLI_ASSOC);
} elseif ($role === 'dean') {
    $deptStmt = $conn->prepare('SELECT id, name FROM departments WHERE faculty_id = ? ORDER BY name');
    $deptStmt->bind_param('i', $deanFacultyId);
    $deptStmt->execute();
    $departmentsForFilter = $deptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $deptStmt->close();
} else {
    $seenDepartmentIds = [];
    $departmentsForFilter = [];
    foreach ($courses as $c) {
        $did = (int) $c['department_id'];
        if (!isset($seenDepartmentIds[$did])) {
            $seenDepartmentIds[$did] = true;
            $departmentsForFilter[] = ['id' => $did, 'name' => $c['department_name']];
        }
    }
    usort($departmentsForFilter, static fn ($a, $b) => strcmp($a['name'], $b['name']));
}

// ---------------------------------------------------------------------
// Semesters for each represented faculty (all semesters, not just
// current — a lecturer/dean/admin may need to correct or review a past
// semester's grid, not only mark the live one), plus each faculty's
// current semester id for the JS default-selection below.
// ---------------------------------------------------------------------
$facultyIds = [];
foreach ($courseFacultyIdsByCourse as $ids) {
    foreach ($ids as $fid) {
        $facultyIds[] = $fid;
    }
}
$facultyIds = array_values(array_unique($facultyIds));
$semestersByFacultyId = [];
$currentSemesterIdByFacultyId = [];
if (!empty($facultyIds)) {
    $placeholders = implode(',', array_fill(0, count($facultyIds), '?'));
    $types = str_repeat('i', count($facultyIds));
    $semStmt = $conn->prepare("SELECT id, name, faculty_id, is_current FROM semesters WHERE faculty_id IN ($placeholders) ORDER BY start_date DESC");
    $semStmt->bind_param($types, ...$facultyIds);
    $semStmt->execute();
    $semRows = $semStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $semStmt->close();
    foreach ($semRows as $sem) {
        $fid = (int) $sem['faculty_id'];
        $semestersByFacultyId[$fid][] = ['id' => (int) $sem['id'], 'name' => $sem['name']];
        if (!empty($sem['is_current'])) {
            $currentSemesterIdByFacultyId[$fid] = (int) $sem['id'];
        }
    }
}

// Course -> [faculty_id, ...] map for the JS Semester cascade (every role,
// not just system_admin — dean/lecturer also need this to populate
// Semester options when Course changes, even though their own Course list
// is server-flat). A course can now have real offerings in more than one
// faculty at once, so this is plural, not a single scalar.
$courseFacultyIdsJs = $courseFacultyIdsByCourse;

// ---------------------------------------------------------------------
// Flash messages (post-redirect-get, same pattern as the other admin pages)
// ---------------------------------------------------------------------
$successMessage = '';
$errorMessage = '';
if (!empty($_SESSION['flash_success'])) {
    $successMessage = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $errorMessage = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------------------------------------------------------------------
// Filter state: Course -> Semester (explicit, or defaulted to that
// faculty's current one) -> optional Shift.
// ---------------------------------------------------------------------
$filterCourseId = (int) ($_GET['course_id'] ?? 0);
$filterSemesterId = (int) ($_GET['semester_id'] ?? 0);
$filterShift = (string) ($_GET['shift'] ?? '');
if (!array_key_exists($filterShift, SHIFT_LABELS)) {
    $filterShift = '';
}

$currentSemester = null;
$currentSemesterSessions = [];
$sessionById = [];

if (array_key_exists($filterCourseId, $courseById)) {
    $courseFacultyId = (int) $courseById[$filterCourseId]['faculty_id'];
    $courseFacultyIds = $courseFacultyIdsByCourse[$filterCourseId] ?? [$courseFacultyId];

    if ($filterSemesterId > 0) {
        // Valid whenever a real course_offerings row exists for this
        // course+semester — not "this semester's faculty equals the
        // course's one catalog faculty" (a course can now have offerings
        // across more than one faculty at once).
        if (course_offering_exists($conn, $filterCourseId, $filterSemesterId)) {
            $semStmt = $conn->prepare(
                "SELECT s.id, s.academic_year_id, s.faculty_id, s.name, s.start_date, s.end_date, s.is_current,
                        ay.label AS academic_year_label
                 FROM semesters s
                 JOIN academic_years ay ON ay.id = s.academic_year_id
                 WHERE s.id = ?"
            );
            $semStmt->bind_param('i', $filterSemesterId);
            $semStmt->execute();
            $currentSemester = $semStmt->get_result()->fetch_assoc() ?: null;
            $semStmt->close();
        }
        if ($currentSemester === null) {
            $filterSemesterId = 0;
        }
    }

    if ($currentSemester === null) {
        // No (valid) semester specified — default to the course's own
        // home faculty's current semester; if that faculty has none set,
        // fall back to any other faculty this course is actually offered
        // in (a cross-listed/guest offering may be the only "live" one).
        foreach ($courseFacultyIds as $fid) {
            $currentSemester = get_current_semester($conn, $fid);
            if ($currentSemester !== null) {
                $filterSemesterId = (int) $currentSemester['id'];
                break;
            }
        }
    }

    if ($currentSemester !== null) {
        $currentSemesterSessions = get_sessions_for_semester($conn, (int) $currentSemester['id']);
        foreach ($currentSemesterSessions as $s) {
            $sessionById[(int) $s['id']] = $s;
        }
    }
}

// Write access for the resolved course+semester — reused as-is from the
// AJAX endpoint's own check, so "can this cell be clicked" on screen never
// disagrees with "will the server actually accept this save". A dean/
// lecturer viewing a semester/course outside their write scope still sees
// the grid (read-only), matching how reports.php already lets the same
// roles view historical data they can't edit.
$canWriteAttendance = false;
if (array_key_exists($filterCourseId, $courseById) && $currentSemester !== null) {
    $canWriteAttendance = user_can_write_course_attendance($conn, $role, $currentUser, $filterCourseId, (int) $currentSemester['id'], $filterShift !== '' ? $filterShift : null);
}

// ---------------------------------------------------------------------
// Link to the full-semester Xiiso grid report (reports.php), pre-filtered
// to the same course + semester currently shown here.
// ---------------------------------------------------------------------
$xiisoGridUrl = '';
if ($currentSemester !== null && array_key_exists($filterCourseId, $courseById)) {
    $xiisoGridUrl = BASE_URL . '/reports.php?' . http_build_query([
        'report_type' => 'xiiso_grid',
        'xiiso_course_id' => $filterCourseId,
        'xiiso_semester_id' => (int) $currentSemester['id'],
    ]);
}

// ---------------------------------------------------------------------
// Grid data
// ---------------------------------------------------------------------
$showGrid = $currentSemester !== null
    && !empty($currentSemesterSessions)
    && array_key_exists($filterCourseId, $courseById);

$gridData = ['sessions' => [], 'students' => [], 'marks' => []];
$monthGroups = [];
$xiisoChunkEndSessionIds = [];
if ($showGrid) {
    $gridData = get_xiiso_grid_data($conn, $filterCourseId, (int) $currentSemester['id'], $filterShift !== '' ? $filterShift : null);
    $monthGroups = build_month_groups($gridData['sessions']);
    // Sky-blue divider every 4 Xiiso columns, layered on top of the
    // calendar-month band row above — the two groupings are independent
    // (fixed position vs. actual date), so a session can be a group-end
    // for one, both, or neither.
    foreach (build_xiiso_chunks($gridData['sessions']) as $chunk) {
        if (!empty($chunk['session_ids'])) {
            $xiisoChunkEndSessionIds[end($chunk['session_ids'])] = true;
        }
    }
}

// ---------------------------------------------------------------------
// JS data for the admin Faculty -> Course rebuild (dean/lecturer render a
// fixed list server-side and don't need this).
// ---------------------------------------------------------------------
$courseJsByFaculty = ['0' => []];
foreach ($courses as $c) {
    $entry = [
        'id' => (int) $c['id'],
        'label' => $c['code'] . ' — ' . $c['name'],
        'faculty' => $c['faculty_name'],
        'department' => $c['department_name'],
        'department_id' => (int) $c['department_id'],
    ];
    $courseJsByFaculty['0'][] = $entry;
    $courseJsByFaculty[(string) $c['faculty_id']][] = $entry;
}

$scopeBanner = match ($role) {
    'system_admin' => 'Access scope: Full system — all faculties, departments, and courses',
    'dean' => 'Access scope: ' . $deanFacultyName . ' Faculty only',
    'lecturer' => 'Access scope: Your assigned courses only',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
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

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Attendance</h4>
                    <p class="text-muted mb-0">Select a course and semester, then click a cell to mark Present/Absent.</p>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($successMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($errorMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="admas-card p-4 mb-3">
                <?php if (empty($courses)): ?>
                    <p class="text-muted mb-0">
                        <?= $role === 'lecturer' ? 'You have no assigned courses yet.' : 'No courses exist in your scope yet.' ?>
                    </p>
                <?php else: ?>
                    <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/attendance.php" class="row g-2 align-items-end">
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small mb-1">Faculty</label>
                            <?php if ($role === 'system_admin'): ?>
                                <select class="form-select form-select-sm" id="facultySelect" onchange="rebuildCourseSelect(this.value, ''); admasFilterCourseByDepartment(document.getElementById('courseSelect'), document.getElementById('departmentFilterSelect').value); admasUpdateSemesterOptionsForCourse('');">
                                    <option value="0">All Faculties</option>
                                    <?php foreach ($faculties as $f): ?>
                                        <option value="<?= (int) $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($role === 'dean'): ?>
                                <select class="form-select form-select-sm" disabled>
                                    <option selected><?= htmlspecialchars($deanFacultyName) ?></option>
                                </select>
                            <?php else: ?>
                                <input type="text" class="form-control form-control-sm" value="Your courses" disabled>
                            <?php endif; ?>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small mb-1">Department <span class="text-muted fw-normal">(optional)</span></label>
                            <select class="form-select form-select-sm" id="departmentFilterSelect" onchange="admasFilterCourseByDepartment(document.getElementById('courseSelect'), this.value)">
                                <option value="">All Departments</option>
                                <?php foreach ($departmentsForFilter as $d): ?>
                                    <option value="<?= (int) $d['id'] ?>">
                                        <?= htmlspecialchars(($role === 'system_admin' ? $d['faculty_name'] . ' — ' : '') . $d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Just narrows the list below — doesn't change what you're allowed to select.</div>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small mb-1">Course</label>
                            <select class="form-select form-select-sm" name="course_id" id="courseSelect" required onchange="admasUpdateSemesterOptionsForCourse(this.value)">
                                <option value="">Select course</option>
                                <?php if ($role === 'lecturer'): ?>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>" data-department-id="<?= (int) $c['department_id'] ?>" <?= $filterCourseId === (int) $c['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php
                                    $groupedCourses = [];
                                    foreach ($courses as $c) {
                                        $label = $role === 'system_admin'
                                            ? $c['faculty_name'] . ' — ' . $c['department_name']
                                            : $c['department_name'];
                                        $groupedCourses[$label][] = $c;
                                    }
                                    ?>
                                    <?php foreach ($groupedCourses as $label => $list): ?>
                                        <optgroup label="<?= htmlspecialchars($label) ?>">
                                            <?php foreach ($list as $c): ?>
                                                <option value="<?= (int) $c['id'] ?>" data-department-id="<?= (int) $c['department_id'] ?>" <?= $filterCourseId === (int) $c['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small mb-1">Semester</label>
                            <select class="form-select form-select-sm" name="semester_id" id="semesterSelect">
                                <?php if ($currentSemester !== null): ?>
                                    <?php foreach (($semestersByFacultyId[(int) ($courseById[$filterCourseId]['faculty_id'] ?? 0)] ?? []) as $sem): ?>
                                        <option value="<?= (int) $sem['id'] ?>" <?= $filterSemesterId === (int) $sem['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sem['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">Select course first</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small mb-1">Shift <span class="text-muted fw-normal">(optional)</span></label>
                            <select class="form-select form-select-sm" name="shift">
                                <option value="">All Shifts</option>
                                <?php foreach (SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                    <option value="<?= htmlspecialchars($shiftValue) ?>" <?= $filterShift === $shiftValue ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($shiftLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <button type="submit" class="btn btn-primary btn-sm w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                <i class="bi bi-grid-3x3"></i> Load Grid
                            </button>
                        </div>
                    </form>

                    <?php if (array_key_exists($filterCourseId, $courseById) && $currentSemester === null): ?>
                        <p class="text-muted small mb-0 mt-2">
                            No current semester is set for <?= htmlspecialchars((string) ($courseById[$filterCourseId]['faculty_name'] ?? '')) ?>.
                            <?= in_array($role, ['system_admin'], true) ? 'Create one and mark it current on the Semesters page.' : 'Ask an administrator to set one on the Semesters page.' ?>
                        </p>
                    <?php elseif (array_key_exists($filterCourseId, $courseById) && $currentSemester !== null && empty($currentSemesterSessions)): ?>
                        <p class="text-muted small mb-0 mt-2">
                            "<?= htmlspecialchars($currentSemester['name']) ?>" has no Xiiso sessions yet.
                            <?= in_array($role, ['system_admin'], true) ? 'Generate them on the Semesters page.' : 'Ask an administrator to generate them on the Semesters page.' ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($showGrid): ?>
                <div class="admas-card grid-card p-4">
                    <?php if (empty($gridData['students'])): ?>
                        <p class="text-muted mb-0">No students match this course<?= $filterShift !== '' ? ' / shift' : '' ?> yet.</p>
                    <?php else: ?>
                        <?= render_scope_breadcrumb([
                            $courseById[$filterCourseId]['code'],
                            $courseById[$filterCourseId]['department_name'],
                            $courseById[$filterCourseId]['faculty_name'],
                            $currentSemester['name'] ?? null,
                            $currentSemester['academic_year_label'] ?? null,
                        ]) ?>
                        <?= render_offering_summary(get_offering_summary($conn, $filterCourseId, (int) ($currentSemester['id'] ?? 0), $filterShift !== '' ? $filterShift : null)) ?>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h6 class="fw-bold mb-0" style="color: var(--admas-text);">
                                Xiiso Grid — <?= htmlspecialchars($courseById[$filterCourseId]['code'] . ' — ' . $courseById[$filterCourseId]['name']) ?>
                                <span class="text-muted fw-normal">
                                    (<?= htmlspecialchars($currentSemester['name']) ?><?= $filterShift !== '' ? ', ' . htmlspecialchars(SHIFT_LABELS[$filterShift]) : '' ?>)
                                </span>
                                <?php if (!$canWriteAttendance): ?>
                                    <span class="badge-pill badge-inactive">Read-only</span>
                                <?php endif; ?>
                            </h6>
                            <?php if ($xiisoGridUrl !== ''): ?>
                                <a href="<?= htmlspecialchars($xiisoGridUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-bar-chart"></i> View as Report
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if (!$canWriteAttendance): ?>
                            <div class="alert alert-light border small mb-3">
                                You can view this grid but not edit it — you don't currently have write access to this course for this semester.
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table admas-table align-middle" id="xiisoGridTable" data-course-id="<?= (int) $filterCourseId ?>" data-semester-id="<?= (int) $currentSemester['id'] ?>">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="col-summary">Student No</th>
                                        <th rowspan="2" class="col-group-end col-summary">Full Name</th>
                                        <?php foreach ($monthGroups as $mgIndex => $mg): ?>
                                            <th colspan="<?= (int) $mg['span'] ?>" class="grid-month-band<?= $mgIndex === count($monthGroups) - 1 ? ' col-group-end' : '' ?>"><?= htmlspecialchars($mg['month_label']) ?></th>
                                        <?php endforeach; ?>
                                        <th rowspan="2" class="text-center col-group-end col-summary">P</th>
                                        <th rowspan="2" class="text-center col-group-end col-summary">A</th>
                                        <th rowspan="2" class="text-center col-summary">%</th>
                                    </tr>
                                    <tr>
                                        <?php foreach ($gridData['sessions'] as $sIndex => $s): ?>
                                            <?php $sIsGroupEnd = $sIndex === count($gridData['sessions']) - 1 || isset($xiisoChunkEndSessionIds[(int) $s['id']]); ?>
                                            <th class="text-center<?= $sIsGroupEnd ? ' col-group-end' : '' ?>"><?= htmlspecialchars($s['label']) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($gridData['students'] as $st): ?>
                                        <?php
                                        $gsid = (int) $st['id'];
                                        $gPresentCount = 0;
                                        $gAbsentCount = 0;
                                        $gTotalMarks = 0;
                                        foreach ($gridData['sessions'] as $s) {
                                            $gStatus = $gridData['marks'][$gsid][(int) $s['id']] ?? null;
                                            if ($gStatus !== null) {
                                                $gTotalMarks++;
                                                if ($gStatus === 'present') {
                                                    $gPresentCount++;
                                                } elseif ($gStatus === 'absent') {
                                                    $gAbsentCount++;
                                                }
                                            }
                                        }
                                        $gPct = $gTotalMarks > 0 ? round(100 * $gPresentCount / $gTotalMarks, 1) : 0.0;
                                        ?>
                                        <tr data-student-row="<?= $gsid ?>">
                                            <td><?= htmlspecialchars($st['student_no']) ?></td>
                                            <td class="fw-semibold col-group-end" style="color: var(--admas-text);"><?= htmlspecialchars($st['full_name']) ?></td>
                                            <?php foreach ($gridData['sessions'] as $sIndex => $s): ?>
                                                <?php
                                                $gSessId = (int) $s['id'];
                                                $gCellStatus = $gridData['marks'][$gsid][$gSessId] ?? '';
                                                $gHasDate = $s['date'] ? true : false;
                                                $gCellGlyph = match ($gCellStatus) {
                                                    'present' => 'P',
                                                    'absent' => 'A',
                                                    default => '',
                                                };
                                                $gIsGroupEnd = $sIndex === count($gridData['sessions']) - 1 || isset($xiisoChunkEndSessionIds[$gSessId]);
                                                $gDisabled = !$gHasDate || !$canWriteAttendance;
                                                $gTitle = !$gHasDate
                                                    ? 'No date assigned yet — ask an admin to assign one in Semesters.'
                                                    : (!$canWriteAttendance ? 'Read-only — you do not have write access to this course/semester.' : '');
                                                ?>
                                                <td class="text-center p-1<?= $gIsGroupEnd ? ' col-group-end' : '' ?>">
                                                    <button type="button"
                                                            class="grid-cell"
                                                            data-student-id="<?= $gsid ?>"
                                                            data-session-id="<?= $gSessId ?>"
                                                            data-course-id="<?= (int) $filterCourseId ?>"
                                                            data-status="<?= htmlspecialchars($gCellStatus) ?>"
                                                            <?= $gDisabled ? 'disabled' : '' ?> <?= $gTitle !== '' ? 'title="' . htmlspecialchars($gTitle) . '"' : '' ?>>
                                                        <?= htmlspecialchars($gCellGlyph) ?>
                                                    </button>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center col-group-end col-summary" data-role="present-count"><?= $gPresentCount ?></td>
                                            <td class="text-center col-group-end col-summary" data-role="absent-count"><?= $gAbsentCount ?></td>
                                            <td class="text-center col-summary" data-role="pct"><?= $gPct ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.ADMAS_BASE_URL = <?= json_encode(BASE_URL, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        // course_id -> [faculty_id, ...] — a course can now have real
        // offerings across more than one faculty at once (home faculty
        // listed first as the preferred default), so this is plural.
        const courseFacultyIdsMap = <?= json_encode($courseFacultyIdsJs, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const semestersByFacultyId = <?= json_encode($semestersByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const currentSemesterIdByFacultyId = <?= json_encode($currentSemesterIdByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        // Semester options depend on EVERY faculty the selected course is
        // actually offered in, not one global list — merged (deduplicated
        // by semester id) and repopulated client-side (no page reload) so
        // Course/Semester/Shift can all be picked before a single "Load
        // Grid" submit, same cascade pattern as attendance_import.php's
        // own Course -> Semester picker.
        function admasUpdateSemesterOptionsForCourse(courseId, preselectedSemesterId) {
            const select = document.getElementById('semesterSelect');
            const facultyIds = courseFacultyIdsMap[courseId] || [];
            const seenSemesterIds = new Set();
            const semesters = [];
            facultyIds.forEach((fid) => {
                (semestersByFacultyId[fid] || []).forEach((sem) => {
                    if (!seenSemesterIds.has(sem.id)) {
                        seenSemesterIds.add(sem.id);
                        semesters.push(sem);
                    }
                });
            });
            select.innerHTML = '';

            if (semesters.length === 0) {
                const blank = document.createElement('option');
                blank.value = '';
                blank.textContent = courseId ? 'No semesters for this course yet' : 'Select course first';
                select.appendChild(blank);
                return;
            }

            semesters.forEach((sem) => {
                const opt = document.createElement('option');
                opt.value = String(sem.id);
                opt.textContent = sem.name;
                select.appendChild(opt);
            });

            let defaultSemesterId = preselectedSemesterId;
            if (!defaultSemesterId) {
                // Prefer the course's own home faculty's current semester
                // (facultyIds[0]); fall back to any other represented
                // faculty's current semester otherwise.
                for (const fid of facultyIds) {
                    if (currentSemesterIdByFacultyId[fid]) {
                        defaultSemesterId = currentSemesterIdByFacultyId[fid];
                        break;
                    }
                }
            }
            if (defaultSemesterId) {
                select.value = String(defaultSemesterId);
            }
        }

        // Phase 2 — Department filter: purely a client-side show/hide over
        // the Course dropdown's already-loaded, already-permission-scoped
        // <option> elements. Never changes which courses exist in the
        // select, never re-queries anything — just narrows what's visible.
        function admasFilterCourseByDepartment(select, departmentId) {
            if (!select) {
                return;
            }
            const options = select.querySelectorAll('option[data-department-id]');
            options.forEach((opt) => {
                opt.hidden = Boolean(departmentId) && opt.dataset.departmentId !== String(departmentId);
            });
            const selected = select.options[select.selectedIndex];
            if (selected && selected.hidden) {
                select.value = '';
            }
            admasUpdateSemesterOptionsForCourse(select.value);
        }
    </script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/attendance_grid.js" defer></script>
    <?php if ($role === 'system_admin'): ?>
        <script>
            const courseDataByFaculty = <?= json_encode($courseJsByFaculty, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const preselectedCourseId = <?= (int) $filterCourseId ?>;
            const preselectedSemesterIdInit = <?= (int) $filterSemesterId ?>;

            function rebuildCourseSelect(facultyId, selectedCourseId) {
                const select = document.getElementById('courseSelect');
                const list = courseDataByFaculty[String(facultyId)] || [];
                const isAll = String(facultyId) === '0';
                const groups = {};

                list.forEach((c) => {
                    const label = isAll ? (c.faculty + ' — ' + c.department) : c.department;
                    if (!groups[label]) {
                        groups[label] = [];
                    }
                    groups[label].push(c);
                });

                select.innerHTML = '';
                const blank = document.createElement('option');
                blank.value = '';
                blank.textContent = 'Select course';
                select.appendChild(blank);

                Object.keys(groups).forEach((label) => {
                    const og = document.createElement('optgroup');
                    og.label = label;
                    groups[label].forEach((c) => {
                        const opt = document.createElement('option');
                        opt.value = String(c.id);
                        opt.textContent = c.label;
                        opt.dataset.departmentId = String(c.department_id);
                        og.appendChild(opt);
                    });
                    select.appendChild(og);
                });

                select.value = String(selectedCourseId || '');
            }

            window.addEventListener('DOMContentLoaded', () => {
                const facultySelect = document.getElementById('facultySelect');
                // Figure out which faculty the currently-selected course (if any) belongs to,
                // so a page reload after Load Grid keeps both dropdowns in sync.
                let initialFaculty = '0';
                if (preselectedCourseId > 0) {
                    Object.keys(courseDataByFaculty).forEach((facultyId) => {
                        if (facultyId !== '0' && courseDataByFaculty[facultyId].some((c) => c.id === preselectedCourseId)) {
                            initialFaculty = facultyId;
                        }
                    });
                }
                facultySelect.value = initialFaculty;
                rebuildCourseSelect(initialFaculty, preselectedCourseId);
                admasUpdateSemesterOptionsForCourse(preselectedCourseId, preselectedSemesterIdInit);
            });
        </script>
    <?php else: ?>
        <script>
            const preselectedSemesterIdInit = <?= (int) $filterSemesterId ?>;
            window.addEventListener('DOMContentLoaded', () => {
                const courseSelect = document.getElementById('courseSelect');
                admasUpdateSemesterOptionsForCourse(courseSelect.value, preselectedSemesterIdInit);
            });
        </script>
    <?php endif; ?>
</body>
</html>
