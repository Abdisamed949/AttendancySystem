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

require_role(['lecturer']);

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
$defaultAcademicYearId = (int) ($settings['current_academic_year_id'] ?? 0);

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

$filterAcademicYearId = isset($_GET['academic_year_id']) ? (int) $_GET['academic_year_id'] : $defaultAcademicYearId;
$filterFacultyId = (int) ($_GET['faculty_id'] ?? 0);
$filterDepartmentId = (int) ($_GET['department_id'] ?? 0);
$filterSearch = trim((string) ($_GET['search'] ?? ''));

// ---------------------------------------------------------------------
// Faculty/Department options — only those actually present among this
// lecturer's own courses (never the whole university's list).
// ---------------------------------------------------------------------
$facultyOptStmt = $conn->prepare(
    "SELECT DISTINCT f.id, f.name
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     JOIN faculties f ON f.id = d.faculty_id
     WHERE c.lecturer_id = ?
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
     WHERE c.lecturer_id = ?
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
// Course list — the real security boundary is c.lecturer_id = ? here;
// Faculty/Department/search are extra narrowing only, never a way to see
// another lecturer's courses.
// ---------------------------------------------------------------------
$conditions = ['c.lecturer_id = ?'];
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

$whereSql = implode(' AND ', $conditions);

$coursesStmt = $conn->prepare(
    "SELECT c.id, c.code, c.name, c.credit_hours, d.name AS department_name, f.name AS faculty_name,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) AS student_count
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     JOIN faculties f ON f.id = d.faculty_id
     WHERE {$whereSql}
     ORDER BY f.name, d.name, c.code"
);
$coursesStmt->bind_param($types, ...$params);
$coursesStmt->execute();
$courses = $coursesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$coursesStmt->close();

// ---------------------------------------------------------------------
// Per-course session stats, scoped to the selected Academic Year (courses
// themselves aren't year-scoped in the schema, so this only affects the
// stats shown, not which courses appear in the list above).
// ---------------------------------------------------------------------
$sessionStatsByCourse = [];
if (!empty($courses) && $filterAcademicYearId > 0) {
    $courseIds = array_map(static fn ($c) => (int) $c['id'], $courses);
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $statsSql = "SELECT course_id, COUNT(DISTINCT attendance_date) AS total_sessions, MAX(attendance_date) AS last_session
                 FROM attendance
                 WHERE academic_year_id = ? AND course_id IN ({$placeholders})
                 GROUP BY course_id";
    $statsStmt = $conn->prepare($statsSql);
    $statsTypes = 'i' . str_repeat('i', count($courseIds));
    $statsParams = array_merge([$filterAcademicYearId], $courseIds);
    $statsStmt->bind_param($statsTypes, ...$statsParams);
    $statsStmt->execute();
    $statsRes = $statsStmt->get_result();
    while ($row = $statsRes->fetch_assoc()) {
        $sessionStatsByCourse[(int) $row['course_id']] = $row;
    }
    $statsStmt->close();
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
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">My Courses</h4>
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
                <h6 class="fw-bold mb-3" style="color: #0b1f3a;">
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
                                <th>Faculty</th>
                                <th>Students</th>
                                <th>Sessions</th>
                                <th>Last Session</th>
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
                                    <?php $stats = $sessionStatsByCourse[(int) $c['id']] ?? null; ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?></td>
                                        <td><?= htmlspecialchars($c['department_name']) ?></td>
                                        <td><?= htmlspecialchars($c['faculty_name']) ?></td>
                                        <td><?= number_format((int) $c['student_count']) ?></td>
                                        <td><?= $stats ? number_format((int) $stats['total_sessions']) : '0' ?></td>
                                        <td>
                                            <?php if ($stats && $stats['last_session']): ?>
                                                <?= htmlspecialchars(date('M j, Y', strtotime((string) $stats['last_session']))) ?>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?= htmlspecialchars(BASE_URL) ?>/attendance.php?course_id=<?= (int) $c['id'] ?>" class="btn btn-primary btn-sm" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                                                <i class="bi bi-calendar2-check"></i> Take Attendance
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
