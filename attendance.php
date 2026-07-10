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

require_role(['system_admin', 'dean', 'lecturer']);

$conn = db();
$currentUser = current_user();
$role = current_role();

const SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

const STATUS_LABELS = [
    'present' => 'Present',
    'absent' => 'Absent',
    'late' => 'Late',
    'excused' => 'Excused',
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
        "SELECT c.id, c.code, c.name, c.department_id, c.lecturer_id,
                d.name AS department_name, d.faculty_id, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name, c.code"
    )->fetch_all(MYSQLI_ASSOC);
} elseif ($role === 'dean') {
    $stmt = $conn->prepare(
        "SELECT c.id, c.code, c.name, c.department_id, c.lecturer_id,
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
    $stmt = $conn->prepare(
        "SELECT c.id, c.code, c.name, c.department_id, c.lecturer_id,
                d.name AS department_name, d.faculty_id, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         WHERE c.lecturer_id = ?
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

$academicYears = $conn->query('SELECT id, label, is_current FROM academic_years ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);

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
$filterDate = date('Y-m-d');
$showRoster = false;
$statusOverride = [];

// ---------------------------------------------------------------------
// Handle Save (POST)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_attendance') {
    $filterAcademicYearId = (int) ($_POST['academic_year_id'] ?? 0);
    $filterCourseId = (int) ($_POST['course_id'] ?? 0);
    $filterShift = (string) ($_POST['shift'] ?? '');
    $filterDate = (string) ($_POST['attendance_date'] ?? '');
    $studentIds = array_map('intval', (array) ($_POST['student_ids'] ?? []));
    $statusInput = (array) ($_POST['status'] ?? []);

    $validationError = '';
    $academicYearValid = false;
    foreach ($academicYears as $ay) {
        if ((int) $ay['id'] === $filterAcademicYearId) {
            $academicYearValid = true;
            break;
        }
    }

    $dateValid = (bool) DateTime::createFromFormat('Y-m-d', $filterDate);

    if (!$academicYearValid) {
        $validationError = 'Please select a valid academic year.';
    } elseif (!array_key_exists($filterCourseId, $courseById)) {
        $validationError = 'Please select a valid course.';
    } elseif (!array_key_exists($filterShift, SHIFT_LABELS)) {
        $validationError = 'Please select a valid shift.';
    } elseif (!$dateValid) {
        $validationError = 'Please select a valid date.';
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
            $stmt = $conn->prepare(
                'INSERT INTO attendance (student_id, course_id, academic_year_id, shift, attendance_date, status, recorded_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), recorded_by_user_id = VALUES(recorded_by_user_id)'
            );
            $recordedBy = (int) $currentUser['id'];
            foreach ($rows as $sid => $status) {
                $sidInt = (int) $sid;
                $stmt->bind_param('iiisssi', $sidInt, $filterCourseId, $filterAcademicYearId, $filterShift, $filterDate, $status, $recordedBy);
                $stmt->execute();
            }
            $stmt->close();
            $conn->commit();

            $count = count($rows);
            $_SESSION['flash_success'] = 'Attendance saved for ' . $count . ' student' . ($count === 1 ? '' : 's') . '.';

            redirect_to('attendance.php?' . http_build_query([
                'academic_year_id' => $filterAcademicYearId,
                'course_id' => $filterCourseId,
                'shift' => $filterShift,
                'attendance_date' => $filterDate,
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
            && $dateValid;
    }
}

// ---------------------------------------------------------------------
// Handle Load Students (GET) — also re-entered via the post-save redirect
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filterAcademicYearId = (int) ($_GET['academic_year_id'] ?? $defaultAcademicYearId);
    $filterCourseId = (int) ($_GET['course_id'] ?? 0);
    $filterShift = (string) ($_GET['shift'] ?? '');
    $filterDate = (string) ($_GET['attendance_date'] ?? date('Y-m-d'));

    if (($_GET['load'] ?? '') === '1') {
        $academicYearValid = false;
        foreach ($academicYears as $ay) {
            if ((int) $ay['id'] === $filterAcademicYearId) {
                $academicYearValid = true;
                break;
            }
        }
        $dateValid = (bool) DateTime::createFromFormat('Y-m-d', $filterDate);

        if (!$academicYearValid) {
            $errorMessage = $errorMessage ?: 'Please select a valid academic year.';
        } elseif (!array_key_exists($filterCourseId, $courseById)) {
            $errorMessage = $errorMessage ?: 'Please select a valid course.';
        } elseif (!array_key_exists($filterShift, SHIFT_LABELS)) {
            $errorMessage = $errorMessage ?: 'Please select a valid shift.';
        } elseif (!$dateValid) {
            $errorMessage = $errorMessage ?: 'Please select a valid date.';
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
        $stmt = $conn->prepare('SELECT student_id, status FROM attendance WHERE course_id = ? AND attendance_date = ?');
        $stmt->bind_param('is', $filterCourseId, $filterDate);
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
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Attendance</h4>
                    <p class="text-muted mb-0">Select a course and date, load the roster, then mark each student's status.</p>
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
                                <select class="form-select form-select-sm" id="facultySelect" onchange="rebuildCourseSelect(this.value, '')">
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
                            <label class="form-label small mb-1">Course</label>
                            <select class="form-select form-select-sm" name="course_id" id="courseSelect" required>
                                <option value="">Select course</option>
                                <?php if ($role === 'lecturer'): ?>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>" <?= $filterCourseId === (int) $c['id'] ? 'selected' : '' ?>>
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
                                                <option value="<?= (int) $c['id'] ?>" <?= $filterCourseId === (int) $c['id'] ? 'selected' : '' ?>>
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

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small mb-1">Date</label>
                            <input type="date" class="form-control form-control-sm" name="attendance_date"
                                   value="<?= htmlspecialchars($filterDate) ?>" required>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <button type="submit" name="load" value="1" class="btn btn-primary btn-sm w-100" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                                <i class="bi bi-arrow-repeat"></i> Load Students
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($showRoster): ?>
                <div class="admas-card p-4">
                    <?php if (empty($roster)): ?>
                        <p class="text-muted mb-0">No students match this Academic Year / Course / Shift combination.</p>
                    <?php else: ?>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h6 class="fw-bold mb-0" style="color: #0b1f3a;">
                                Roster — <?= htmlspecialchars($courseById[$filterCourseId]['code'] . ' — ' . $courseById[$filterCourseId]['name']) ?>
                                <span class="text-muted fw-normal">(<?= htmlspecialchars(SHIFT_LABELS[$filterShift]) ?>, <?= htmlspecialchars($filterDate) ?>)</span>
                            </h6>
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
                            <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($filterDate) ?>">

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
                                                <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($r['full_name']) ?></td>
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

                            <button type="submit" class="btn btn-primary" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                                <i class="bi bi-save"></i> Save Attendance
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
