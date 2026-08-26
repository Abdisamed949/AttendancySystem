<?php
/**
 * Class Time Table — a university-wide (or, for Dean, own-faculty) weekly
 * Day/Time grid across every course_offerings row that has a schedule set
 * (see migrations/2026_08_course_offerings_timetable.sql). Read-only —
 * the actual Day/Time/Room fields are set on admin/course_offerings.php
 * ("Manage Offerings") or lecturer_courses.php ("Assign Courses"), both
 * already Dean(own faculty)/Head of Academic Affairs(any faculty)-only;
 * this page exists purely to visualize the result, including for
 * University Rector, who never edits course_offerings at all.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/includes/timetable_helpers.php';
require_once __DIR__ . '/includes/university_logo.php';

require_role(['university_rector', 'dean', 'head_academic']);

$conn = db();
$role = current_role();
$currentUser = current_user();

// Inline Edit — Dean only, own faculty (matches admin/course_offerings.php's
// own write scope for this role). Head of Academic Affairs / University
// Rector keep editing on "Manage Offerings"/"Assign Courses" as before; this
// is purely a Dean convenience so they don't have to leave the page they're
// already looking at to fix a Day/Time/Room mistake.
$canEditSchedule = ($role === 'dean');
$deanFacultyIdForWrite = (int) ($_SESSION['faculty_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_schedule') {
    if (!$canEditSchedule) {
        $_SESSION['flash_error'] = 'Access scope: View only — this role cannot modify records.';
        redirect_to('class_timetable.php');
    }

    $offeringId = (int) ($_POST['offering_id'] ?? 0);
    $dayOfWeekRaw = trim((string) ($_POST['day_of_week'] ?? ''));
    $startTimeRaw = trim((string) ($_POST['start_time'] ?? ''));
    $endTimeRaw = trim((string) ($_POST['end_time'] ?? ''));
    $roomRaw = trim((string) ($_POST['room'] ?? ''));

    // Ownership: the offering's own semester must belong to the Dean's own
    // faculty — never trusts offering_id alone, same pattern
    // admin/course_offerings.php already uses for its own write checks.
    $ownStmt = $conn->prepare(
        'SELECT co.id FROM course_offerings co JOIN semesters se ON se.id = co.semester_id WHERE co.id = ? AND se.faculty_id = ?'
    );
    $ownStmt->bind_param('ii', $offeringId, $deanFacultyIdForWrite);
    $ownStmt->execute();
    $ownRow = $ownStmt->get_result()->fetch_assoc();
    $ownStmt->close();

    if (!$ownRow) {
        $_SESSION['flash_error'] = 'Selected class time table entry does not exist.';
        redirect_to('class_timetable.php');
    }

    $validationError = '';
    $dayOfWeek = null;
    $startTime = null;
    $endTime = null;
    $room = null;
    if ($dayOfWeekRaw !== '' && !array_key_exists($dayOfWeekRaw, DAY_OF_WEEK_LABELS)) {
        $validationError = 'Please select a valid day.';
    } elseif (($startTimeRaw !== '') !== ($endTimeRaw !== '')) {
        $validationError = 'Please provide both a start time and an end time, or leave both blank.';
    } elseif ($startTimeRaw !== '' && $endTimeRaw !== '' && $startTimeRaw >= $endTimeRaw) {
        $validationError = 'Class end time must be after the start time.';
    } else {
        $dayOfWeek = $dayOfWeekRaw !== '' ? $dayOfWeekRaw : null;
        $startTime = $startTimeRaw !== '' ? $startTimeRaw : null;
        $endTime = $endTimeRaw !== '' ? $endTimeRaw : null;
        $room = $roomRaw !== '' ? mb_substr($roomRaw, 0, 50) : null;
    }

    if ($validationError !== '') {
        $_SESSION['flash_error'] = $validationError;
    } else {
        $updStmt = $conn->prepare('UPDATE course_offerings SET day_of_week = ?, start_time = ?, end_time = ?, room = ? WHERE id = ?');
        $updStmt->bind_param('ssssi', $dayOfWeek, $startTime, $endTime, $room, $offeringId);
        $updStmt->execute();
        $updStmt->close();
        $_SESSION['flash_success'] = 'Class Time Table updated.';
    }
    redirect_to('class_timetable.php');
}

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

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
// Filters — Faculty (locked to own faculty for Dean), Department
// (cascaded client-side), Semester (scoped to the selected faculty),
// Shift.
// ---------------------------------------------------------------------
$faculties = $role === 'dean'
    ? array_filter($conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC), static fn ($f) => (int) $f['id'] === $deanFacultyId)
    : $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$filterFacultyId = $role === 'dean' ? $deanFacultyId : (int) ($_GET['faculty_id'] ?? 0);

$departments = $conn->query('SELECT id, name, faculty_id FROM departments ORDER BY faculty_id, name')->fetch_all(MYSQLI_ASSOC);
$departmentsByFacultyId = [];
foreach ($departments as $d) {
    $departmentsByFacultyId[(int) $d['faculty_id']][] = ['id' => (int) $d['id'], 'name' => $d['name']];
}
$filterDepartmentId = (int) ($_GET['department_id'] ?? 0);

$semesters = $conn->query('SELECT id, name, faculty_id, status FROM semesters ORDER BY faculty_id, status = \'current\' DESC, name')->fetch_all(MYSQLI_ASSOC);
$semestersByFacultyId = [];
foreach ($semesters as $s) {
    $semestersByFacultyId[(int) $s['faculty_id']][] = ['id' => (int) $s['id'], 'name' => $s['name'], 'status' => $s['status']];
}
$filterSemesterId = (int) ($_GET['semester_id'] ?? 0);

$filterShift = (string) ($_GET['shift'] ?? '');

// ---------------------------------------------------------------------
// Build the query — always scoped to Dean's own faculty when applicable,
// regardless of what a filter parameter claims.
// ---------------------------------------------------------------------
$conditions = ['co.day_of_week IS NOT NULL', 'co.start_time IS NOT NULL', 'co.end_time IS NOT NULL'];
$params = [];
$types = '';

if ($role === 'dean') {
    $conditions[] = 'se.faculty_id = ?';
    $params[] = $deanFacultyId;
    $types .= 'i';
} elseif ($filterFacultyId > 0) {
    $conditions[] = 'se.faculty_id = ?';
    $params[] = $filterFacultyId;
    $types .= 'i';
}

if ($filterDepartmentId > 0) {
    $conditions[] = '(co.roster_department_id = ? OR (co.roster_department_id IS NULL AND c.department_id = ?))';
    $params[] = $filterDepartmentId;
    $params[] = $filterDepartmentId;
    $types .= 'ii';
}

if ($filterSemesterId > 0) {
    $conditions[] = 'co.semester_id = ?';
    $params[] = $filterSemesterId;
    $types .= 'i';
} else {
    // Default view: only currently-active semesters, so the grid isn't
    // cluttered with every historical offering ever scheduled.
    $conditions[] = "se.status = 'current'";
}

if ($filterShift !== '' && array_key_exists($filterShift, OFFERING_SHIFT_LABELS)) {
    $conditions[] = "co.shift IN (?, 'any')";
    $params[] = $filterShift;
    $types .= 's';
}

$whereSql = implode(' AND ', $conditions);

$sql = "SELECT co.id AS offering_id, c.code, c.name AS course_name, co.day_of_week, co.start_time, co.end_time, co.room, co.shift,
               se.name AS semester_name, f.name AS faculty_name, COALESCE(rd.name, d.name) AS department_name,
               l.full_name AS lecturer_name
        FROM course_offerings co
        JOIN courses c ON c.id = co.course_id
        JOIN departments d ON d.id = c.department_id
        JOIN semesters se ON se.id = co.semester_id
        JOIN faculties f ON f.id = se.faculty_id
        LEFT JOIN departments rd ON rd.id = co.roster_department_id
        LEFT JOIN lecturers l ON l.id = co.lecturer_id
        WHERE {$whereSql}
        ORDER BY co.start_time";
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$scheduledOfferings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$timetableGrid = build_class_timetable_grid($scheduledOfferings);

$universityName = $settings['university_name'] ?? 'ADMAS University';
$campusLine = $settings['campus'] ?? 'Garoowe Campus';
$logoRelativePath = get_university_logo_relative_path($settings);

// Header Faculty/Semester line — reflects whichever filter is actually
// selected; falls back to a generic label when the view spans more than
// one faculty/semester (the normal case for Rector/Head Academic with no
// filter chosen).
$printFacultyLabel = 'All Faculties';
if ($role === 'dean') {
    $printFacultyLabel = $deanFacultyName;
} elseif ($filterFacultyId > 0) {
    foreach ($faculties as $f) {
        if ((int) $f['id'] === $filterFacultyId) {
            $printFacultyLabel = $f['name'];
            break;
        }
    }
}

$printSemesterLabel = 'Current Semester(s)';
if ($filterSemesterId > 0) {
    foreach ($semesters as $s) {
        if ((int) $s['id'] === $filterSemesterId) {
            $printSemesterLabel = $s['name'];
            break;
        }
    }
}

// Reference table only shows the Sat-Thu teaching week (no Friday column).
$printDayOrder = array_values(array_diff(DAY_OF_WEEK_DISPLAY_ORDER, ['friday']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Time Table — ADMAS Attendance System</title>
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
                <?php if ($role === 'dean'): ?>
                    Access scope: <?= htmlspecialchars($deanFacultyName) ?> Faculty only — view only
                <?php else: ?>
                    Access scope: Full system — view only
                <?php endif; ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Class Time Table</h4>
                    <p class="text-muted mb-0">
                        Weekly Day/Time schedule across every course offering with a slot set.
                        <?= $canEditSchedule ? 'Click "Edit" on any entry below to fix its Day/Time/Room right here.' : 'Edit a course\'s own schedule from "Manage Offerings" or "Assign Courses".' ?>
                    </p>
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

            <div class="admas-card p-3 mb-3">
                <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/class_timetable.php" class="row g-2 align-items-end" id="timetableFilterForm">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label small mb-1">Faculty</label>
                        <select class="form-select form-select-sm" name="faculty_id" id="timetableFacultySelect" <?= $role === 'dean' ? 'disabled' : '' ?> onchange="admasUpdateTimetableDepartments(); admasUpdateTimetableSemesters();">
                            <?php if ($role !== 'dean'): ?><option value="0">All Faculties</option><?php endif; ?>
                            <?php foreach ($faculties as $f): ?>
                                <option value="<?= (int) $f['id'] ?>" <?= $filterFacultyId === (int) $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($role === 'dean'): ?><input type="hidden" name="faculty_id" value="<?= (int) $deanFacultyId ?>"><?php endif; ?>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label small mb-1">Department</label>
                        <select class="form-select form-select-sm" name="department_id" id="timetableDepartmentSelect">
                            <option value="0">All Departments</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label small mb-1">Semester</label>
                        <select class="form-select form-select-sm" name="semester_id" id="timetableSemesterSelect">
                            <option value="0">Current semester(s)</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <label class="form-label small mb-1">Shift</label>
                        <select class="form-select form-select-sm" name="shift">
                            <option value="">All Shifts</option>
                            <option value="morning" <?= $filterShift === 'morning' ? 'selected' : '' ?>>Morning</option>
                            <option value="afternoon" <?= $filterShift === 'afternoon' ? 'selected' : '' ?>>Afternoon</option>
                            <option value="weekend" <?= $filterShift === 'weekend' ? 'selected' : '' ?>>Weekend</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-1">
                        <button type="submit" class="btn btn-sm text-white w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"><i class="bi bi-funnel"></i></button>
                    </div>
                </form>
            </div>

            <div class="admas-card p-4 timetable-print-card">
                <div class="timetable-print-header">
                    <img src="<?= htmlspecialchars(BASE_URL . '/' . $logoRelativePath) ?>" alt="<?= htmlspecialchars($universityName) ?> logo" class="timetable-print-logo">
                    <div class="timetable-print-header-text">
                        <div class="timetable-print-university"><?= htmlspecialchars(mb_strtoupper($universityName)) ?></div>
                        <div class="timetable-print-campus"><?= htmlspecialchars($campusLine) ?></div>
                        <div class="timetable-print-faculty"><?= htmlspecialchars((string) ($currentUser['full_name'] ?? '')) ?> &middot; <?= htmlspecialchars(role_label($role)) ?></div>
                        <div class="timetable-print-faculty">Faculty: <?= htmlspecialchars($printFacultyLabel) ?></div>
                        <div class="timetable-print-year"><?= htmlspecialchars($printSemesterLabel) ?></div>
                    </div>
                </div>

                <div class="timetable-print-meta">
                    <span class="timetable-print-title">Class Time Table</span>
                </div>

                <?php if (empty($timetableGrid['time_slots'])): ?>
                    <p class="text-muted small mb-0 py-3 text-center">No scheduled class times match the selected filters.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <?php render_class_timetable_grid_table($timetableGrid, $printDayOrder, 'course_name', 'timetable-print-table', $canEditSchedule); ?>
                    </div>
                <?php endif; ?>

                <div class="timetable-print-signature">REGISTRAR</div>
            </div>
        </div>
    </div>

    <?php if ($canEditSchedule): ?>
    <div class="modal fade" id="timetableEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/class_timetable.php" class="modal-content">
                <input type="hidden" name="action" value="update_schedule">
                <input type="hidden" name="offering_id" id="editOfferingId">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Class Time Table — <span id="editCourseLabel"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Day</label>
                        <select class="form-select" name="day_of_week" id="editDayOfWeek">
                            <option value="">Not scheduled</option>
                            <?php foreach (DAY_OF_WEEK_LABELS as $dayValue => $dayLabel): ?>
                                <option value="<?= htmlspecialchars($dayValue) ?>"><?= htmlspecialchars($dayLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small mb-1">Start Time</label>
                            <input type="time" class="form-control" name="start_time" id="editStartTime">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1">End Time</label>
                            <input type="time" class="form-control" name="end_time" id="editEndTime">
                        </div>
                    </div>
                    <div>
                        <label class="form-label small mb-1">Room <span class="text-muted small">(optional)</span></label>
                        <input type="text" class="form-control" name="room" id="editRoom" maxlength="50" placeholder="e.g. Room 3">
                    </div>
                    <div class="form-text">Leave Day/Time blank to clear this entry's schedule entirely.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">Save</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const timetableDepartmentsByFaculty = <?= json_encode($departmentsByFacultyId) ?>;
        const timetableSemestersByFaculty = <?= json_encode($semestersByFacultyId) ?>;
        const timetableSelectedDepartment = <?= (int) $filterDepartmentId ?>;
        const timetableSelectedSemester = <?= (int) $filterSemesterId ?>;

        function admasUpdateTimetableDepartments() {
            const facultyId = document.getElementById('timetableFacultySelect').value;
            const select = document.getElementById('timetableDepartmentSelect');
            select.innerHTML = '<option value="0">All Departments</option>';
            (timetableDepartmentsByFaculty[facultyId] || []).forEach((d) => {
                const opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.name;
                if (d.id === timetableSelectedDepartment) opt.selected = true;
                select.appendChild(opt);
            });
        }

        function admasUpdateTimetableSemesters() {
            const facultyId = document.getElementById('timetableFacultySelect').value;
            const select = document.getElementById('timetableSemesterSelect');
            select.innerHTML = '<option value="0">Current semester(s)</option>';
            (timetableSemestersByFaculty[facultyId] || []).forEach((s) => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.name + (s.status === 'current' ? ' (current)' : '');
                if (s.id === timetableSelectedSemester) opt.selected = true;
                select.appendChild(opt);
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            admasUpdateTimetableDepartments();
            admasUpdateTimetableSemesters();
        });

        function admasOpenTimetableEditModal(btn) {
            document.getElementById('editOfferingId').value = btn.dataset.offeringId;
            document.getElementById('editCourseLabel').textContent = btn.dataset.courseLabel;
            document.getElementById('editDayOfWeek').value = btn.dataset.day || '';
            document.getElementById('editStartTime').value = btn.dataset.startTime || '';
            document.getElementById('editEndTime').value = btn.dataset.endTime || '';
            document.getElementById('editRoom').value = btn.dataset.room || '';
            const modalEl = document.getElementById('timetableEditModal');
            if (modalEl) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        }
    </script>
</body>
</html>
