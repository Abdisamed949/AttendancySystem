<?php
/**
 * Attendance marking screen — shared by System Administrator, Dean, and
 * Lecturer (each scoped to a different slice of courses). Lives at the app
 * root (not under /admin) because the same file is reused by all three
 * roles; includes/sidebar.php links to it via the 'path' override in
 * includes/nav_items.php instead of the usual per-role-folder convention.
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
$defaultAcademicYearId = (int) ($settings['current_academic_year_id'] ?? 0);

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
    $stmt = $conn->prepare(
        "SELECT c.id, c.code, c.name, c.department_id,
                d.name AS department_name, d.faculty_id, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         WHERE d.faculty_id = ?
         ORDER BY d.name, c.code"
    );
    $stmt->bind_param('i', $deanFacultyId);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($role === 'lecturer') {
    // Current-offering-only: a lecturer only sees courses they're
    // currently assigned to teach (course_offerings, scoped to that
    // course's OWN faculty's current semester) — not a lifetime list of
    // every course they've ever been assigned, and never derived from
    // the lecturer's own home department (D1: always resolved per course).
    $stmt = $conn->prepare(
        "SELECT c.id, c.code, c.name, c.department_id,
                d.name AS department_name, d.faculty_id, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         JOIN course_offerings co ON co.course_id = c.id AND co.lecturer_id = ?
         JOIN semesters se ON se.id = co.semester_id AND se.faculty_id = d.faculty_id AND se.is_current = 1
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

$faculties = $role === 'system_admin'
    ? $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC)
    : [];

// Department filter options (Phase 2 — UI convenience only, purely a
// client-side narrowing of the Course dropdown below it; no new access
// check, since $courses above is already the real, role-scoped security
// boundary). Scoped per role exactly as specified: system_admin sees
// every department system-wide; Dean sees every department in their own
// faculty (not just ones with a course yet); Lecturer's list is derived
// straight from their own already-scoped $courses, since "departments
// containing courses they're assigned to" is exactly what that already is.
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

$academicYears = $conn->query('SELECT id, label, is_current FROM academic_years ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------------
// Current semester + its 12 Xiiso sessions (marking is always scoped to
// whichever semester is currently active for the selected course's own
// faculty — "current" is a per-faculty concept, so this can only be
// resolved once a course is known, not eagerly here at page-load; see
// resolve_current_semester_for_course() below, called from both the POST
// and GET handlers as soon as each has a course_id).
// ---------------------------------------------------------------------
$currentSemester = null;
$currentSemesterSessions = [];
$sessionById = [];

/**
 * Resolves the current semester (and its sessions) for whichever faculty
 * the given course belongs to — never a single global "current semester",
 * and never the lecturer's own home faculty (a lecturer can be assigned
 * courses across faculties), always the specific course being marked.
 */
function resolve_current_semester_for_course(mysqli $conn, array $courseById, int $courseId): array
{
    $facultyId = $courseById[$courseId]['faculty_id'] ?? null;
    if ($facultyId === null) {
        return [null, [], []];
    }

    $semester = get_current_semester($conn, (int) $facultyId);
    $sessions = $semester ? get_sessions_for_semester($conn, (int) $semester['id']) : [];
    $byId = [];
    foreach ($sessions as $s) {
        $byId[(int) $s['id']] = $s;
    }

    return [$semester, $sessions, $byId];
}

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
// Current filter state + roster state
// ---------------------------------------------------------------------
$filterAcademicYearId = 0;
$filterCourseId = 0;
$filterShift = '';
$filterSessionId = 0;
$showRoster = false;
$statusOverride = [];

// ---------------------------------------------------------------------
// Handle Save (POST)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_attendance') {
    $filterAcademicYearId = (int) ($_POST['academic_year_id'] ?? 0);
    $filterCourseId = (int) ($_POST['course_id'] ?? 0);
    $filterShift = (string) ($_POST['shift'] ?? '');
    $filterSessionId = (int) ($_POST['session_id'] ?? 0);
    $studentIds = array_map('intval', (array) ($_POST['student_ids'] ?? []));
    $statusInput = (array) ($_POST['status'] ?? []);

    if (array_key_exists($filterCourseId, $courseById)) {
        [$currentSemester, $currentSemesterSessions, $sessionById] = resolve_current_semester_for_course($conn, $courseById, $filterCourseId);
    }

    $validationError = '';
    $academicYearValid = false;
    foreach ($academicYears as $ay) {
        if ((int) $ay['id'] === $filterAcademicYearId) {
            $academicYearValid = true;
            break;
        }
    }

    $sessionDate = array_key_exists($filterSessionId, $sessionById) ? (string) ($sessionById[$filterSessionId]['date'] ?? '') : '';

    if (!$academicYearValid) {
        $validationError = 'Please select a valid academic year.';
    } elseif (!array_key_exists($filterCourseId, $courseById)) {
        $validationError = 'Please select a valid course.';
    } elseif ($currentSemester === null) {
        $facultyName = (string) ($courseById[$filterCourseId]['faculty_name'] ?? "this course's faculty");
        $validationError = 'No current semester is set for ' . $facultyName . '.';
    } elseif (!array_key_exists($filterShift, SHIFT_LABELS)) {
        $validationError = 'Please select a valid shift.';
    } elseif (!array_key_exists($filterSessionId, $sessionById)) {
        $validationError = 'Please select a valid Xiiso session.';
    } elseif ($sessionDate === '') {
        $validationError = 'This Xiiso session has no calendar date assigned yet — ask an admin to assign one in Semesters.';
    } elseif (empty($studentIds)) {
        $validationError = 'No students to save — load the roster first.';
    }

    $rows = [];
    if ($validationError === '') {
        foreach ($studentIds as $sid) {
            $st = (string) ($statusInput[$sid] ?? '');
            if (!array_key_exists($st, STATUS_LABELS)) {
                $validationError = 'Please select a status (Present/Absent/Late/Excused) for every student before saving.';
                break;
            }
            $rows[$sid] = $st;
        }
    }

    if ($validationError === '') {
        $conn->begin_transaction();
        try {
            $recordedBy = (int) $currentUser['id'];
            foreach ($rows as $sid => $status) {
                save_attendance_record(
                    $conn,
                    (int) $sid,
                    $filterCourseId,
                    $filterSessionId,
                    $filterAcademicYearId,
                    $filterShift,
                    $sessionDate,
                    $status,
                    $recordedBy
                );
            }
            $conn->commit();

            $count = count($rows);
            $_SESSION['flash_success'] = 'Attendance saved for ' . $count . ' student' . ($count === 1 ? '' : 's') . '.';

            redirect_to('attendance.php?' . http_build_query([
                'academic_year_id' => $filterAcademicYearId,
                'course_id' => $filterCourseId,
                'shift' => $filterShift,
                'session_id' => $filterSessionId,
                'load' => 1,
            ]));
        } catch (\Throwable $e) {
            $conn->rollback();
            $errorMessage = 'Failed to save attendance. Please try again.';
        }
    } else {
        $errorMessage = $validationError;
        $statusOverride = array_map('strval', $statusInput);
        $showRoster = array_key_exists($filterCourseId, $courseById)
            && array_key_exists($filterShift, SHIFT_LABELS)
            && array_key_exists($filterSessionId, $sessionById);
    }
}

// ---------------------------------------------------------------------
// Handle Load Students (GET) — also re-entered via the post-save redirect
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filterAcademicYearId = (int) ($_GET['academic_year_id'] ?? $defaultAcademicYearId);
    $filterCourseId = (int) ($_GET['course_id'] ?? 0);
    $filterShift = (string) ($_GET['shift'] ?? '');
    $filterSessionId = (int) ($_GET['session_id'] ?? 0);

    if (array_key_exists($filterCourseId, $courseById)) {
        [$currentSemester, $currentSemesterSessions, $sessionById] = resolve_current_semester_for_course($conn, $courseById, $filterCourseId);
    }

    if (($_GET['load'] ?? '') === '1') {
        $academicYearValid = false;
        foreach ($academicYears as $ay) {
            if ((int) $ay['id'] === $filterAcademicYearId) {
                $academicYearValid = true;
                break;
            }
        }

        if (!$academicYearValid) {
            $errorMessage = $errorMessage ?: 'Please select a valid academic year.';
        } elseif (!array_key_exists($filterCourseId, $courseById)) {
            $errorMessage = $errorMessage ?: 'Please select a valid course.';
        } elseif ($currentSemester === null) {
            $facultyName = (string) ($courseById[$filterCourseId]['faculty_name'] ?? "this course's faculty");
            $errorMessage = $errorMessage ?: ('No current semester is set for ' . $facultyName . '.');
        } elseif (!array_key_exists($filterShift, SHIFT_LABELS)) {
            $errorMessage = $errorMessage ?: 'Please select a valid shift.';
        } elseif (!array_key_exists($filterSessionId, $sessionById)) {
            $errorMessage = $errorMessage ?: 'Please select a valid Xiiso session.';
        } else {
            $showRoster = true;
        }
    }
}

// ---------------------------------------------------------------------
// Load roster: enrolled students first, fall back to department/year/shift
// match if the course has no course_enrollments rows yet.
// ---------------------------------------------------------------------
$roster = [];
if ($showRoster) {
    $stmt = $conn->prepare(
        "SELECT s.id, s.student_no, s.full_name
         FROM course_enrollments ce
         JOIN students s ON s.id = ce.student_id
         WHERE ce.course_id = ? AND s.academic_year_id = ? AND s.shift = ? AND s.status = 'active'
         ORDER BY s.student_no"
    );
    $stmt->bind_param('iis', $filterCourseId, $filterAcademicYearId, $filterShift);
    $stmt->execute();
    $roster = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($roster)) {
        $deptId = (int) $courseById[$filterCourseId]['department_id'];
        $stmt = $conn->prepare(
            "SELECT s.id, s.student_no, s.full_name
             FROM students s
             WHERE s.department_id = ? AND s.academic_year_id = ? AND s.shift = ? AND s.status = 'active'
             ORDER BY s.student_no"
        );
        $stmt->bind_param('iis', $deptId, $filterAcademicYearId, $filterShift);
        $stmt->execute();
        $roster = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $existing = [];
    if (!empty($roster)) {
        $stmt = $conn->prepare('SELECT student_id, status FROM attendance WHERE course_id = ? AND session_id = ?');
        $stmt->bind_param('ii', $filterCourseId, $filterSessionId);
        $stmt->execute();
        $exResult = $stmt->get_result();
        while ($row = $exResult->fetch_assoc()) {
            $existing[(int) $row['student_id']] = (string) $row['status'];
        }
        $stmt->close();
    }

    foreach ($roster as &$r) {
        $sid = (int) $r['id'];
        $r['status'] = $statusOverride[$sid] ?? ($existing[$sid] ?? '');
    }
    unset($r);
}

$statusCounts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
foreach ($roster as $r) {
    if ($r['status'] !== '' && isset($statusCounts[$r['status']])) {
        $statusCounts[$r['status']]++;
    }
}

// ---------------------------------------------------------------------
// Link to the full-semester Xiiso grid (reports.php), pre-filtered to the
// same course + semester. Only built once the course is confirmed to be
// in this user's scope ($courseById is already role-scoped above), and
// reports.php independently re-checks scope against the same course list
// shape, so this is safe even if the query string were tampered with.
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
// Grid View: an alternate, interactive way to mark attendance for the
// whole current semester at once (all Xiiso sessions for one course,
// grouped by month), toggled client-side against the classic form above.
// Selecting a course in the Grid View's own picker reloads the page with
// ?view=grid&course_id=X so the grid data (like the classic roster) is
// always server-rendered; only the per-cell save itself is AJAX.
// ---------------------------------------------------------------------
$viewMode = ((string) ($_GET['view'] ?? '')) === 'grid' ? 'grid' : 'classic';
$showGrid = $viewMode === 'grid'
    && $currentSemester !== null
    && !empty($currentSemesterSessions)
    && array_key_exists($filterCourseId, $courseById);

$gridData = ['sessions' => [], 'students' => [], 'marks' => []];
$monthGroups = [];
if ($showGrid) {
    $gridData = get_xiiso_grid_data($conn, $filterCourseId, (int) $currentSemester['id']);
    $monthGroups = build_month_groups($gridData['sessions']);
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
    <style>
        .status-summary {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .status-summary .badge-pill {
            font-size: 0.78rem;
        }

        .roster-radio-cell {
            text-align: center;
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

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Attendance</h4>
                    <p class="text-muted mb-0">Select a course and date, load the roster, then mark each student's status.</p>
                </div>
                <div class="btn-group" role="group" aria-label="Attendance marking mode">
                    <button type="button" id="viewToggleClassic" class="btn btn-sm view-toggle-btn<?= $viewMode !== 'grid' ? ' active' : '' ?>" onclick="admasSetAttendanceView('classic')">
                        <i class="bi bi-list-check"></i> Single Session
                    </button>
                    <button type="button" id="viewToggleGrid" class="btn btn-sm view-toggle-btn<?= $viewMode === 'grid' ? ' active' : '' ?>" onclick="admasSetAttendanceView('grid')">
                        <i class="bi bi-grid-3x3"></i> Grid View
                    </button>
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

            <div id="classicMarkingView"<?= $viewMode === 'grid' ? ' hidden' : '' ?>>

            <div class="admas-card p-4 mb-3">
                <?php if (empty($courses)): ?>
                    <p class="text-muted mb-0">
                        <?= $role === 'lecturer' ? 'You have no assigned courses yet.' : 'No courses exist in your scope yet.' ?>
                    </p>
                <?php else: ?>
                    <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/attendance.php" class="row g-2 align-items-end">
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small mb-1">Academic Year</label>
                            <select class="form-select form-select-sm" name="academic_year_id" required>
                                <option value="">Select year</option>
                                <?php foreach ($academicYears as $ay): ?>
                                    <option value="<?= (int) $ay['id'] ?>" <?= $filterAcademicYearId === (int) $ay['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ay['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small mb-1">Faculty</label>
                            <?php if ($role === 'system_admin'): ?>
                                <select class="form-select form-select-sm" id="facultySelect" onchange="rebuildCourseSelect(this.value, ''); admasFilterCourseByDepartment(document.getElementById('courseSelect'), document.getElementById('departmentFilterSelect').value)">
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
                            <select class="form-select form-select-sm" name="course_id" id="courseSelect" required onchange="admasReloadWithCourse(this.value)">
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
                            <label class="form-label small mb-1">Shift</label>
                            <select class="form-select form-select-sm" name="shift" required>
                                <option value="">Select shift</option>
                                <?php foreach (SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                    <option value="<?= htmlspecialchars($shiftValue) ?>" <?= $filterShift === $shiftValue ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($shiftLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (!array_key_exists($filterCourseId, $courseById)): ?>
                            <div class="col-sm-6 col-md-6">
                                <p class="text-muted small mb-0">Select a course above to see its faculty's current semester and Xiiso sessions.</p>
                            </div>
                        <?php elseif ($currentSemester === null): ?>
                            <div class="col-sm-6 col-md-6">
                                <p class="text-muted small mb-0">
                                    No current semester is set for <?= htmlspecialchars((string) ($courseById[$filterCourseId]['faculty_name'] ?? '')) ?>.
                                    <?= in_array($role, ['system_admin'], true) ? 'Create one and mark it current on the Semesters page.' : 'Ask an administrator to set one on the Semesters page.' ?>
                                </p>
                            </div>
                        <?php elseif (empty($currentSemesterSessions)): ?>
                            <div class="col-sm-6 col-md-6">
                                <p class="text-muted small mb-0">
                                    "<?= htmlspecialchars($currentSemester['name']) ?>" has no Xiiso sessions yet.
                                    <?= in_array($role, ['system_admin'], true) ? 'Generate them on the Semesters page.' : 'Ask an administrator to generate them on the Semesters page.' ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label small mb-1">Semester</label>
                                <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($currentSemester['name']) ?>" disabled>
                            </div>

                            <div class="col-sm-6 col-md-3">
                                <label class="form-label small mb-1">Xiiso (Session)</label>
                                <select class="form-select form-select-sm" name="session_id" required>
                                    <option value="">Select Xiiso</option>
                                    <?php foreach ($currentSemesterSessions as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>" <?= $filterSessionId === (int) $s['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['label']) ?><?= $s['date'] ? ' — ' . htmlspecialchars((string) $s['date']) : ' (no date yet)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-sm-6 col-md-3">
                                <button type="submit" name="load" value="1" class="btn btn-primary btn-sm w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                    <i class="bi bi-arrow-repeat"></i> Load Students
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($showRoster): ?>
                <div class="admas-card p-4">
                    <?php if (empty($roster)): ?>
                        <p class="text-muted mb-0">No students match this Academic Year / Course / Shift combination.</p>
                    <?php else: ?>
                        <?= render_scope_breadcrumb([
                            $courseById[$filterCourseId]['code'],
                            $courseById[$filterCourseId]['department_name'],
                            $courseById[$filterCourseId]['faculty_name'],
                            $currentSemester['name'] ?? null,
                            $currentSemester['academic_year_label'] ?? null,
                        ]) ?>
                        <?= render_offering_summary(get_offering_summary($conn, $filterCourseId, (int) ($currentSemester['id'] ?? 0))) ?>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h6 class="fw-bold mb-0" style="color: var(--admas-text);">
                                Roster — <?= htmlspecialchars($courseById[$filterCourseId]['code'] . ' — ' . $courseById[$filterCourseId]['name']) ?>
                                <span class="text-muted fw-normal">(<?= htmlspecialchars(SHIFT_LABELS[$filterShift]) ?>, <?= htmlspecialchars($sessionById[$filterSessionId]['label']) ?>)</span>
                            </h6>
                            <?php if ($xiisoGridUrl !== ''): ?>
                                <a href="<?= htmlspecialchars($xiisoGridUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-grid-3x3"></i> View Full Semester Grid
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="status-summary">
                            <?php foreach (STATUS_LABELS as $statusKey => $statusLabel): ?>
                                <span class="badge-pill badge-<?= htmlspecialchars($statusKey) ?>">
                                    <?= htmlspecialchars($statusLabel) ?>: <?= (int) $statusCounts[$statusKey] ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/attendance.php">
                            <input type="hidden" name="action" value="save_attendance">
                            <input type="hidden" name="academic_year_id" value="<?= (int) $filterAcademicYearId ?>">
                            <input type="hidden" name="course_id" value="<?= (int) $filterCourseId ?>">
                            <input type="hidden" name="shift" value="<?= htmlspecialchars($filterShift) ?>">
                            <input type="hidden" name="session_id" value="<?= (int) $filterSessionId ?>">

                            <div class="table-responsive">
                                <table class="table admas-table align-middle">
                                    <thead>
                                        <tr>
                                            <th>Student No</th>
                                            <th>Full Name</th>
                                            <?php foreach (STATUS_LABELS as $statusLabel): ?>
                                                <th class="text-center"><?= htmlspecialchars($statusLabel) ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($roster as $r): ?>
                                            <?php $sid = (int) $r['id']; ?>
                                            <tr>
                                                <td>
                                                    <?= htmlspecialchars($r['student_no']) ?>
                                                    <input type="hidden" name="student_ids[]" value="<?= $sid ?>">
                                                </td>
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($r['full_name']) ?></td>
                                                <?php foreach (STATUS_LABELS as $statusKey => $statusLabel): ?>
                                                    <td class="roster-radio-cell">
                                                        <input type="radio" class="form-check-input" name="status[<?= $sid ?>]"
                                                               value="<?= htmlspecialchars($statusKey) ?>" required
                                                               <?= $r['status'] === $statusKey ? 'checked' : '' ?>>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                <i class="bi bi-save"></i> Save Attendance
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            </div>

            <div id="gridMarkingView"<?= $viewMode === 'grid' ? '' : ' hidden' ?>>

            <div class="admas-card p-4 mb-3">
                <?php if (empty($courses)): ?>
                    <p class="text-muted mb-0">
                        <?= $role === 'lecturer' ? 'You have no assigned courses yet.' : 'No courses exist in your scope yet.' ?>
                    </p>
                <?php else: ?>
                    <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/attendance.php" class="row g-2 align-items-end">
                        <input type="hidden" name="view" value="grid">
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small mb-1">Department <span class="text-muted fw-normal">(optional)</span></label>
                            <select class="form-select form-select-sm" id="gridDepartmentFilterSelect" onchange="admasFilterCourseByDepartment(document.getElementById('gridCourseSelect'), this.value)">
                                <option value="">All Departments</option>
                                <?php foreach ($departmentsForFilter as $d): ?>
                                    <option value="<?= (int) $d['id'] ?>">
                                        <?= htmlspecialchars(($role === 'system_admin' ? $d['faculty_name'] . ' — ' : '') . $d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-8 col-md-6">
                            <label class="form-label small mb-1">Course</label>
                            <select class="form-select form-select-sm" name="course_id" id="gridCourseSelect" required onchange="admasReloadWithCourse(this.value, 'grid')">
                                <option value="">Select course</option>
                                <?php if ($role === 'lecturer'): ?>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>" data-department-id="<?= (int) $c['department_id'] ?>" <?= $filterCourseId === (int) $c['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php
                                    $gridGroupedCourses = [];
                                    foreach ($courses as $c) {
                                        $label = $role === 'system_admin'
                                            ? $c['faculty_name'] . ' — ' . $c['department_name']
                                            : $c['department_name'];
                                        $gridGroupedCourses[$label][] = $c;
                                    }
                                    ?>
                                    <?php foreach ($gridGroupedCourses as $label => $list): ?>
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
                        <div class="col-sm-4 col-md-3">
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
                    <?php elseif (array_key_exists($filterCourseId, $courseById) && empty($currentSemesterSessions)): ?>
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
                        <p class="text-muted mb-0">No students match this course yet.</p>
                    <?php else: ?>
                        <?= render_scope_breadcrumb([
                            $courseById[$filterCourseId]['code'],
                            $courseById[$filterCourseId]['department_name'],
                            $courseById[$filterCourseId]['faculty_name'],
                            $currentSemester['name'] ?? null,
                            $currentSemester['academic_year_label'] ?? null,
                        ]) ?>
                        <?= render_offering_summary(get_offering_summary($conn, $filterCourseId, (int) ($currentSemester['id'] ?? 0))) ?>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h6 class="fw-bold mb-0" style="color: var(--admas-text);">
                                Xiiso Grid — <?= htmlspecialchars($courseById[$filterCourseId]['code'] . ' — ' . $courseById[$filterCourseId]['name']) ?>
                                <span class="text-muted fw-normal">(<?= htmlspecialchars($currentSemester['name']) ?>)</span>
                            </h6>
                        </div>

                        <div class="table-responsive">
                            <table class="table admas-table align-middle" id="xiisoGridTable" data-course-id="<?= (int) $filterCourseId ?>">
                                <thead>
                                    <tr>
                                        <th rowspan="2">Student No</th>
                                        <th rowspan="2" class="col-group-end">Full Name</th>
                                        <?php foreach ($monthGroups as $mgIndex => $mg): ?>
                                            <th colspan="<?= (int) $mg['span'] ?>" class="grid-month-band<?= $mgIndex === count($monthGroups) - 1 ? ' col-group-end' : '' ?>"><?= htmlspecialchars($mg['month_label']) ?></th>
                                        <?php endforeach; ?>
                                        <th rowspan="2" class="text-center">P</th>
                                        <th rowspan="2" class="text-center">A</th>
                                        <th rowspan="2" class="text-center">%</th>
                                    </tr>
                                    <tr>
                                        <?php foreach ($gridData['sessions'] as $sIndex => $s): ?>
                                            <th class="text-center<?= $sIndex === count($gridData['sessions']) - 1 ? ' col-group-end' : '' ?>"><?= htmlspecialchars($s['label']) ?></th>
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
                                                    'late' => 'L',
                                                    'excused' => 'E',
                                                    default => '',
                                                };
                                                $gIsLastSession = $sIndex === count($gridData['sessions']) - 1;
                                                ?>
                                                <td class="text-center p-1<?= $gIsLastSession ? ' col-group-end' : '' ?>">
                                                    <button type="button"
                                                            class="grid-cell"
                                                            data-student-id="<?= $gsid ?>"
                                                            data-session-id="<?= $gSessId ?>"
                                                            data-course-id="<?= (int) $filterCourseId ?>"
                                                            data-status="<?= htmlspecialchars($gCellStatus) ?>"
                                                            <?= $gHasDate ? '' : 'disabled title="No date assigned yet — ask an admin to assign one in Semesters."' ?>>
                                                        <?= htmlspecialchars($gCellGlyph) ?>
                                                    </button>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center" data-role="present-count"><?= $gPresentCount ?></td>
                                            <td class="text-center" data-role="absent-count"><?= $gAbsentCount ?></td>
                                            <td class="text-center" data-role="pct"><?= $gPct ?></td>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.ADMAS_BASE_URL = <?= json_encode(BASE_URL, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function admasSetAttendanceView(mode) {
            document.getElementById('classicMarkingView').hidden = mode !== 'classic';
            document.getElementById('gridMarkingView').hidden = mode !== 'grid';
            document.getElementById('viewToggleClassic').classList.toggle('active', mode === 'classic');
            document.getElementById('viewToggleGrid').classList.toggle('active', mode === 'grid');
        }

        // Which Xiiso sessions (and which semester) are valid depends on the
        // selected course's own faculty, not one global "current semester" —
        // so picking a different course reloads the page to re-resolve them
        // server-side, the same way the Grid View's course picker already did.
        function admasReloadWithCourse(courseId, viewMode) {
            if (!courseId) {
                return;
            }
            const params = new URLSearchParams();
            params.set('course_id', courseId);
            if (viewMode === 'grid') {
                params.set('view', 'grid');
            }
            window.location = window.ADMAS_BASE_URL + '/attendance.php?' + params.toString();
        }

        // Phase 2 — Department filter: purely a client-side show/hide over
        // the Course dropdown's already-loaded, already-permission-scoped
        // <option> elements (each already carries data-department-id from
        // the server, or from rebuildCourseSelect() below for
        // system_admin). Never changes which courses exist in the select,
        // never re-queries anything — just narrows what's visible. If the
        // currently-selected course gets hidden by the new filter, the
        // selection is cleared so a hidden/invisible option can't stay
        // "selected" behind the scenes.
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
        }
    </script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/attendance_grid.js" defer></script>
    <?php if ($role === 'system_admin'): ?>
        <script>
            const courseDataByFaculty = <?= json_encode($courseJsByFaculty, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const preselectedCourseId = <?= (int) $filterCourseId ?>;

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
                // so a page reload after Load Students keeps both dropdowns in sync.
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
            });
        </script>
    <?php endif; ?>
</body>
</html>
