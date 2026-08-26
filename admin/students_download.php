<?php
/**
 * Download Students (Registration Office only) — a dedicated page,
 * separate from Import Students, so the two sidebar items no longer both
 * highlight for the same URL. Search-first flow: pick filters and click
 * "Search" to see the exact matching students in the body first, THEN
 * click "Download These Students" to get the file — the download link
 * carries the exact same filter query string, so what was shown is
 * exactly what gets exported (never a silent mismatch). The actual file
 * streaming still happens on admin/students_import.php's own
 * "download_students" action, which also logs each download via
 * audit_log() — this page's own "Last Downloaded" card reads that same
 * log.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/avatar_helpers.php';

require_role(['registration']);

$conn = db();
$currentUser = current_user();

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

const DOWNLOAD_SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

$existingFaculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$existingDepartments = $conn->query('SELECT id, name, faculty_id FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$existingSemesters = $conn->query('SELECT id, name, faculty_id FROM semesters ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$existingAcademicYears = $conn->query('SELECT id, label FROM academic_years ORDER BY label')->fetch_all(MYSQLI_ASSOC);

$departmentsByFacultyId = [];
foreach ($existingDepartments as $d) {
    $departmentsByFacultyId[(int) $d['faculty_id']][] = ['id' => (int) $d['id'], 'name' => $d['name']];
}
$semestersByFacultyId = [];
foreach ($existingSemesters as $s) {
    $semestersByFacultyId[(int) $s['faculty_id']][] = ['id' => (int) $s['id'], 'name' => $s['name']];
}

// ---------------------------------------------------------------------
// Search — only runs once the form has actually been submitted (the
// hidden "searched" field), so a bare first visit to this page shows the
// filter form alone, not every student unprompted.
// ---------------------------------------------------------------------
$hasSearched = isset($_GET['searched']);
$filterFacultyId = (int) ($_GET['faculty_id'] ?? 0);
$filterDepartmentId = (int) ($_GET['department_id'] ?? 0);
$filterSemesterId = (int) ($_GET['semester_id'] ?? 0);
$filterAcademicYearId = (int) ($_GET['academic_year_id'] ?? 0);
$filterShift = (string) ($_GET['shift'] ?? '');

$matchingStudents = [];
if ($hasSearched) {
    $where = [];
    $params = [];
    $types = '';
    if ($filterFacultyId > 0) {
        $where[] = 's.faculty_id = ?';
        $params[] = $filterFacultyId;
        $types .= 'i';
    }
    if ($filterDepartmentId > 0) {
        $where[] = 's.department_id = ?';
        $params[] = $filterDepartmentId;
        $types .= 'i';
    }
    if ($filterSemesterId > 0) {
        $where[] = 's.semester_id = ?';
        $params[] = $filterSemesterId;
        $types .= 'i';
    }
    if ($filterAcademicYearId > 0) {
        $where[] = 's.academic_year_id = ?';
        $params[] = $filterAcademicYearId;
        $types .= 'i';
    }
    if ($filterShift !== '' && array_key_exists($filterShift, DOWNLOAD_SHIFT_LABELS)) {
        $where[] = 's.shift = ?';
        $params[] = $filterShift;
        $types .= 's';
    }

    $sql = "SELECT s.student_no, s.full_name, u.photo_path, f.name AS faculty_name, d.name AS department_name,
                   sem.name AS semester_name, s.shift, u.status
            FROM students s
            JOIN users u ON u.id = s.user_id
            JOIN faculties f ON f.id = s.faculty_id
            JOIN departments d ON d.id = s.department_id
            LEFT JOIN semesters sem ON sem.id = s.semester_id";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY f.name, d.name, s.full_name';

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $matchingStudents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Same filters, carried over verbatim to the real download action.
$downloadQueryString = http_build_query([
    'action' => 'download_students',
    'faculty_id' => $filterFacultyId,
    'department_id' => $filterDepartmentId,
    'semester_id' => $filterSemesterId,
    'academic_year_id' => $filterAcademicYearId,
    'shift' => $filterShift,
]);

// ---------------------------------------------------------------------
// Last Downloaded — the 5 most recent "download_students" audit_log
// entries, university-wide (every Registration Office user's own
// downloads, not just the one currently logged in).
// ---------------------------------------------------------------------
$recentDownloads = $conn->query(
    "SELECT username, details, created_at FROM audit_log
     WHERE action = 'download_students'
     ORDER BY created_at DESC
     LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Students — ADMAS Attendance System</title>
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
                Access scope: All faculties — enrollment-focused
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Download Students</h4>
                    <p class="text-muted mb-0">Search first to see exactly who matches, then download that same list as a ready-to-re-import backup.</p>
                </div>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-file-earmark-arrow-up"></i> Import Students instead
                </a>
            </div>

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="admas-card p-4 mb-3">
                        <h6 class="fw-bold mb-1" style="color: var(--admas-text);"><i class="bi bi-search"></i> Search Students</h6>
                        <p class="text-muted small mb-3">Narrow down to just the students you need, or leave every filter blank to search everyone — the list appears below once you search.</p>
                        <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students_download.php" class="row g-2 align-items-end">
                            <input type="hidden" name="searched" value="1">
                            <div class="col-sm-6 col-md-4">
                                <label class="form-label small mb-1">Faculty</label>
                                <select class="form-select form-select-sm" name="faculty_id" id="downloadFacultySelect" onchange="admasUpdateDownloadDepartmentOptions(); admasUpdateDownloadSemesterOptions();">
                                    <option value="0">All Faculties</option>
                                    <?php foreach ($existingFaculties as $f): ?>
                                        <option value="<?= (int) $f['id'] ?>" <?= $filterFacultyId === (int) $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label class="form-label small mb-1">Department</label>
                                <select class="form-select form-select-sm" name="department_id" id="downloadDepartmentSelect">
                                    <option value="0">All Departments</option>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label class="form-label small mb-1">Semester</label>
                                <select class="form-select form-select-sm" name="semester_id" id="downloadSemesterSelect">
                                    <option value="0">All Semesters</option>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label class="form-label small mb-1">Shift</label>
                                <select class="form-select form-select-sm" name="shift">
                                    <option value="">All Shifts</option>
                                    <?php foreach (DOWNLOAD_SHIFT_LABELS as $shiftValue => $shiftLabel): ?>
                                        <option value="<?= htmlspecialchars($shiftValue) ?>" <?= $filterShift === $shiftValue ? 'selected' : '' ?>><?= htmlspecialchars($shiftLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label class="form-label small mb-1">Academic Year</label>
                                <select class="form-select form-select-sm" name="academic_year_id">
                                    <option value="0">All Academic Years</option>
                                    <?php foreach ($existingAcademicYears as $ay): ?>
                                        <option value="<?= (int) $ay['id'] ?>" <?= $filterAcademicYearId === (int) $ay['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ay['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <button type="submit" class="btn btn-sm text-white w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </form>
                    </div>

                    <?php if ($hasSearched): ?>
                        <div class="admas-card p-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <h6 class="fw-bold mb-0" style="color: var(--admas-text);">
                                    <i class="bi bi-people"></i> <?= count($matchingStudents) ?> matching student<?= count($matchingStudents) === 1 ? '' : 's' ?>
                                </h6>
                                <?php if (!empty($matchingStudents)): ?>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php?<?= htmlspecialchars($downloadQueryString) ?>" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-download"></i> Download These Students
                                    </a>
                                <?php endif; ?>
                            </div>

                            <?php if (empty($matchingStudents)): ?>
                                <p class="text-muted small mb-0">No students match the selected filters.</p>
                            <?php else: ?>
                                <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                                    <table class="table admas-table table-sm align-middle">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Faculty</th>
                                                <th>Department</th>
                                                <th>Semester</th>
                                                <th>Shift</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($matchingStudents as $stu): ?>
                                                <tr>
                                                    <td><?php render_person_avatar_cell($stu['photo_path'] ?? null, (string) $stu['full_name'], (string) $stu['student_no']); ?></td>
                                                    <td><?= htmlspecialchars((string) $stu['faculty_name']) ?></td>
                                                    <td><?= htmlspecialchars((string) $stu['department_name']) ?></td>
                                                    <td><?= htmlspecialchars((string) ($stu['semester_name'] ?? 'Not set')) ?></td>
                                                    <td><?= htmlspecialchars(DOWNLOAD_SHIFT_LABELS[$stu['shift']] ?? $stu['shift']) ?></td>
                                                    <td><span class="badge-pill <?= $stu['status'] === 'active' ? 'badge-active' : 'badge-absent' ?>"><?= htmlspecialchars(ucfirst((string) $stu['status'])) ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-4">
                    <div class="admas-card p-4 h-100">
                        <h6 class="fw-bold mb-2" style="color: var(--admas-text);"><i class="bi bi-clock-history"></i> Last Downloaded</h6>
                        <?php if (empty($recentDownloads)): ?>
                            <p class="text-muted small mb-0">No one has downloaded students yet.</p>
                        <?php else: ?>
                            <?php foreach ($recentDownloads as $i => $dl): ?>
                                <div class="py-2 <?= $i > 0 ? 'border-top' : '' ?>" style="border-color: var(--admas-border) !important;">
                                    <div class="small fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars((string) $dl['details']) ?></div>
                                    <div class="small text-muted">by <?= htmlspecialchars((string) $dl['username']) ?> &middot; <?= htmlspecialchars(date('M j, Y g:ia', strtotime((string) $dl['created_at']))) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const downloadDepartmentsByFacultyId = <?= json_encode($departmentsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const downloadSemestersByFacultyId = <?= json_encode($semestersByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const downloadSelectedDepartment = <?= (int) $filterDepartmentId ?>;
        const downloadSelectedSemester = <?= (int) $filterSemesterId ?>;

        function admasUpdateDownloadDepartmentOptions() {
            const facultyId = document.getElementById('downloadFacultySelect').value;
            const select = document.getElementById('downloadDepartmentSelect');
            select.innerHTML = '<option value="0">All Departments</option>';
            (downloadDepartmentsByFacultyId[facultyId] || []).forEach((d) => {
                const opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.name;
                if (d.id === downloadSelectedDepartment) opt.selected = true;
                select.appendChild(opt);
            });
        }

        function admasUpdateDownloadSemesterOptions() {
            const facultyId = document.getElementById('downloadFacultySelect').value;
            const select = document.getElementById('downloadSemesterSelect');
            select.innerHTML = '<option value="0">All Semesters</option>';
            (downloadSemestersByFacultyId[facultyId] || []).forEach((s) => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.name;
                if (s.id === downloadSelectedSemester) opt.selected = true;
                select.appendChild(opt);
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            admasUpdateDownloadDepartmentOptions();
            admasUpdateDownloadSemesterOptions();
        });
    </script>
</body>
</html>
